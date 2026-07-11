<?php
// CLI tool: build an encrypted .akupd update package
//
// Usage:
//   php tools/make_update.php <version> <update-key> <file1> [file2 ...]
//   php tools/make_update.php 2.3.0 MySecretKey sales.php assets/app.js
//
// Optional: put upgrade SQL in a file and pass --sql=path/to/upgrade.sql
// Output: update_v<version>.akupd  (upload it in Settings → Software Update)

if (PHP_SAPI !== 'cli') die("Run from command line only.\n");
require_once __DIR__ . '/../includes/updater.php';

array_shift($argv);
$version = array_shift($argv);
$key = array_shift($argv);
$sqlFile = '';
$files = [];
foreach ($argv as $a) {
    if (strpos($a, '--sql=') === 0) $sqlFile = substr($a, 6);
    else $files[] = $a;
}
if (!$version || !$key || !$files) {
    die("Usage: php tools/make_update.php <version> <update-key> <files...> [--sql=upgrade.sql]\n");
}

$root = dirname(__DIR__);
$tmpZip = tempnam(sys_get_temp_dir(), 'mk') . '.zip';
$zip = new ZipArchive();
$zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

$manifest = ['version' => $version, 'notes' => 'Update v' . $version, 'built_at' => date('c')];
foreach ($files as $f) {
    $src = $root . '/' . $f;
    if (!file_exists($src)) die("File not found: $f\n");
    $zip->addFile($src, $f);
}
if ($sqlFile) {
    if (!file_exists($sqlFile)) die("SQL file not found: $sqlFile\n");
    $zip->addFile($sqlFile, 'upgrade.sql');
    $manifest['sql'] = 'upgrade.sql';
}
$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
$zip->close();

$out = "update_v{$version}.akupd";
file_put_contents($out, akupd_build($tmpZip, $key));
unlink($tmpZip);
echo "Built $out (" . count($files) . " files" . ($sqlFile ? ' + SQL' : '') . ")\n";
echo "Upload it at: Settings → Software Update (same Update Key required)\n";
