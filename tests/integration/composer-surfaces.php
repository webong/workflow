<?php

declare(strict_types=1);

// Run inside the PHP 8.3 test container. Each consumer is a real Composer root.
$root = dirname(__DIR__, 2);
$lab = sys_get_temp_dir() . '/workflow-projections-' . bin2hex(random_bytes(6));
mkdir($lab . '/artifacts', 0777, true);

function command(array $args, string $directory): string
{
    global $lab;
    $log = $lab . '/command-stderr.log';
    $offset = is_file($log) ? filesize($log) : 0;
    $pipes = [];
    $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $log, 'a']], $pipes, $directory);
    if (! is_resource($process)) { throw new RuntimeException('Cannot start command'); }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException(implode(' ', $args) . " failed in $directory\n" . $out . file_get_contents($log, offset: $offset));
    }
    return $out;
}
function composer(string $directory, string ...$args): string
{
    return command(['composer', ...$args, '--no-interaction'], $directory);
}
function check(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

// Test the same archives that an external artifact repository can distribute.
$version = '0.1.0-rc.999';
command([PHP_BINARY, $root . '/tools/package-composer.php', $lab . '/artifacts', $version], $root);

$cases = [
    'full' => null,
    'core' => ['core'],
    'core-minimal' => ['core'],
    'laravel' => ['core'],
    'temporal' => ['core'],
    'open-workflow' => ['core'],
    'combined' => ['core'],
];
foreach ($cases as $case => $surfaces) {
    echo "Installing $case consumer...\n";
    $directory = $lab . '/' . $case;
    mkdir($directory);
    $require = ['php' => '^8.3', 'webong/workflow' => $version];
    // A real Laravel host must discover the adapter only when installed.
    if (in_array($case, ['full', 'core', 'laravel', 'combined'], true)) {
        $require['laravel/framework'] = '^12.0';
    }
    foreach (['laravel', 'temporal', 'open-workflow'] as $adapter) {
        if ($case === $adapter || $case === 'combined') {
            $require['webong/workflow-' . $adapter] = $version;
            unset($require['webong/workflow']);
        }
    }
    if ($surfaces !== null) { $require['webong/composer-source-plugin'] = '0.4.0'; }
    $consumer = [
        'name' => 'workflow-test/' . $case,
        'minimum-stability' => 'RC',
        'prefer-stable' => true,
        'require' => $require,
        'repositories' => [['type' => 'artifact', 'url' => $lab . '/artifacts']],
        'config' => ['allow-plugins' => ['webong/composer-source-plugin' => true]],
    ];
    if ($surfaces !== null) {
        $consumer['extra']['source-plugin']['projections']['webong/workflow'] = ['surfaces' => $surfaces];
    }
    file_put_contents($directory . '/composer.json', json_encode($consumer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    composer($directory, 'update', '--no-plugins', '--prefer-dist');
    if ($surfaces !== null) {
        // Activate only after installation so PRE_UPDATE_CMD records write mode.
        composer($directory, 'update', '--prefer-dist');
        check(is_file($directory . '/composer-source.lock'), 'Projection state was not recorded');
        check(is_file($directory . '/vendor/webong/workflow/.source-plugin/projection.json'), 'Projection never ran');
    }
    composer($directory, 'dump-autoload', '--classmap-authoritative');
    echo command([PHP_BINARY, $root . '/tests/integration/projection-consumer.php', $directory, $case], $directory);
    echo command([$directory . '/vendor/bin/workflow', 'help'], $directory);
    file_put_contents($directory . '/definition.json', json_encode(['key' => 'cli-check', 'steps' => [['id' => 'first', 'label' => 'First']]], JSON_THROW_ON_ERROR));
    echo command([$directory . '/vendor/bin/workflow', 'validate', $directory . '/definition.json', '--json'], $directory);

    if ($case === 'core') {
        // Replay a committed root and both lock files into an empty vendor tree.
        $clone = $lab . '/locked-core'; mkdir($clone);
        foreach (['composer.json', 'composer.lock', 'composer-source.lock'] as $file) { copy($directory . '/' . $file, $clone . '/' . $file); }
        composer($clone, 'install', '--no-plugins', '--no-dev', '--prefer-dist');
        composer($clone, 'install', '--no-dev', '--prefer-dist');
        composer($clone, 'dump-autoload', '--classmap-authoritative');
        echo command([PHP_BINARY, $root . '/tests/integration/projection-consumer.php', $clone, 'core'], $clone);
        // A plugin-free installation must stay full, even with selection metadata.
        $disabled = $lab . '/disabled'; mkdir($disabled);
        copy($directory . '/composer.json', $disabled . '/composer.json');
        composer($disabled, 'update', '--no-plugins', '--prefer-dist');
        echo command([PHP_BINARY, $root . '/tests/integration/projection-consumer.php', $disabled, 'full'], $disabled);
    }
}
// The previous monolithic core cannot coexist with split adapter ownership.
$legacy = $lab . '/old-core'; mkdir($legacy);
$legacyRoot = [
    'name' => 'workflow-test/old-core',
    'require' => ['webong/workflow' => '0.1.0-rc.2', 'webong/workflow-laravel' => $version],
    'repositories' => [
        ['type' => 'package', 'package' => ['name' => 'webong/workflow', 'version' => '0.1.0-rc.2', 'type' => 'metapackage']],
        ['type' => 'artifact', 'url' => $lab . '/artifacts'],
    ],
];
file_put_contents($legacy . '/composer.json', json_encode($legacyRoot, JSON_THROW_ON_ERROR));
try {
    composer($legacy, 'update', '--dry-run', '--no-plugins');
    throw new RuntimeException('Split adapter incorrectly accepted the old monolithic core');
} catch (RuntimeException $error) {
    check(str_contains($error->getMessage(), 'requires webong/workflow ^0.1.0-rc.3'), 'Old core was not rejected by the adapter minimum: ' . $error->getMessage());
}
echo "PASS: independent package archives, core projection, minimal core, adapter combinations, lock replay and installed CLI\n";
echo "Consumer artifacts: $lab\n";
