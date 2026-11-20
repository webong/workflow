<?php

declare(strict_types=1);

require $argv[1].'/vendor/autoload.php';

use Composer\InstalledVersions;
use Webong\WorkFlow\Conditions\CompiledCondition;
use Webong\WorkFlow\Conditions\ConditionAuthoringValidator;
use Webong\WorkFlow\Conditions\ConditionCompiler;
use Webong\WorkFlow\Conditions\ConditionResult;
use Webong\WorkFlow\Contracts\ConditionDriver;
use Webong\WorkFlow\Rules\RuleSet;
use Webong\WorkFlow\Services\FlowDefinitionValidator;

$rules = RuleSet::make(['customer.Status' => 'required|in:active']);
if ($rules->rules['customer.Status'] !== ['required', 'in:active']) {
    throw new RuntimeException('Core rules changed case or normalization.');
}
foreach (['webong/workflow-rules', 'webong/workflow-conditions'] as $removed) {
    if (InstalledVersions::isInstalled($removed)) { throw new RuntimeException('Removed package was installed: '.$removed); }
}
if ($argv[2] === 'core-conditions') {
    foreach (['webong/workflow-jq', 'illuminate/validation', 'laravel/framework'] as $dependency) {
        if (InstalledVersions::isInstalled($dependency)) { throw new RuntimeException('Core unexpectedly installed '.$dependency); }
    }
    $driver = new class implements ConditionDriver {
        public function syntax(): string { return 'php'; }
        public function compile(array $document): CompiledCondition { return new CompiledCondition(static fn (mixed $input) => ConditionResult::decided($input === $document['expected'])); }
        public function schema(): array { return ['type' => 'object', 'properties' => ['syntax' => ['enum' => ['php']], 'expected' => []], 'required' => ['syntax', 'expected'], 'additionalProperties' => false]; }
    };
    $condition = (new ConditionCompiler([$driver]))->compile(['not' => ['syntax' => 'php', 'expected' => false]]);
    if (! $condition->matches(true) || $condition->matches(false)) { throw new RuntimeException('Core PHP driver composition failed'); }
    $authoring = new ConditionAuthoringValidator();
    if ($authoring->validate(['rules' => []])[0]->code !== 'condition_driver_unavailable') { throw new RuntimeException('Core silently enabled a rules engine'); }
} else {
    $jq = $argv[2] === 'jq-driver';
    $driver = $jq ? new \Webong\WorkFlow\Jq\JqConditionDriver() : new \Webong\WorkFlow\Illuminate\RuleConditionDriver();
    $compiler = new ConditionCompiler([$driver]);
    $leaf = $jq ? ['syntax' => 'jq', 'expression' => '.amount > 10'] : ['rules' => ['amount' => 'required|numeric|min:11']];
    $condition = $compiler->compile(['all' => [$leaf]]);
    if (! $condition->matches(['amount' => 11]) || $condition->matches(['amount' => 1])) { throw new RuntimeException('Installed predicate driver failed'); }
    if ($jq && InstalledVersions::isInstalled('illuminate/validation')) { throw new RuntimeException('Jq driver pulled in Illuminate'); }
    if (! $jq && InstalledVersions::isInstalled('webong/workflow-jq')) { throw new RuntimeException('Rule driver pulled in jq'); }
    if (! $jq) {
        foreach (['webong/workflow-laravel', 'illuminate/database', 'illuminate/cache', 'spatie/eloquent-sortable'] as $dependency) {
            if (InstalledVersions::isInstalled($dependency)) { throw new RuntimeException('Illuminate driver pulled in app integration: '.$dependency); }
        }
        $manifest = json_decode(file_get_contents($argv[1].'/vendor/webong/workflow-illuminate/composer.json'), true);
        if (isset($manifest['extra']['laravel']['providers'])) { throw new RuntimeException('Component adapter advertises an application provider'); }
    }

    $authoring = new ConditionAuthoringValidator($compiler);
    $bad = $jq ? ['syntax' => 'jq', 'expression' => '.['] : ['rules' => ['id' => ['exists:private_users']]];
    $report = (new FlowDefinitionValidator(conditions: $authoring))->validate(['key' => 'authoring', 'steps' => [['id' => 'send', 'metadata' => ['when' => $bad]]]]);
    $expected = '/steps/0/metadata/when/'.($jq ? 'expression' : 'rules/id/0');
    if ($report->isValid() || $report->errors()[0]->path !== $expected) { throw new RuntimeException('Installed driver authoring lost its diagnostic path'); }
    $driverClass = $jq ? '\\Webong\\WorkFlow\\Jq\\JqConditionDriver' : '\\Webong\\WorkFlow\\Illuminate\\RuleConditionDriver';
    file_put_contents($argv[1].'/drivers.php', '<?php return [new '.$driverClass.'()];');
    $pipes = [];
    $process = proc_open([PHP_BINARY, $argv[1].'/vendor/bin/workflow', 'condition-schema', '--condition-drivers='.$argv[1].'/drivers.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) { throw new RuntimeException('Cannot run installed CLI'); }
    $schema = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0 || json_decode($schema, true) !== $authoring->schema()) { throw new RuntimeException('Installed CLI driver schema failed: '.$error); }
    if (InstalledVersions::isInstalled('laravel/framework')) { throw new RuntimeException('Driver integration required a Laravel application'); }
}
echo "PASS: {$argv[2]} installed with explicit drivers and isolated dependencies\n";
