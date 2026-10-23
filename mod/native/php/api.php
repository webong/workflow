<?php

declare(strict_types=1);

use Webong\WorkFlow\Native\NativeFlow;

/** Experimental JSON interface shared by the executable and C library. */
function workflow_native_dispatch(string $request): string
{
    return (new NativeFlow())->dispatch($request);
}
