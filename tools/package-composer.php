<?php

declare(strict_types=1);

// Build real, independently installable dist archives from the current source.
// This command never uploads or publishes anything.
[$script, $output, $version] = $argv + [null, null, null];
if (! is_string($output) || ! is_string($version) || ! preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $version)) {
    fwrite(STDERR, "Usage: php tools/package-composer.php <output-directory> <version>\n");
    exit(2);
}
$root = dirname(__DIR__);
if (! is_dir($output) && ! mkdir($output, 0777, true)) { throw new RuntimeException('Cannot create output directory'); }
$output = realpath($output);
$process = proc_open(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], [1 => ['pipe', 'w']], $pipes, $root);
if (! is_resource($process)) { throw new RuntimeException('Cannot list package source'); }
$files = array_unique(explode("\0", stream_get_contents($pipes[1])));
fclose($pipes[1]);
if (proc_close($process) !== 0) { throw new RuntimeException('Cannot list package source'); }
sort($files);
foreach (['' => 'workflow', 'pkg/Rules/' => 'workflow-rules', 'pkg/Conditions/' => 'workflow-conditions', 'pkg/Jq/' => 'workflow-jq', 'pkg/Laravel/' => 'workflow-laravel', 'pkg/OpenWorkflow/' => 'workflow-open-workflow', 'pkg/Temporal/' => 'workflow-temporal'] as $prefix => $name) {
    $manifest = json_decode(file_get_contents($root . '/' . $prefix . 'composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['version'] = $version;
    $archive = $output . '/' . $name . '-' . $version . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Cannot create ' . $archive); }
    foreach ($files as $file) {
        if ($file === '' || ! is_file($root . '/' . $file)) { continue; }
        if ($prefix === '' ? str_starts_with($file, 'pkg/') : ! str_starts_with($file, $prefix)) { continue; }
        $relative = substr($file, strlen($prefix));
        // Version only the owning manifest; keep discovered group descriptors verbatim.
        if ($relative === 'composer.json') { continue; }
        $entry = $name . '/' . $relative;
        $zip->addFile($root . '/' . $file, $entry);
        $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, fileperms($root . '/' . $file) << 16);
        $zip->setMtimeName($entry, 315532800);
    }
    $entry = $name . '/composer.json';
    $zip->addFromString($entry, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $zip->setMtimeName($entry, 315532800);
    $zip->close();
    echo $archive . "\n";
}
