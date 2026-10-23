<?php

declare(strict_types=1);

function main(): void
{
    $request = file_get_contents('php://stdin', length: 1048577);
    echo workflow_native_dispatch(is_string($request) ? $request : '').PHP_EOL;
}
