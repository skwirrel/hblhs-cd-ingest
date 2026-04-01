<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/response.php';

requireMethod('GET');

$config    = loadConfig();
$outputDir = $config['output_dir'];

// Walk up the configured output path until we find an existing directory.
// This ensures we always report the correct partition even when the output
// directory itself hasn't been created yet (e.g. USB drive not yet set up).
$checkPath = $outputDir;
while ($checkPath !== '/' && $checkPath !== '' && !is_dir($checkPath)) {
    $checkPath = dirname($checkPath);
}
if (!is_dir($checkPath)) {
    jsonError('DISK_ERROR', 'Could not resolve a valid path for output directory: ' . $outputDir, 500);
    exit;
}

// Use df -P (POSIX output) to get the same Use% the OS reports.
// PHP's disk_free_space() returns user-available space which excludes
// reserved blocks, while disk_total_space() includes them — so a pure
// PHP formula gives a different result from df. df -P avoids that.
$dfOutput = shell_exec('df -P ' . escapeshellarg($checkPath) . ' 2>/dev/null');
if (!$dfOutput) {
    jsonError('DISK_ERROR', 'Could not run df on path: ' . $checkPath, 500);
    exit;
}

$lines = preg_split('/\n/', trim($dfOutput));
if (!isset($lines[1]) || !preg_match('/(\d+)%/', $lines[1], $m)) {
    jsonError('DISK_ERROR', 'Could not parse df output.', 500);
    exit;
}

$usedPct = (int) $m[1];
$free    = disk_free_space($checkPath);
$total   = disk_total_space($checkPath);

jsonOk([
    'used_pct' => $usedPct,
    'free_gb'  => $free  !== false ? round($free  / 1073741824, 1) : null,
    'total_gb' => $total !== false ? round($total / 1073741824, 1) : null,
]);
