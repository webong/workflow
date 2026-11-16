<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Psr\Http\Client\ClientInterface;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;

/**
 * Resolves an OWS `call: openapi` task against its OpenAPI document:
 * fetches the document, finds the operation registered under `operationId`,
 * and produces the method/uri/query for the request, substituting path
 * parameters from the task's `with.parameters` (simple `{placeholder}`
 * interpolation from the parameters map, which may contain runtime
 * expressions already evaluated by the executor).
 */
final class OwsOpenApiOperation
{
    /**
     * @param array<string, mixed> $with The call task's `with` map (document, operationId, parameters)
     * @param array<string, mixed> $taskInput
     * @return array{method: string, uri: string, query: array<string, mixed>}
     * @throws OwsFaultException when the document or operation cannot be resolved
     */
    public static function resolve(
        array $with,
        mixed $taskInput,
        ClientInterface $httpClient,
        \Psr\Http\Message\RequestFactoryInterface $messages,
        ExpressionEvaluator $evaluator,
        string $taskId,
    ): array {
        $document = $with['document'] ?? null;
        $documentUri = is_array($document) ? ($document['endpoint'] ?? null) : ($document ?? null);
        $documentUri = is_array($documentUri) ? ($documentUri['uri'] ?? null) : $documentUri;

        if (! is_string($documentUri) || $documentUri === '') {
            throw new \RuntimeException("OWS openapi call '{$taskId}' requires a document endpoint.");
        }
        $operationId = is_string($with['operationId'] ?? null) ? $with['operationId'] : '';
        if ($operationId === '') {
            throw new \RuntimeException("OWS openapi call '{$taskId}' requires an operationId.");
        }

        $spec = self::fetchDocument($documentUri, $httpClient, $messages, $taskId);
        $operation = self::findOperation($spec, $operationId, $taskId);
        if ($operation === null) {
            throw new OwsFaultException(
                errorType: 'https://serverlessworkflow.io/errors/types/communication',
                errorStatus: 0,
                errorTitle: "Operation '{$operationId}' not found in the OpenAPI document",
                message: "Operation '{$operationId}' not found",
            );
        }

        $basePath = self::basePath($spec);
        $rawPath = is_string($operation['path'] ?? null) ? $operation['path'] : '';
        $method = is_string($operation['method'] ?? null) ? strtoupper($operation['method']) : 'GET';
        $parameters = is_array($with['parameters'] ?? null) ? $with['parameters'] : [];

        // Path parameters: `{name}` placeholders substituted from the
        // parameters map (values may be evaluated runtime expressions).
        $resolved = [];
        foreach ($parameters as $name => $value) {
            $resolved[(string) $name] = is_string($value) && preg_match('/^\$\{(.*)\}$/s', trim($value)) === 1
                ? OwsDataTaskPipeline::evaluate(trim(trim($value), '${} '), $taskInput, ['input' => $taskInput], $evaluator, $taskId)
                : $value;
        }

        $path = preg_replace_callback(
            '/\{([^}]+)\}/',
            function (array $m) use ($resolved): string {
                $value = $resolved[$m[1]] ?? null;
                if (is_array($value)) {
                    $value = json_encode($value);
                }

                return urlencode((string) $value);
            },
            $rawPath,
        ) ?? $rawPath;

        // Remaining (non-path) parameters become the query string.
        $query = [];
        foreach ($resolved as $name => $value) {
            if (! str_contains($rawPath, "{{$name}}")) {
                $query[$name] = $value;
            }
        }

        return [
            'method' => $method,
            'uri' => rtrim($basePath, '/') . $path,
            'query' => $query,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetchDocument(string $uri, ClientInterface $httpClient, \Psr\Http\Message\RequestFactoryInterface $messages, string $taskId): array
    {
        try {
            $response = $httpClient->sendRequest($messages->createRequest('GET', $uri));
        } catch (ClientExceptionInterface $e) {
            throw new OwsFaultException(
                errorType: 'https://serverlessworkflow.io/errors/types/communication',
                errorStatus: 0,
                errorTitle: "Could not fetch the OpenAPI document: {$e->getMessage()}",
                message: $e->getMessage(),
            );
        }

        if ($response->getStatusCode() !== 200) {
            throw new OwsFaultException(
                errorType: 'https://serverlessworkflow.io/errors/types/communication',
                errorStatus: $response->getStatusCode(),
                errorTitle: 'Could not fetch the OpenAPI document',
                message: "HTTP {$response->getStatusCode()} for {$uri}",
            );
        }

        try {
            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("OWS openapi call '{$taskId}': document is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Find the path item and HTTP method registered under the operationId.
     *
     * @param array<string, mixed> $spec
     * @return array{path: string, method: string}|null
     */
    private static function findOperation(array $spec, string $operationId, string $taskId): ?array
    {
        $paths = is_array($spec['paths'] ?? null) ? $spec['paths'] : [];
        $httpMethods = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

        foreach ($paths as $path => $pathItem) {
            if (! is_array($pathItem)) {
                continue;
            }
            foreach ($httpMethods as $method) {
                $operation = $pathItem[$method] ?? null;
                if (is_array($operation) && ($operation['operationId'] ?? null) === $operationId) {
                    return ['path' => (string) $path, 'method' => $method];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function basePath(array $spec): string
    {
        // OpenAPI 3: servers[0].url; Swagger 2: basePath + host.
        $servers = is_array($spec['servers'] ?? null) ? $spec['servers'] : [];
        foreach ($servers as $server) {
            if (is_array($server) && is_string($server['url'] ?? null)) {
                return $server['url'];
            }
        }

        return '';
    }
}