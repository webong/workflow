<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;
use Throwable;
use Webong\WorkFlow\Contracts\FlowDefinitionDriver;
use Webong\WorkFlow\ValueObjects\FlowDefinitionImportResult;
use Webong\WorkFlow\ValueObjects\FlowDiagnostic;

/** Explicit definition-language drivers; has no engine, storage, framework or runtime dependency. */
final readonly class FlowDefinitionImporter
{
    /** @var array<string, FlowDefinitionDriver> */
    private array $drivers;

    /** @param iterable<FlowDefinitionDriver> $drivers */
    public function __construct(iterable $drivers = [])
    {
        $registered = [];
        foreach ($drivers as $driver) {
            if (! $driver instanceof FlowDefinitionDriver) {
                throw new InvalidArgumentException('Definition drivers must implement FlowDefinitionDriver.');
            }
            $format = $driver->format();
            if (preg_match('/^[a-z][a-z0-9_-]*$/D', $format) !== 1 || isset($registered[$format])) {
                throw new InvalidArgumentException('Definition driver formats must be valid and unique.');
            }
            $registered[$format] = $driver;
        }
        $this->drivers = $registered;
    }

    /** @return list<string> */
    public function formats(): array { return array_keys($this->drivers); }

    public function import(string $format, string $document): FlowDefinitionImportResult
    {
        $driver = $this->drivers[$format] ?? null;
        if ($driver === null) {
            return new FlowDefinitionImportResult(null, [new FlowDiagnostic('definition_driver_unavailable', 'No definition driver is registered for this format.')]);
        }
        try {
            $result = $driver->import($document);
        } catch (Throwable) {
            return new FlowDefinitionImportResult(null, [new FlowDiagnostic('definition_driver_failure', 'The definition driver could not import this document.')]);
        }
        return $result;
    }
}
