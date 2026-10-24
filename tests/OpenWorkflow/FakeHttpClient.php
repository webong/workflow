<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Tests\OpenWorkflow;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Deterministic in-memory PSR-18 client: routes requests to canned responses
 * by URI substring (first match wins) and records every request for
 * assertions.
 */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, uri: string, headers: array<string, string>}> */
    public array $captured = [];

    /**
     * @param array<string, ResponseInterface> $routes uri substring -> response
     */
    public function __construct(
        private readonly array $routes,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $entry = [
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'headers' => [],
        ];
        foreach ($request->getHeaders() as $name => $values) {
            $entry['headers'][$name] = implode(', ', $values);
        }
        $this->captured[] = $entry;

        foreach ($this->routes as $needle => $response) {
            if (str_contains($entry['uri'], $needle)) {
                return $response;
            }
        }

        throw new \RuntimeException('FakeHttpClient: no route for ' . $entry['uri']);
    }
}