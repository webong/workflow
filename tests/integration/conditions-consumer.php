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
    if (InstalledVersions::isInstalled('laravel/framework')) { throw new RuntimeException('Conditions require an application framework.'); }
}
echo "PASS: independent {$argv[2]} archive consumer\n";
