<?php

declare(strict_types=1);

// Standalone interpreter oracle: no Laravel, Temporal, or Composer runtime.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Webong\\WorkFlow\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__.'/../../../src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});
require_once __DIR__.'/NativeFlow.php';
require_once __DIR__.'/api.php';
