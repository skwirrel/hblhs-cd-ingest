<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/response.php';

requireMethod('GET');

$config = loadConfig();

// ── Drive status via cdstat.pl ────────────────────────────────
$cdstat  = escapeshellarg($config['cdstat_script']);
$device  = escapeshellarg($config['drive']);
$raw     = shell_exec("perl $cdstat $device 2>/dev/null");
$ioctl   = (int) trim((string) $raw);

$statusMap = [
    0 => 'no_info',
    1 => 'no_disc',
    2 => 'tray_open',
    3 => 'not_ready',
    4 => 'disc_ok',
];
$driveStatus = $statusMap[$ioctl] ?? 'no_info';
debugLog('ioctl result', ['raw' => $ioctl, 'drive_status' => $driveStatus]);

// ── Track count and total duration (only when disc is ready) ──
$trackCount    = 0;
$totalDuration = '';
if ($driveStatus === 'disc_ok') {
    $qOut = shell_exec("cdparanoia -Q -d $device 2>&1");
    preg_match_all('/^\s+(\d+)\.\s+/m', (string) $qOut, $m);
    if (!empty($m[1])) {
        $trackCount = (int) max($m[1]);
    }
    // Parse total duration from: TOTAL   186480 [41:26.30]
    if (preg_match('/^TOTAL\s+\d+\s+\[(\d+:\d+)\.\d+\]/m', (string) $qOut, $tm)) {
        $totalDuration = $tm[1]; // "MM:SS" — drop the frames component
    }
    debugLog('disc query', ['track_count' => $trackCount, 'total_duration' => $totalDuration]);
}

// ── Drive busy? ───────────────────────────────────────────────
$stateFile = $config['state_file'];
$state     = resolveStaleRip($stateFile, readStateFile($stateFile));
$driveBusy = ($state['state'] === 'ripping');
debugLog('drive busy check', ['rip_state' => $state['state'], 'drive_busy' => $driveBusy]);

jsonOk([
    'drive_status'   => $driveStatus,
    'track_count'    => $trackCount,
    'total_duration' => $totalDuration,
    'drive_busy'     => $driveBusy,
]);
