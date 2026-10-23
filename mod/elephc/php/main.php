<?php

declare(strict_types=1);

/*
 * elephc executable entry point.
 *
 *   elephc mod/elephc/php/main.php
 *
 * elephc runs top-level statements for executable output, so this file reuses
 * the same protocol and the same stdin handling the shared library compiles.
 * The generated binary is named after this file and, for a non-empty request,
 * prints exactly one JSON response followed by a newline.
 */

require_once __DIR__.'/../../native/php/NativeFlow.php';
require_once __DIR__.'/../../native/php/api.php';
require_once __DIR__.'/../../native/php/main.php';

main();
