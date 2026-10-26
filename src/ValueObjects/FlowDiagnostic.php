<?php

declare(strict_types=1);

namespace Webong\WorkFlow\ValueObjects;

use InvalidArgumentException;
use Webong\WorkFlow\Enums\FlowDiagnosticSeverity;

/**
 * One structured authoring finding about a flow definition.
 *
 * A diagnostic is data, never an exception: the shared validator collects
 * them so JSON-RPC, the CLI, and generated clients can render the same
 * result. `code` is a stable machine-readable identifier, `path` is an
 * RFC 6901 JSON Pointer into the definition document, and `message` is
 * human-readable. Diagnostics never contain resolved run values — they
 * describe the document the author submitted.
 */
final readonly class FlowDiagnostic
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $code,
        public string $message,
        public FlowDiagnosticSeverity $severity = FlowDiagnosticSeverity::ERROR,
        public string $path = '',
        public ?string $stepId = null,
        public array $details = [],
    ) {
        if ($this->code === '') {
            throw new InvalidArgumentException('A flow diagnostic requires a stable code.');
        }
    }

    /** @param mixed $data */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data)) {
            throw new InvalidArgumentException('A flow diagnostic must be an object.');
        }

        $code = $data['code'] ?? null;
        $message = $data['message'] ?? null;
        $severity = $data['severity'] ?? null;
        $path = $data['path'] ?? '';
        $stepId = $data['step_id'] ?? null;
        $detailsValue = $data['details'] ?? null;
        $details = (array) (is_array($detailsValue) ? $detailsValue : []);
        $parsedSeverity = is_string($severity) ? FlowDiagnosticSeverity::tryFrom($severity) : null;

        return new self(
            code: is_string($code) && $code !== '' ? $code : 'invalid_diagnostic',
            message: is_string($message) ? $message : '',
            severity: $parsedSeverity ?? FlowDiagnosticSeverity::ERROR,
            path: is_string($path) ? $path : '',
            stepId: is_string($stepId) && $stepId !== '' ? $stepId : null,
            details: $details,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity->value,
            'path' => $this->path,
            'step_id' => $this->stepId,
            'details' => $this->details,
        ];
    }
}
