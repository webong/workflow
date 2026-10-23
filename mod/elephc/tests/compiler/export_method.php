<?php

declare(strict_types=1);

// Standalone reproduction: no WorkFlow classes, includes, or Composer dependencies.
final class EchoService
{
    public function echoRequest(string $request): string
    {
        return $request;
    }
}

#[Export]
function echo_request(string $request): string
{
    $service = new EchoService();

    return $service->echoRequest($request);
}
