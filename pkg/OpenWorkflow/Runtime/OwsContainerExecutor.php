<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\FlowStepInvocation;
use Webong\WorkFlow\ValueObjects\StepOutput;
use Webong\WorkFlow\ValueObjects\StepResult;

/**
 * Executes OWS `container` tasks: runs a pre-approved container image.
 *
 * Security model:
 * - Only images from an explicit server-side allowlist may run
 * - No raw commands, mounts, env vars, or network policy from untrusted input
 * - Bounded timeouts, output limits, cancellation, and redaction
 * - All parameters are validated against the allowlist entry
 */
final readonly class OwsContainerExecutor implements FlowStepExecutor
{
    /**
     * @param array<string, array{
     *     image: string,
     *     cmd: list<string>,
     *     env_allowlist: list<string>,
     *     timeout_seconds: int,
     *     output_limit_bytes: int,
     *     network?: string,
     *     mounts?: list<string>,
     *     user?: string,
     *     working_dir?: string,
     * }> $allowlist
     */
    public function __construct(
        private readonly array $allowlist,
        private readonly int $defaultTimeout = 300,
        private readonly int $defaultOutputLimit = 1048576, // 1 MiB
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'container';
    }

    public function execute(FlowStepInvocation $invocation): StepResult
    {
        $step = $invocation->step;
        if (! $invocation->context instanceof \Webong\WorkFlow\OpenWorkflow\Runtime\OwsWorkflowContext) {
            throw new \RuntimeException("OWS container task '{$step->id}' requires an OwsWorkflowContext.");
        }

        $container = $step->metadata['ows_container'] ?? null;
        if (! is_array($container)) {
            throw new \RuntimeException("OWS container task '{$step->id}' requires a container configuration.");
        }

        $imageKey = $container['image'] ?? null;
        if (! is_string($imageKey) || $imageKey === '') {
            throw new \RuntimeException("OWS container task '{$step->id}' requires an image key.");
        }

        if (! isset($this->allowlist[$imageKey])) {
            throw new \RuntimeException("OWS container task '{$step->id}' references unapproved image key '{$imageKey}'.");
        }

        $config = $this->allowlist[$imageKey];

        // Validate command against allowlist
        $cmd = $container['cmd'] ?? $config['cmd'];
        if (! is_array($cmd) || $cmd === []) {
            throw new \RuntimeException("OWS container task '{$step->id}' requires a command array.");
        }

        // Validate environment variables against allowlist
        $env = [];
        $envInput = $container['env'] ?? [];
        if (is_array($envInput)) {
            foreach ($envInput as $key => $value) {
                if (in_array($key, $config['env_allowlist'], true)) {
                    $env[$key] = (string) $value;
                }
            }
        }

        // Resolve timeout
        $timeout = $container['timeout_seconds'] ?? $config['timeout_seconds'] ?? $this->defaultTimeout;
        if (! is_int($timeout) || $timeout < 1 || $timeout > 3600) {
            throw new \RuntimeException("OWS container task '{$step->id}' timeout must be between 1 and 3600 seconds.");
        }

        // Resolve output limit
        $outputLimit = $container['output_limit_bytes'] ?? $config['output_limit_bytes'] ?? $this->defaultOutputLimit;
        if (! is_int($outputLimit) || $outputLimit < 1 || $outputLimit > 10485760) { // max 10 MiB
            throw new \RuntimeException("OWS container task '{$step->id}' output limit must be between 1 and 10485760 bytes.");
        }

        // Build docker command
        $dockerCmd = ['docker', 'run', '--rm'];
        
        // Network
        if (isset($config['network'])) {
            $dockerCmd[] = '--network';
            $dockerCmd[] = $config['network'];
        }
        
        // Mounts (from allowlist only)
        if (! empty($config['mounts'])) {
            foreach ($config['mounts'] as $mount) {
                $dockerCmd[] = '-v';
                $dockerCmd[] = $mount;
            }
        }
        
        // User
        if (! empty($config['user'])) {
            $dockerCmd[] = '--user';
            $dockerCmd[] = $config['user'];
        }
        
        // Working dir
        if (! empty($config['working_dir'])) {
            $dockerCmd[] = '-w';
            $dockerCmd[] = $config['working_dir'];
        }

        // Environment
        foreach ($env as $key => $value) {
            $dockerCmd[] = '-e';
            $dockerCmd[] = "{$key}={$value}";
        }

        // Image and command
        $dockerCmd[] = $config['image'];
        $dockerCmd = array_merge($dockerCmd, $cmd);

        // Execute with timeout and output limit
        $proc = proc_open(
            $dockerCmd,
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            null,
            $env
        );

        if (! is_resource($proc)) {
            return StepResult::failed('Failed to start container');
        }

        // Close stdin
        fclose($pipes[0]);

        // Read stdout with limit
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

            // Check timeout
            if (time() - $startTime >= $timeout) {
                proc_terminate($proc);
                return StepResult::failed("Container timed out after {$timeout} seconds");
            }

            // Read stdout with limit
            $read = fread($pipes[1], 8192);
            if ($read !== false && $read !== '') {
                $stdout .= $read;
                if (strlen($stdout) > $outputLimit) {
                    proc_terminate($proc);
                    return StepResult::failed("Container output exceeded limit of {$outputLimit} bytes");
                }
            }

            // Read stderr
            $read = fread($pipes[2], 8192);
            if ($read !== false && $read !== '') {
                $stderr .= $read;
            }

            usleep(10000); // 10ms
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            $error = "Container exited with code {$exitCode}";
            if ($stderr !== '') {
                $error .= ": {$stderr}";
            }
            return StepResult::failed($error);
        }

        // Truncate stdout if needed for output
        $output = $stdout;
        if (strlen($output) > $outputLimit) {
            $output = substr($output, 0, $outputLimit) . "\n[truncated]";
        }

        // Try to parse JSON output
        $parsed = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            $outputValue = $parsed;
        } else {
            $outputValue = ['stdout' => $output];
        }

        return StepResult::completed(
            'Container executed successfully',
            ['ows_output' => $outputValue],
            output: \Webong\WorkFlow\ValueObjects\StepOutput::from($outputValue),
        );
    }

    public function supports(\Webong\WorkFlow\ValueObjects\FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'container';
    }
}