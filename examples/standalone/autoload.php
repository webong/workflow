<?php

declare(strict_types=1);

// Repository checkout or Composer dependency: never assume a nested vendor/.
foreach ([__DIR__.'/vendor/autoload.php', dirname(__DIR__, 2).'/vendor/autoload.php', dirname(__DIR__, 4).'/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        return;
    }
}
throw new RuntimeException('Cannot locate the Composer autoloader; install dependencies first.');
