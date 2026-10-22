<?php

declare(strict_types=1);

/**
 * Regenerates docs/open-workflow-ctk-matrix.md from the vendored pinned CTK.
 *
 * Usage: php tools/generate-ctk-matrix.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Webong\WorkFlow\OpenWorkflow\Conformance\CtkMatrixGenerator;
use Webong\WorkFlow\OpenWorkflow\Conformance\CtkParser;
use Webong\WorkFlow\OpenWorkflow\Conformance\CtkRunner;

$featuresDir = __DIR__ . '/../ext/OpenWorkflow/Conformance/features';
$matrixPath = __DIR__ . '/../docs/open-workflow-ctk-matrix.md';

$scenarios = (new CtkParser())->parseDirectory($featuresDir);
if ($scenarios === []) {
    fwrite(STDERR, "No CTK scenarios parsed from {$featuresDir}\n");
    exit(1);
}

$results = (new CtkRunner())->runAll($scenarios);
$markdown = (new CtkMatrixGenerator())->generate($results, $featuresDir);

if (file_put_contents($matrixPath, $markdown) === false) {
    fwrite(STDERR, "Could not write {$matrixPath}\n");
    exit(1);
}

$counts = [];
foreach ($results as $r) {
    $counts[$r->status] = ($counts[$r->status] ?? 0) + 1;
}

echo "Wrote {$matrixPath}\n";
echo 'Scenarios: ' . count($results) . PHP_EOL;
foreach ($counts as $status => $n) {
    echo "  {$status}: {$n}" . PHP_EOL;
}