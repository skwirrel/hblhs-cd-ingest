<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/response.php';

requireMethod('POST');

$config    = loadConfig();
$stateFile = $config['state_file'];
$state     = readStateFile($stateFile);

debugLog('force reset requested', ['current_state' => $state['state'], 'pid' => $state['pid'] ?? null]);

// Kill the worker process if we have its PID
$pid = (int) ($state['pid'] ?? 0);
if ($pid > 0 && function_exists('posix_kill')) {
    posix_kill($pid, SIGKILL);
    debugLog('SIGKILL sent', ['pid' => $pid]);
}

// Clean up any temp work directory belonging to this PID
if ($pid > 0) {
    foreach (glob($config['temp_dir'] . '/*_work_' . $pid) ?: [] as $dir) {
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($dir);
        }
    }
}

// Write a clean idle state
writeStateFile($stateFile, [
    'state'               => 'idle',
    'location_id'         => null,
    'progress_pct'        => 0,
    'tracks_done'         => 0,
    'tracks_total'        => 0,
    'current_track'       => 0,
    'current_track_phase' => '',
    'bad_sectors'         => 0,
    'log_tail'            => '',
    'pid'                 => null,
    'cancel_requested'    => false,
]);

debugLog('reset complete');

jsonOk(['state' => 'idle']);
