<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/response.php';

requireMethod('GET');

$config    = loadConfig();
$stateFile = $config['state_file'];
$state     = resolveStaleRip($stateFile, readStateFile($stateFile));

jsonOk([
    'state'               => $state['state'],
    'location_id'         => $state['location_id'],
    'progress_pct'        => (int) $state['progress_pct'],
    'track_progress_pct'  => (int) ($state['track_progress_pct'] ?? 0),
    'tracks_done'         => (int) $state['tracks_done'],
    'tracks_total'        => (int) $state['tracks_total'],
    'current_track'       => (int) $state['current_track'],
    'current_track_phase' => (string) $state['current_track_phase'],
    'bad_sectors'         => (int) $state['bad_sectors'],
    'log_tail'            => (string) $state['log_tail'],
    'failure_message'     => (string) ($state['failure_message'] ?? ''),
]);
