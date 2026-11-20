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

// The optional condition package owns its schema; the core generator stays usable without it.
if (class_exists(\Webong\WorkFlow\Conditions\ConditionSchema::class)) {
    file_put_contents(dirname(__DIR__).'/pkg/Conditions/condition.schema.json', json_encode(
        \Webong\WorkFlow\Conditions\ConditionSchema::document(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    )."\n");
}
