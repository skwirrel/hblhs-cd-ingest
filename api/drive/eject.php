<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/response.php';

requireMethod('POST');

$config = loadConfig();

// Block eject if a rip is in progress
$state = readStateFile($config['state_file']);
if ($state['state'] === 'ripping') {
    jsonError('DRIVE_BUSY', 'A rip is currently in progress. Cancel it before ejecting.', 409);
    exit;
}

$device = escapeshellarg($config['drive']);
exec("eject $device 2>&1", $out, $code);

if ($code !== 0) {
    jsonError('EJECT_FAILED', 'OS-level eject command returned an error: ' . implode(' ', $out), 500);
    exit;
}

jsonOk(['ejected' => true]);
