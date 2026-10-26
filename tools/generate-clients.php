<?php

declare(strict_types=1);

use Webong\WorkFlow\Tools\ClientContractGenerator;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/ClientContractGenerator.php';

/** @var array{enums: array<string, mixed>, types: array<string, mixed>} $manifest */
$manifest = require __DIR__.'/contracts.php';

$paths = ClientContractGenerator::write($manifest, dirname(__DIR__));

foreach ($paths as $language => $path) {
    echo "Generated {$language}: {$path}\n";
}
