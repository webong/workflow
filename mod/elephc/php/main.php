<?php

declare(strict_types=1);

/*
 * elephc executable entry point.
 *
 *   elephc mod/elephc/php/main.php
 *
 * elephc runs top-level statements for executable output, so this file reuses
 * the same protocol and the same stdin handling the shared library compiles.
 * The bootstrap file explicitly requires all core classes so elephc's
 * compile-time autoloading can resolve type-hint-only classes.
 */

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/../../native/php/main.php';

main();
