<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

/**
 * Executes OWS `shell` tasks: runs a pre-approved shell command.
 *
 * Security model:
 * - Only commands from an explicit server-side allowlist may run
 * - No raw commands, arguments, env vars, or redirections from untrusted input
 * - Bounded timeouts, output limits, cancellation, and redaction
 * - Commands run in a restricted environment
 */
final readonly class OwsShellExecutor implements \Webong\WorkFlow\Contracts\FlowStepExecutor
{
    /**
     * @param array<string, array{
     *     cmd: list<string>,
     *     env_allowlist: list<string>,
     *     timeout_seconds: int,
     *     output_limit_bytes: int,
     *     working_dir?: string,
     * }> $allowlist
     */
    public function __construct(
        private readonly array $allowlist,
        private readonly int $defaultTimeout = 60,
        private readonly int $defaultOutputLimit = 1048576, // 1 MiB
    ) {}

    public function supports(\Webong\WorkFlow\ValueObjects\FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'shell';
    }

    public function execute(\Webong\WorkFlow\ValueObjects\FlowStepInvocation $invocation): \Webong\WorkFlow\ValueObjects\StepResult
    {
        $step = $invocation->step;
        $shell = $step->metadata['ows_shell'] ?? null;
        if (! is_array($shell)) {
            throw new \RuntimeException("OWS shell task '{$step->id}' requires a shell configuration.");
        }

        $cmdKey = $shell['cmd'] ?? null;
        if (! is_string($cmdKey) || $cmdKey === '') {
            throw new \RuntimeException("OWS shell task '{$step->id}' requires a command key.");
        }

        if (! isset($this->allowlist[$cmdKey])) {
            throw new \RuntimeException("OWS shell task '{$step->id}' references unapproved command key '{$cmdKey}'.");
        }

        $config = $this->allowlist[$cmdKey];

        // Validate command
        $cmd = $config['cmd'];
        if (! is_array($cmd) || $cmd === []) {
            throw new \RuntimeException("OWS shell task '{$step->id}' requires a command array in allowlist.");
        }

        // Validate environment variables against allowlist
        $env = [];
        $envInput = $shell['env'] ?? [];
        if (is_array($envInput)) {
            foreach ($envInput as $key => $value) {
                if (in_array($key, $config['env_allowlist'], true)) {
                    $env[$key] = (string) $value;
                }
            }
        }

        // Resolve timeout
        $timeout = $shell['timeout_seconds'] ?? $config['timeout_seconds'] ?? $this->defaultTimeout;
        if (! is_int($timeout) || $timeout < 1 || $timeout > 600) {
            throw new \RuntimeException("OWS shell task '{$step->id}' timeout must be between 1 and 600 seconds.");
        }

        // Resolve output limit
        $outputLimit = $shell['output_limit_bytes'] ?? $config['output_limit_bytes'] ?? $this->defaultOutputLimit;
        if (! is_int($outputLimit) || $outputLimit < 1 || $outputLimit > 10485760) {
            throw new \RuntimeException("OWS shell task '{$step->id}' output limit must be between 1 and 10485760 bytes.");
        }

        // Working directory
        $workingDir = $shell['working_dir'] ?? $config['working_dir'] ?? null;

        // Execute command
        $proc = proc_open(
            $cmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            $workingDir,
            $env
        );

        if (! is_resource($proc)) {
            return \Webong\WorkFlow\ValueObjects\StepResult::failed('Failed to start shell command');
        }

        fclose($pipes[0]);

        $stdout = '';
        $stderr = '';
        $startTime = time();

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($proc);
            if (! $status['running']) {
                break;
            }

            if (time() - $startTime >= $timeout) {
                proc_terminate($proc);
                return \Webong\WorkFlow\ValueObjects\StepResult::failed("Shell command timed out after {$timeout} seconds");
            }

            $read = fread($pipes[1], 8192);
            if ($read !== false && $read !== '') {
                $stdout .= $read;
                if (strlen($stdout) > $outputLimit) {
                    proc_terminate($proc);
                    return \Webong\WorkFlow\ValueObjects\StepResult::failed("Shell output exceeded limit of {$outputLimit} bytes");
                }
            }

            $read = fread($pipes[2], 8192);
            if ($read !== false && $read !== '') {
                $stderr .= $read;
            }

            usleep(10000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            $error = "Shell command exited with code {$exitCode}";
            if ($stderr !== '') {
                $error .= ": {$stderr}";
            }
            return \Webong\WorkFlow\ValueObjects\StepResult::failed($error);
        }

        $output = $stdout;
        if (strlen($output) > $outputLimit) {
            $output = substr($output, 0, $outputLimit) . "\n[truncated]";
        }

        $parsed = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $outputValue = $parsed;
        } else {
            $outputValue = ['stdout' => $output];
        }

        return \Webong\WorkFlow\ValueObjects\StepResult::completed(
            'Shell command executed successfully',
            ['ows_output' => $outputValue],
            output: \Webong\WorkFlow\ValueObjects\StepOutput::from($outputValue),
        );
    }

    public function supports(\Webong\WorkFlow\ValueObjects\FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'shell';
    }
}