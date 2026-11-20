<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

use Composer\InstalledVersions;
use Webong\WorkFlow\Conditions\ConditionCompiler;
use Webong\WorkFlow\Rules\RuleSet;

$rules = RuleSet::make(['customer.Status' => 'required|in:active']);
if ($rules->rules['customer.Status'] !== ['required', 'in:active']) {
    throw new RuntimeException('Standalone rules changed field case or normalization.');
}
if ($argv[2] === 'rules') {
    foreach (['webong/workflow', 'webong/workflow-jq', 'illuminate/validation', 'laravel/framework'] as $dependency) {
        if (InstalledVersions::isInstalled($dependency)) { throw new RuntimeException('Rules unexpectedly installed ' . $dependency); }
    }
} else {
    $condition = (new ConditionCompiler())->compile(['all' => [
        ['rules' => ['customer.Status' => 'required|in:active']],
        ['syntax' => 'jq', 'expression' => '.amount > 10'],
    ]]);
    if (! $condition->matches(['customer' => ['Status' => 'active'], 'amount' => 11])
        || $condition->matches(['customer' => ['Status' => 'inactive'], 'amount' => 11])) {
        throw new RuntimeException('Installed conditions did not evaluate correctly.');
    }
    $authoring = new \Webong\WorkFlow\Conditions\ConditionAuthoringValidator();
    $report = (new \Webong\WorkFlow\Services\FlowDefinitionValidator(conditions: $authoring))->validate([
        'key' => 'authoring', 'steps' => [['id' => 'send', 'metadata' => ['when' => ['rules' => ['id' => ['exists:users']]]]]],
    ]);
    if ($report->isValid() || $report->errors()[0]->path !== '/steps/0/metadata/when/rules/id/0') {
        throw new RuntimeException('Installed authoring did not report the exact invalid rule.');
    }
    $pipes = [];
    $process = proc_open([PHP_BINARY, $argv[1].'/vendor/bin/workflow', 'condition-schema'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot run installed CLI'); }
    $schema = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || json_decode($schema, true) !== $authoring->schema()) {
        throw new RuntimeException('Installed CLI did not discover the condition authoring package: '.$error);
    }
    if (InstalledVersions::isInstalled('laravel/framework')) { throw new RuntimeException('Conditions require an application framework.'); }
}
echo "PASS: independent {$argv[2]} archive consumer\n";
