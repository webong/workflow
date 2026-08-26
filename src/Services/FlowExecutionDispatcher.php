<?php

declare(strict_types=1);

namespace Webong\WorkFlow\Services;

use InvalidArgumentException;
use Webong\WorkFlow\Contracts\FlowExecutionDriver;
use Webong\WorkFlow\ValueObjects\FlowExecutionReceipt;
use Webong\WorkFlow\ValueObjects\FlowExecutionRequest;

final class FlowExecutionDispatcher
{
    /** @var array<string, FlowExecutionDriver> */
    private readonly array $drivers;

    /** @param iterable<FlowExecutionDriver> $drivers */
    public function __construct(iterable $drivers)
    {
        $registered = [];

        foreach ($drivers as $driver) {
            if (! $driver instanceof FlowExecutionDriver) {
                throw new InvalidArgumentException('Flow execution drivers must implement FlowExecutionDriver.');
            }

            if ($driver->name() === '' || isset($registered[$driver->name()])) {
                throw new InvalidArgumentException('Flow execution driver names must be unique and non-empty.');
            }

            $registered[$driver->name()] = $driver;
        }

        $this->drivers = $registered;
    }

    public function dispatch(FlowExecutionRequest $request): FlowExecutionReceipt
    {
        $driver = $this->drivers[$request->driver] ?? null;

        if (! $driver instanceof FlowExecutionDriver) {
            throw new InvalidArgumentException("No flow execution driver registered as '{$request->driver}'.");
        }

        $receipt = $driver->dispatch($request->withRecordedDriver());

        if ($receipt->driver !== $driver->name()) {
            throw new InvalidArgumentException(
                "Flow execution driver '{$driver->name()}' returned a '{$receipt->driver}' receipt.",
            );
        }

        return $receipt;
    }
}
