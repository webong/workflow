<?php

declare(strict_types=1);

$consumer = $argv[1];
require $consumer . '/vendor/autoload.php';
foreach (['webong/workflow', 'webong/workflow-open-workflow', 'illuminate/support'] as $unrelated) {
    if (Composer\InstalledVersions::isInstalled($unrelated)) { throw new RuntimeException('Standalone jq pulled ' . $unrelated); }
}
$jq = new Webong\WorkFlow\Jq\Evaluator(new Webong\WorkFlow\Jq\ZestJqEvaluator());
if ($jq->evaluate('.order.id', ['order' => ['id' => 42]]) !== 42
    || $jq->evaluate('.amount >= 100', ['amount' => 125]) !== true
    || $jq->evaluate('type', []) !== 'array'
    || $jq->evaluate('"$workflow.literal"', null) !== '$workflow.literal') {
    throw new RuntimeException('Standalone raw jq contract failed');
}
echo "PASS: standalone jq archive, authoritative autoload, raw JSON and no engine/OWS/Laravel dependency\n";
