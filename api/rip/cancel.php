<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/response.php';

requireMethod('POST');

$config    = loadConfig();
$stateFile = $config['state_file'];
$state     = readStateFile($stateFile);

debugLog('cancel requested', ['current_state' => $state['state'], 'location_id' => $state['location_id'] ?? null]);

if ($state['state'] !== 'ripping') {
    jsonError('NOT_RIPPING', 'No rip is currently in progress; nothing to cancel.', 409);
    exit;
}

// Set cancel flag — the worker polls this and cleans up, then sets state to 'cancelled'
$state['cancel_requested'] = true;
writeStateFile($stateFile, $state);

// Also send SIGTERM to the worker process if posix is available
$pid = (int) ($state['pid'] ?? 0);
if ($pid > 0 && function_exists('posix_kill')) {
    posix_kill($pid, SIGTERM);
    debugLog('SIGTERM sent', ['pid' => $pid]);
}

jsonOk(['state' => 'cancelled']);
