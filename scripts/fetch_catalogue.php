#!/usr/bin/env php
<?php
/**
 * Standalone CLI script — fetch the catalogue CSV from the remote URL.
 * Called by setup.sh and runnable manually at any time.
 *
 * Usage: php scripts/fetch_catalogue.php [/path/to/config.ini]
 */

$configFile = $argv[1] ?? __DIR__ . '/../config.ini';

if (!file_exists($configFile)) {
    fwrite(STDERR, "Error: config.ini not found at $configFile\n");
    exit(1);
}

$ini     = parse_ini_file($configFile, true);
$baseDir = rtrim($ini['general']['base_dir'] ?? dirname($configFile), '/');
$resolve = static fn($p) => ($p[0] === '/') ? $p : $baseDir . '/' . $p;

$sourceUrl = $ini['catalogue']['source_url'] ?? '';
$csvDest   = $resolve($ini['paths']['catalogue_csv'] ?? 'data/catalogue.csv');
$tempDir   = $resolve($ini['paths']['temp_dir']      ?? 'data/temp');

if (empty($sourceUrl)) {
    fwrite(STDERR, "Error: No source_url in [catalogue] section of config.ini.\n");
    exit(1);
}

if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

echo "Fetching catalogue from:\n  $sourceUrl\n";

$tmpFile = $tempDir . '/catalogue_fetch_' . uniqid() . '.csv';
$cmd     = 'wget -q --show-progress -O ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($sourceUrl);

passthru($cmd, $exitCode);

if ($exitCode !== 0 || !file_exists($tmpFile) || filesize($tmpFile) === 0) {
    if (file_exists($tmpFile)) unlink($tmpFile);
    fwrite(STDERR, "Error: wget failed to fetch catalogue (exit code $exitCode).\n");
    exit(1);
}

// Validate
$fh       = fopen($tmpFile, 'r');
$header   = fgetcsv($fh);
$rowCount = 0;
while (fgetcsv($fh) !== false) {
    $rowCount++;
}
fclose($fh);

if ($header === false || $rowCount === 0) {
    unlink($tmpFile);
    fwrite(STDERR, "Error: Downloaded file could not be parsed as valid CSV.\n");
    exit(1);
}

rename($tmpFile, $csvDest);
echo "Saved $rowCount records to:\n  $csvDest\n";
