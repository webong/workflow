<?php

declare(strict_types=1);

namespace Webong\WorkFlow\App;

use Closure;
use InvalidArgumentException;
use JsonException;
use Throwable;
use Traversable;
use Webong\WorkFlow\Contracts\FlowOperationDirectory;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\Services\FlowDefinitionValidator;
use Webong\WorkFlow\Services\FlowOperationCatalog;
use Webong\WorkFlow\ValueObjects\FlowDefinition;
use Webong\WorkFlow\ValueObjects\FlowDiagnostic;
use Webong\WorkFlow\ValueObjects\FlowOperation;
use Webong\WorkFlow\ValueObjects\FlowValidationReport;

/**
 * Authoring CLI: validate, operations, and inspect.
 *
 * This is a presentation layer over the same validation module JSON-RPC
 * exposes as `flow.validate` (FlowDefinitionValidator) and the same catalog
 * `flow.operations` reads (FlowOperationCatalog). It adds no rules of its
 * own, executes nothing, and never reaches a store.
 *
 * Hosts bind their executors with `--executors=file.php`: the file returns an
 * array of FlowStepExecutor instances (optionally implementing
 * FlowOperationProvider or FlowOperationDirectory). Capabilities are
 * supplied with `--capabilities=a,b`.
 */
final readonly class FlowAuthoringCli
{
    /** @var Closure(string): void */
    private Closure $stdout;

    /** @var Closure(string): void */
    private Closure $stderr;

    public function __construct(?Closure $stdout = null, ?Closure $stderr = null)
    {
        $this->stdout = $stdout ?? static function (string $text): void {
            fwrite(\STDOUT, $text);
        };
        $this->stderr = $stderr ?? static function (string $text): void {
            fwrite(\STDERR, $text);
        };
    }

    /** @param list<string> $arguments argv without the program name */
    public function run(array $arguments): int
    {
        $command = $arguments[0] ?? 'help';
        $options = $this->parse(array_slice($arguments, 1));

        try {
            return match ($command) {
                'help', '--help', '-h' => $this->help(),
                'validate' => $this->validateCommand($options),
                'operations' => $this->operationsCommand($options),
                'inspect' => $this->inspectCommand($options),
                default => $this->fail("Unknown command '{$command}'. Run 'workflow help'."),
            };
        } catch (Throwable $exception) {
            return $this->fail($exception->getMessage());
        }
    }

    /**
     * @param array{positional: list<string>, flags: array<string, string|bool>} $options
     */
    private function validateCommand(array $options): int
    {
        if ($this->flag($options, 'help') === true) {
            return $this->help();
        }

        $file = $options['positional'][0] ?? null;
        if ($file === null) {
            return $this->fail('Usage: workflow validate <definition.json|-> [--executors=file.php] [--capabilities=a,b] [--json]');
        }

        $report = $this->report($this->definition($file), $options);

        if ($this->json($options)) {
            $this->write($this->encode($report->toArray()));
        } else {
            $this->writeDiagnostics($report);
            $this->write(sprintf(
                "%s: %d error(s), %d warning(s).\n",
                $report->isValid() ? 'Valid' : 'Invalid',
                count($report->errors()),
                count($report->warnings()),
            ));
        }

        return $report->isValid() ? 0 : 1;
    }

    /**
     * @param array{positional: list<string>, flags: array<string, string|bool>} $options
     */
    private function operationsCommand(array $options): int
    {
        if ($this->flag($options, 'help') === true) {
            return $this->help();
        }

        $executors = $this->executors($this->flag($options, 'executors'));
        $definitionFile = $this->flag($options, 'definition');
        if (is_string($definitionFile)) {
            $definition = FlowDefinition::fromArray($this->definition($definitionFile));
            $operations = (new FlowOperationCatalog())->describe($definition, $executors);

            if ($this->json($options)) {
                $this->write($this->encode(['operations' => $this->operationsToArray($operations)]));

                return 0;
            }

            if ($operations === []) {
                $this->write("No operations are published for this definition.\n");

                return 0;
            }

            foreach ($operations as $stepId => $operation) {
                $this->write(sprintf("%s\t%s\t%s\n", $stepId, $operation->key, $operation->label));
            }

            return 0;
        }

        $operations = $this->directory($executors);
        if ($operations === null) {
            return $this->fail(
                'No operation directory is bound. Pass --executors=file.php with executors implementing '
                .'FlowOperationDirectory, or pass --definition=file.json to describe a definition.',
            );
        }

        if ($this->json($options)) {
            $this->write($this->encode(['operations' => $this->operationsToArray($operations)]));

            return 0;
        }

        foreach ($operations as $operation) {
            $capabilities = $operation->capabilities === [] ? '' : ' ['.implode(', ', $operation->capabilities).']';
            $this->write(sprintf("%s\t%s%s\n", $operation->key, $operation->label, $capabilities));
        }

        return 0;
    }

    /**
     * @param array{positional: list<string>, flags: array<string, string|bool>} $options
     */
    private function inspectCommand(array $options): int
    {
        if ($this->flag($options, 'help') === true) {
            return $this->help();
        }

        $file = $options['positional'][0] ?? null;
        if ($file === null) {
            return $this->fail('Usage: workflow inspect <definition.json|-> [--executors=file.php] [--capabilities=a,b] [--json]');
        }

        $document = $this->definition($file);
        $executors = $this->executors($this->flag($options, 'executors'));
        $report = $this->report($document, $options);

        try {
            $definition = FlowDefinition::fromArray($document);
        } catch (InvalidArgumentException $exception) {
            $this->writeDiagnostics($report);
            $this->write('The definition could not be parsed: '.$exception->getMessage()."\n");

            return 1;
        }

        $operations = (new FlowOperationCatalog())->describe($definition, $executors);

        if ($this->json($options)) {
            $this->write($this->encode([
                'definition' => $definition->toArray(),
                'operations' => $this->operationsToArray($operations),
                'valid' => $report->isValid(),
                'diagnostics' => array_map(
                    static fn (FlowDiagnostic $diagnostic): array => $diagnostic->toArray(),
                    $report->diagnostics,
                ),
            ]));

            return $report->isValid() ? 0 : 1;
        }

        $this->write(sprintf(
            "Flow %s (version %d, %d step(s))\n",
            $definition->key,
            $definition->version,
            count($definition->steps),
        ));

        foreach ($definition->executionSteps() as $step) {
            $this->write(sprintf(
                "  %s  %s  depends_on: %s\n",
                $step->id,
                $step->label,
                $step->dependsOn === [] ? '-' : implode(', ', $step->dependsOn),
            ));

            foreach ($step->input as $name => $binding) {
                $this->write(sprintf(
                    "      input %s <- %s%s\n",
                    $name,
                    $binding->describe(),
                    $binding->required ? '' : ' (optional)',
                ));
            }

            $operation = $operations[$step->id] ?? null;
            if ($operation !== null) {
                $capabilities = $operation->capabilities === [] ? '' : ' capabilities: '.implode(', ', $operation->capabilities);
                $this->write(sprintf("      operation %s (%s)%s\n", $operation->key, $operation->label, $capabilities));
            }
        }

        $this->writeDiagnostics($report);

        return $report->isValid() ? 0 : 1;
    }

    /**
     * @param array<string, mixed> $document
     * @param array{positional: list<string>, flags: array<string, string|bool>} $options
     */
    private function report(array $document, array $options): FlowValidationReport
    {
        return (new FlowDefinitionValidator(
            $this->executors($this->flag($options, 'executors')),
            $this->grantedCapabilities($this->flag($options, 'capabilities')),
        ))->validate($document);
    }

    private function help(): int
    {
        $this->write(<<<'HELP'
        WorkFlow authoring tools

        Usage:
          workflow validate <definition.json|-> [--executors=file.php] [--capabilities=a,b] [--json]
              Check a canonical flow definition without executing it.
          workflow inspect <definition.json|-> [--executors=file.php] [--capabilities=a,b] [--json]
              Summarize a definition, its bindings, and the operations behind its steps.
          workflow operations [--definition=file.json] [--executors=file.php] [--json]
              List the operations this host publishes.

        Options:
          --executors=file.php  A PHP file returning an array of FlowStepExecutor objects.
          --capabilities=a,b    Capabilities this host grants (enables capability checks).
          --json                Emit one JSON object instead of text.

        Exit codes: 0 valid, 1 invalid or unparseable, 2 usage or input error.

        HELP);

        return 0;
    }

    /**
     * @param array{positional: list<string>, flags: array<string, string|bool>} $options
     */
    private function json(array $options): bool
    {
        return $this->flag($options, 'json') === true;
    }

    /** @param array{positional: list<string>, flags: array<string, string|bool>} $options */
    private function flag(array $options, string $name): string|bool|null
    {
        return $options['flags'][$name] ?? null;
    }

    /**
     * @param list<string> $arguments
     * @return array{positional: list<string>, flags: array<string, string|bool>}
     */
    private function parse(array $arguments): array
    {
        $positional = [];
        $flags = [];

        foreach ($arguments as $argument) {
            if (! str_starts_with($argument, '--')) {
                $positional[] = $argument;
                continue;
            }

            $option = substr($argument, 2);
            $separator = strpos($option, '=');
            if ($separator === false) {
                $flags[$option] = true;
                continue;
            }

            $flags[substr($option, 0, $separator)] = substr($option, $separator + 1);
        }

        return ['positional' => $positional, 'flags' => $flags];
    }

    /** @return array<string, mixed> */
    private function definition(string $file): array
    {
        $json = $file === '-' ? stream_get_contents(\STDIN) : @file_get_contents($file);
        if (! is_string($json) || trim($json) === '') {
            throw new InvalidArgumentException("Cannot read definition '{$file}'.");
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("Definition '{$file}' is not valid JSON: ".$exception->getMessage());
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("Definition '{$file}' must be a JSON object.");
        }

        $document = [];
        foreach ($decoded as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidArgumentException("Definition '{$file}' must be a JSON object.");
            }
            $document[$name] = $value;
        }

        return $document;
    }

    /** @return list<mixed> */
    private function executors(string|bool|null $file): array
    {
        if (! is_string($file) || $file === '') {
            return [];
        }

        if (! is_file($file)) {
            throw new InvalidArgumentException("Cannot read executor file '{$file}'.");
        }

        $loaded = require $file;
        if ($loaded instanceof FlowStepExecutor || $loaded instanceof FlowOperationDirectory) {
            $loaded = [$loaded];
        }
        if ($loaded instanceof Traversable) {
            $loaded = iterator_to_array($loaded, false);
        }
        if (! is_array($loaded)) {
            throw new InvalidArgumentException("Executor file '{$file}' must return an array of executors.");
        }

        $executors = array_values($loaded);
        foreach ($executors as $executor) {
            if (! $executor instanceof FlowStepExecutor && ! $executor instanceof FlowOperationDirectory) {
                throw new InvalidArgumentException("Executor file '{$file}' returned an unsupported value.");
            }
        }

        return $executors;
    }

    /** @return list<string>|null */
    private function grantedCapabilities(string|bool|null $flag): ?array
    {
        if (! is_string($flag) || $flag === '') {
            return null;
        }

        $capabilities = [];
        foreach (explode(',', $flag) as $capability) {
            $capability = trim($capability);
            if ($capability !== '') {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    /**
     * @param list<mixed> $executors
     * @return list<FlowOperation>|null Null when no executor publishes a directory.
     */
    private function directory(array $executors): ?array
    {
        $found = false;
        $operations = [];
        foreach ($executors as $executor) {
            if (! $executor instanceof FlowOperationDirectory) {
                continue;
            }
            $found = true;
            foreach ($executor->operations() as $operation) {
                $operations[$operation->key] = $operation;
            }
        }

        return $found ? array_values($operations) : null;
    }

    /**
     * @param array<string, FlowOperation>|list<FlowOperation> $operations
     * @return array<string, array<string, mixed>>
     */
    private function operationsToArray(array $operations): array
    {
        $encoded = [];
        foreach ($operations as $key => $operation) {
            if (! $operation instanceof FlowOperation) {
                continue;
            }
            $encoded[is_string($key) ? $key : $operation->key] = $operation->toArray();
        }

        return $encoded;
    }

    private function writeDiagnostics(FlowValidationReport $report): void
    {
        foreach ($report->diagnostics as $diagnostic) {
            $this->write(sprintf(
                "%s %s %s: %s\n",
                $diagnostic->severity->value,
                $diagnostic->path === '' ? '-' : $diagnostic->path,
                $diagnostic->code,
                $diagnostic->message,
            ));
        }
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function write(string $text): void
    {
        $write = $this->stdout;
        $write($text);
    }

    private function fail(string $message): int
    {
        $write = $this->stderr;
        $write("Error: {$message}\n");

        return 2;
    }
}
