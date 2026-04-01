#!/usr/bin/env php
<?php
/**
 * Test script: verify LAME progress output parsing works as the worker expects.
 * Usage: php scripts/test_encode_progress.php [path/to/file.wav]
 */

$wavFile = $argv[1] ?? '/data/Media/Audio/Music/Unknown_Artist/Unknown_Album/01 Track_1.wav';
$mp3File = sys_get_temp_dir() . '/test_encode_' . getmypid() . '.mp3';

if (!file_exists($wavFile)) {
    echo "ERROR: WAV file not found: $wavFile\n";
    exit(1);
}

// Load lame_options from config if available, otherwise default
$configFile = __DIR__ . '/../config.ini';
$lameOptions = '--preset voice';
if (file_exists($configFile)) {
    $ini = parse_ini_file($configFile, true, INI_SCANNER_RAW);
    $lameOptions = $ini['encoding']['lame_options'] ?? $lameOptions;
}

$cmd = 'lame ' . $lameOptions
     . ' ' . escapeshellarg($wavFile)
     . ' ' . escapeshellarg($mp3File)
     . ' 2>&1';

echo "Command: $cmd\n";
echo "----------------------------------------\n";

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$proc = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) {
    echo "ERROR: Failed to start LAME process.\n";
    exit(1);
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$output      = '';
$lastPct     = -1;
$tickCount   = 0;
$matchCount  = 0;

while (true) {
    $status = proc_get_status($proc);

    $chunk = fread($pipes[1], 8192);
    if ($chunk !== false && $chunk !== '') {
        $output .= $chunk;
        // Show the raw bytes received (replace \r and \n for readability)
        $visible = str_replace(["\r", "\n"], ['<CR>', '<LF>'], $chunk);
        echo "[chunk] " . $visible . "\n";
    }

    $chunk = fread($pipes[2], 8192);
    if ($chunk !== false && $chunk !== '') {
        $output .= $chunk;
        $visible = str_replace(["\r", "\n"], ['<CR>', '<LF>'], $chunk);
        echo "[chunk] " . $visible . "\n";
    }

    if (!$status['running']) {
        break;
    }

    // Attempt the same regex the worker uses
    if (preg_match_all('/\d+\/\d+\s+\(\s*(\d+)%\)/', $output, $pm)) {
        $matchCount = count($pm[1]);
        $pct = min(99, (int) $pm[1][$matchCount - 1]);
        if ($pct !== $lastPct) {
            echo "[progress] {$pct}%\n";
            $lastPct = $pct;
        }
    }

    $tickCount++;
    usleep(200000); // 200 ms — same as worker
}

$output .= stream_get_contents($pipes[1]);
$output .= stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($proc);

echo "----------------------------------------\n";
echo "Exit code:    $exitCode\n";
echo "Poll ticks:   $tickCount\n";
echo "Regex matches found: $matchCount\n";
echo "Last progress: " . ($lastPct >= 0 ? "{$lastPct}%" : "none") . "\n";
echo "Output length: " . strlen($output) . " bytes\n";

// Show the tail of the raw output for inspection
echo "\n--- Raw output (last 500 bytes, \\r shown as <CR>) ---\n";
$tail = substr($output, -500);
echo str_replace(["\r", "\n"], ['<CR>', "\n"], $tail) . "\n";

if (file_exists($mp3File)) {
    unlink($mp3File);
    echo "\n(Temp MP3 cleaned up)\n";
}
