<?php

declare(strict_types=1);

/*
 * Manticore AOT compilation entry point.
 *
 *   manticore compile mod/manticore/php/entry.php -o workflow-native
 *
 * Reads one JSON request from stdin, prints one JSON response on stdout.
 * The wire contract is identical to the TypePHP and elephc modules: protocol 1,
 * operations start / evaluate / advance / complete / cancel.
 */

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../../native/php/api.php';

$request = file_get_contents('php://stdin', length: 1048577);
echo workflow_native_dispatch(is_string($request) ? $request : '').PHP_EOL;
