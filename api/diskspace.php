<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/response.php';

requireMethod('GET');

$config    = loadConfig();
$outputDir = $config['output_dir'];

// Use the output dir if it exists, otherwise fall back to base dir
$checkPath = is_dir($outputDir) ? $outputDir : $config['base_dir'];

$total = disk_total_space($checkPath);
$free  = disk_free_space($checkPath);

if ($total === false || $total === 0) {
    jsonError('DISK_ERROR', 'Could not read disk space.', 500);
    exit;
}

$usedPct = (int) round(($total - $free) / $total * 100);

jsonOk([
    'used_pct' => $usedPct,
    'free_gb'  => round($free  / 1073741824, 1),
    'total_gb' => round($total / 1073741824, 1),
]);
