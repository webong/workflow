<?php

declare(strict_types=1);

namespace Webong\WorkFlow\OpenWorkflow\Runtime;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Webong\WorkFlow\Contracts\FlowContext;
use Webong\WorkFlow\Contracts\FlowStepExecutor;
use Webong\WorkFlow\OpenWorkflow\Expression\ExpressionEvaluator;
use Webong\WorkFlow\ValueObjects\FlowStepDefinition;
use Webong\WorkFlow\ValueObjects\StepResult;
use Webong\WorkFlow\ValueObjects\StepState;

/**
 * Executes OWS `call: http` tasks over a PSR-18 client.
 *
 * Supports the 1.0.3 HTTP call surface: method, endpoint URI with simple
 * `{placeholder}` interpolation from top-level task input, `with.headers`,
 * `with.query`, `with.body` (JSON), endpoint authentication (basic/bearer,
 * with runtime expressions), and the two output modes — `content` (default:
 * the response body, JSON-decoded when the content type says so) and
 * `response` (request echo + headers + statusCode + content).
 *
 * Task-base `output.as` filters the raw output; the task input is bound as
 * the `$input` runtime-expression argument. Non-2xx responses fault with the
 * OWS communication error (type/status/instance), so try/catch handlers can
 * claim them.
 *
 * The transport client is host-owned (PSR-18): the adapter owns the call
 * semantics. Timeouts, retries, and TLS policy are client configuration.
 */
final class OwsHttpExecutor implements FlowStepExecutor
{
    private const COMMUNICATION_ERROR = 'https://serverlessworkflow.io/dsl/errors/types/communication';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface&StreamFactoryInterface $messages,
        private readonly ExpressionEvaluator $expressionEvaluator = new ExpressionEvaluator(),
    ) {}

    public function supports(FlowStepDefinition $step): bool
    {
        return ($step->metadata['ows_type'] ?? null) === 'call'
            && in_array($step->metadata['ows_call'] ?? null, ['http', 'openapi'], true);
    }

    public function execute(
        FlowStepDefinition $step,
        FlowContext $context,
        StepState $previous,
    ): StepResult {
        if (! $context instanceof OwsWorkflowContext) {
            throw new \RuntimeException("OWS http call '{$step->id}' requires an OwsWorkflowContext.");
        }

        $with = is_array($step->metadata['ows_with'] ?? null) ? $step->metadata['ows_with'] : [];
        $instance = is_string($step->metadata['ows_instance'] ?? null) ? $step->metadata['ows_instance'] : $step->id;

        // Raw input: the most recent executed predecessor's output, or the
        // workflow input (routing-skipped steps are transparent).
        $rawInput = OwsRouting::resolveChainInput($step, $context);
        $taskInput = $rawInput;
        $inputExpression = $step->metadata['ows_input_expression'] ?? null;
        if (is_string($inputExpression) && $inputExpression !== '') {
            $taskInput = $this->evaluate($inputExpression, $rawInput, [], $step->id);
        }

        if (($step->metadata['ows_call'] ?? null) === 'openapi') {
            $resolved = OwsOpenApiOperation::resolve(
                $with,
                $taskInput,
                $this->httpClient,
                $this->messages,
                $this->expressionEvaluator,
                $step->id,
            );
            $with['method'] = $resolved['method'];
            $with['endpoint'] = $resolved['uri'];
            $with['query'] = $resolved['query'];
        }

        [$uri, $headers, $body] = $this->buildRequest($with, $taskInput, $step->id);
        $method = strtoupper(is_string($with['method'] ?? null) ? $with['method'] : 'GET');

        $request = $this->messages->createRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withAddedHeader((string) $name, (string) $value);
        }
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->messages->createStream($body));
        }

        $sentHeaders = [];
        foreach ($request->getHeaders() as $name => $values) {
            $sentHeaders[$name] = implode(', ', $values);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new OwsFaultException(
                errorType: self::COMMUNICATION_ERROR,
                errorStatus: 0,
                errorTitle: 'HTTP transport failure: ' . $e->getMessage(),
                message: $e->getMessage(),
            );
        }

        $statusCode = $response->getStatusCode();
        $responseBody = (string) $response->getBody();
        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new OwsFaultException(
                errorType: self::COMMUNICATION_ERROR,
                errorStatus: $statusCode,
                errorTitle: $response->getReasonPhrase() !== '' ? $response->getReasonPhrase() : "HTTP {$statusCode}",
                message: "HTTP {$statusCode} for {$method} {$uri}",
            );
        }

        $content = str_contains($contentType, 'json') || ($contentType === '' && $this->looksLikeJson($responseBody))
            ? $this->decodeJson($responseBody, $step->id)
            : $responseBody;

        $responseHeaders = [];
        foreach ($response->getHeaders() as $name => $values) {
            $responseHeaders[strtolower($name)] = implode(', ', $values);
        }

        $outputMode = $with['output'] ?? 'content';
        $rawOutput = $outputMode === 'response'
            ? [
                'request' => ['method' => $method, 'uri' => $uri, 'headers' => $sentHeaders],
                'headers' => $responseHeaders,
                'statusCode' => $statusCode,
                'content' => $content,
            ]
            : $content;

        // Task-base output.as filters the raw output; the task input is
        // bound as the $input runtime-expression argument.
        $outputExpression = $step->metadata['ows_output_expression'] ?? null;
        if (is_string($outputExpression) && $outputExpression !== '') {
            $rawOutput = OwsDataTaskPipeline::evaluate(
                $outputExpression,
                $rawOutput,
                ['input' => $taskInput],
                $this->expressionEvaluator,
                $step->id,
            );
        }

        $context->recordStepOutput($step->id, $rawOutput);

        return StepResult::completed("HTTP {$method} {$uri}", ['ows_output' => $rawOutput]);
    }

    /**
     * Build the request target, headers, and JSON body from the `with` map.
     *
     * @param array<string, mixed> $with
     * @return array{0: string, 1: array<string, mixed>, 2: ?string}
     */
    private function buildRequest(array $with, mixed $taskInput, string $taskId): array
    {
        $endpoint = $with['endpoint'] ?? null;
        $uri = is_array($endpoint) ? ($endpoint['uri'] ?? null) : ($endpoint ?? null);
        if (! is_string($uri) || $uri === '') {
            throw new \RuntimeException("OWS http call '{$taskId}' requires an endpoint uri.");
        }

        $uri = $this->interpolatePlaceholders($uri, $taskInput);

        $headers = [];
        foreach (is_array($with['headers'] ?? null) ? $with['headers'] : [] as $name => $value) {
            $headers[(string) $name] = is_string($value)
                ? $this->evaluateOrLiteral($value, $taskInput, $taskId)
                : $value;
        }

        // Endpoint authentication: basic and bearer (evaluated expressions).
        $authentication = is_array($endpoint['authentication'] ?? null) ? $endpoint['authentication'] : [];
        if (isset($authentication['basic']) && is_array($basic = $authentication['basic'])) {
            $username = $this->evaluateOrLiteral($basic['username'] ?? '', $taskInput, $taskId);
            $password = $this->evaluateOrLiteral($basic['password'] ?? '', $taskInput, $taskId);
            $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
        } elseif (isset($authentication['bearer']) && is_array($bearer = $authentication['bearer'])) {
            $token = $this->evaluateOrLiteral($bearer['token'] ?? '', $taskInput, $taskId);
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        // with.query appends (interpolated) query parameters.
        $query = [];
        foreach (is_array($with['query'] ?? null) ? $with['query'] : [] as $name => $value) {
            $query[(string) $name] = is_string($value)
                ? $this->evaluateOrLiteral($value, $taskInput, $taskId)
                : $value;
        }
        if ($query !== []) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . http_build_query($query);
        }

        // with.body: evaluated then JSON-encoded for methods that carry one.
        $body = null;
        if (isset($with['body'])) {
            $bodyValue = is_array($with['body'])
                ? $this->evaluateMap($with['body'], $taskInput, $taskId)
                : $this->evaluateOrLiteral((string) $with['body'], $taskInput, $taskId);
            $body = is_array($bodyValue) ? json_encode($bodyValue) : (string) $bodyValue;
        }

        return [$uri, $headers, $body];
    }

    /**
     * Simple `{placeholder}` interpolation from top-level task input, per the
     * 1.0.3 URI interpolation rule.
     */
    private function interpolatePlaceholders(string $uri, mixed $taskInput): string
    {
        $vars = is_array($taskInput) ? $taskInput : [];

        return preg_replace_callback(
            '/\{([a-zA-Z0-9_-]+)\}/',
            function (array $m) use ($vars): string {
                $value = $vars[$m[1]] ?? null;
                if (is_array($value)) {
                    $value = json_encode($value);
                }

                return urlencode((string) $value);
            },
            $uri,
        ) ?? $uri;
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, mixed>
     */
    private function evaluateMap(array $map, mixed $taskInput, string $taskId): array
    {
        $evaluated = [];
        foreach ($map as $key => $value) {
            $evaluated[(string) $key] = is_string($value)
                ? $this->evaluateOrLiteral($value, $taskInput, $taskId)
                : $this->evaluateMap($value, $taskInput, $taskId);
        }
        return $evaluated;
    }

    private function evaluateOrLiteral(string $value, mixed $taskInput, string $taskId): mixed
    {
        $trimmed = trim($value);
        if (preg_match('/^\$\{(.*)\}$/s', $trimmed, $m) !== 1) {
            return $value; // literal
        }

        return OwsDataTaskPipeline::evaluate(trim($m[1]), $taskInput, ['input' => $taskInput], $this->expressionEvaluator, $taskId);
    }

    private function evaluate(string $expression, mixed $input, array $vars, string $taskId): mixed
    {
        return OwsDataTaskPipeline::evaluate($expression, $input, $vars, $this->expressionEvaluator, $taskId);
    }

    private function looksLikeJson(string $body): bool
    {
        $first = ltrim($body)[0] ?? '';

        return $first === '{' || $first === '[';
    }

    /**
     * @return mixed
     */
    private function decodeJson(string $body, string $taskId): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("OWS http call '{$taskId}': response is not valid JSON: {$e->getMessage()}", 0, $e);
        }
    }

}