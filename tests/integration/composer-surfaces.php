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

// A ZIP of the current worktree's publishable files, not a path symlink and not
// a stale HEAD archive. A synthetic fixture version is the only metadata change.
$manifest = json_decode(file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$manifest['version'] = '999.0.0';
$zip = new ZipArchive();
check($zip->open($lab . '/artifacts/workflow.zip', ZipArchive::CREATE) === true, 'Cannot create archive');
$files = explode("\0", command(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $root));
foreach ($files as $file) {
    if ($file !== '' && is_file($root . '/' . $file) && $file !== 'composer.json') {
        $zip->addFile($root . '/' . $file, 'workflow/' . $file);
        $zip->setExternalAttributesName('workflow/' . $file, ZipArchive::OPSYS_UNIX, fileperms($root . '/' . $file) << 16);
    }
}
$zip->addFromString('workflow/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$zip->close();

$cases = [
    'full' => null,
    'core' => ['core'],
    'laravel' => ['laravel'],
    'temporal' => ['temporal'],
    'open-workflow' => ['open-workflow'],
    'app' => ['app'],
    'combined' => ['laravel', 'temporal', 'open-workflow', 'app'],
];
foreach ($cases as $case => $surfaces) {
    echo "Installing $case consumer...\n";
    $directory = $lab . '/' . $case;
    mkdir($directory);
    $require = ['php' => '^8.3', 'webong/workflow' => '999.0.0'];
    // Even core is installed into a real Laravel host: its advertised provider
    // must remain discoverable without registering a deleted integration.
    if (in_array($case, ['full', 'core', 'laravel', 'combined'], true)) {
        $require['laravel/framework'] = '^12.0';
    }
    if (in_array($case, ['full', 'laravel', 'combined'], true)) {
        $require['spatie/eloquent-sortable'] = '^4.2';
    }
    if ($surfaces !== null) { $require['webong/composer-source-plugin'] = '0.4.0'; }
    $consumer = [
        'name' => 'workflow-test/' . $case,
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
echo "PASS: full install, six projections, lock replay, no-plugins, authoritative autoload and installed CLI\n";
echo "Consumer artifacts: $lab\n";
