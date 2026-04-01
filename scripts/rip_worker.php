#!/usr/bin/env php
<?php
/**
 * Rip worker — runs as a detached background process.
 * Spawned by api/rip/start.php; communicates via rip_state.json.
 *
 * Usage: php rip_worker.php <config.ini> <rip_info.json>
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: rip_worker.php <config.ini> <rip_info.json>\n");
    exit(1);
}

// ── Load config ───────────────────────────────────────────────
$configFile = $argv[1];
if (!file_exists($configFile)) {
    fwrite(STDERR, "Config file not found: $configFile\n");
    exit(1);
}

$ini     = parse_ini_file($configFile, true, INI_SCANNER_RAW);
$baseDir = rtrim($ini['general']['base_dir'] ?? dirname($configFile), '/');
$resolve = static fn(string $p): string => ($p[0] === '/') ? $p : $baseDir . '/' . $p;

$cfg = [
    'drive'               => $ini['device']['drive']                               ?? '/dev/sr0',
    'output_dir'          => $resolve($ini['paths']['output_dir']   ?? 'data/output'),
    'temp_dir'            => $resolve($ini['paths']['temp_dir']     ?? 'data/temp'),
    'log_dir'             => $resolve($ini['paths']['log_dir']      ?? 'data/logs'),
    'state_file'          => $resolve($ini['paths']['state_file']   ?? 'data/rip_state.json'),
    'lame_options'        => $ini['encoding']['lame_options']                      ?? '--preset voice',
    'cdparanoia_options'  => $ini['ripping']['cdparanoia_options']                 ?? '-z 3',
    'max_read_errors'     => (int) ($ini['ripping']['max_read_errors']              ?? 0),
    'debug'               => filter_var($ini['general']['debug'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
];

// ── Load rip info ─────────────────────────────────────────────
$ripInfoFile = $argv[2];
if (!file_exists($ripInfoFile)) {
    fwrite(STDERR, "Rip info file not found: $ripInfoFile\n");
    exit(1);
}

$info = json_decode(file_get_contents($ripInfoFile), true);
unlink($ripInfoFile); // consumed; remove immediately

$locationId  = $info['location_id']  ?? '';
$inCatalogue = (bool) ($info['in_catalogue'] ?? false);
$subject     = $info['subject']      ?? '';
$description = $info['description']  ?? '';
$trackCount  = (int) ($info['track_count']  ?? 0);
$trackSectors = $info['track_sectors'] ?? []; // track_number => sector count
$dirName     = $info['dir_name']     ?? '';
$device      = $cfg['drive'];
$stateFile   = $cfg['state_file'];

if ($trackCount === 0 || $dirName === '') {
    fwrite(STDERR, "Invalid rip info: track_count=$trackCount, dir_name=$dirName\n");
    exit(1);
}

// ── Helpers ───────────────────────────────────────────────────

/**
 * Write a debug log line to debug.log (worker-specific; mirrors the web helper).
 * Uses globals $cfg to avoid threading the config through every call.
 */
function wDebugLog(string $message, array $context = []): void
{
    global $cfg;
    if (empty($cfg['debug'])) {
        return;
    }
    $ts  = gmdate('Y-m-d\TH:i:s\Z');
    $ctx = !empty($context)
        ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : '';
    file_put_contents(
        $cfg['log_dir'] . '/debug.log',
        "[$ts] [rip_worker] $message$ctx\n",
        FILE_APPEND | LOCK_EX
    );
}

function readState(string $path): array
{
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function writeState(string $path, array $state): void
{
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($state));
    rename($tmp, $path);
}

/**
 * Move a directory to a destination, working across filesystems.
 * Tries rename() first (atomic, same-fs only). Falls back to cp -a
 * with file-count validation, then removes the source on success.
 * Returns true on success, throws RuntimeException on failure.
 */
function moveDir(string $src, string $dest): bool
{
    // Try atomic rename first (works only on same filesystem)
    if (@rename($src, $dest)) {
        return true;
    }

    // Cross-filesystem: shell out to cp -a
    $cmd = 'cp -a ' . escapeshellarg($src) . ' ' . escapeshellarg($dest) . ' 2>&1';
    $cpOutput = '';
    exec($cmd, $cpOutputLines, $cpExit);
    $cpOutput = implode("\n", $cpOutputLines);

    if ($cpExit !== 0) {
        throw new RuntimeException("cp -a failed (exit $cpExit): $cpOutput");
    }

    // Validate: compare file counts
    $srcCount  = count(glob($src . '/*') ?: []);
    $destCount = count(glob($dest . '/*') ?: []);
    if ($destCount < $srcCount) {
        throw new RuntimeException(
            "Copy validation failed: source has $srcCount files, destination has $destCount"
        );
    }

    // Remove source
    foreach (glob($src . '/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($src);

    return true;
}

function tailLines(string $existing, string $append, int $max = 25): string
{
    $combined = rtrim($existing) . ($existing !== '' ? "\n" : '') . rtrim($append);
    $lines    = explode("\n", $combined);
    if (count($lines) > $max) {
        $lines = array_slice($lines, -$max);
    }
    return implode("\n", $lines);
}

function discProgress(int $sectorsDone, float $currentFraction, int $currentTrackSectors, int $totalSectors): int
{
    if ($totalSectors === 0) {
        return 0;
    }
    return (int) round(($sectorsDone + $currentFraction * $currentTrackSectors) / $totalSectors * 100);
}

/**
 * Run a command via proc_open, collecting output while polling the state file
 * for a cancel_requested flag every 200 ms.
 *
 * When $watchFile, $expectedBytes, $tracksDone, and $trackCount are supplied,
 * the poll loop also watches the growing output file and updates progress_pct
 * in the state file on every tick (used during cdparanoia ripping).
 *
 * Returns [exitCode, output, cancelRequested].
 */
function runWithCancelCheck(
    string $cmd,
    string $stateFile,
    string $watchFile           = '',
    int    $expectedBytes       = 0,
    int    $sectorsDone         = 0,
    int    $currentTrackSectors = 0,
    int    $totalSectors        = 0,
    int    $maxReadErrors       = 0,
    bool   $parseLameProgress   = false
): array
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return [1, "Failed to start process: $cmd", false];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $output          = '';
    $cancelRequested = false;
    $abortedOnErrors = false;
    $exitCode        = -1;

    while (true) {
        $status = proc_get_status($proc);

        // Collect any available output
        $chunk = fread($pipes[1], 8192);
        if ($chunk !== false && $chunk !== '') {
            $output .= $chunk;
        }
        $chunk = fread($pipes[2], 8192);
        if ($chunk !== false && $chunk !== '') {
            $output .= $chunk;
        }

        if (!$status['running']) {
            // Capture exit code here from proc_get_status() — proc_close() returns
            // -1 once the process has already been reaped by proc_get_status().
            $exitCode = $status['exitcode'];
            break;
        }

        // Poll state file for cancel flag (and optionally update rip progress)
        $state = readState($stateFile);
        if (!empty($state['cancel_requested'])) {
            $cancelRequested = true;
            // Kill the child process tree: the proc_open PID is `sh -c ...`,
            // and its child (cdparanoia/lame) won't die from proc_terminate alone.
            $shPid = proc_get_status($proc)['pid'];
            if ($shPid > 0) {
                // cdparanoia often enters D state (uninterruptible sleep) waiting
                // on CD I/O, so SIGTERM has no effect. Use SIGKILL for children,
                // then kill the shell wrapper.
                exec('pkill -KILL -P ' . (int)$shPid);
                usleep(50000);
                proc_terminate($proc, SIGKILL);
            } else {
                proc_terminate($proc, SIGKILL);
            }
            exec('killall -9 cdparanoia 2>/dev/null');
            // Drain remaining output briefly
            usleep(300000);
            $output .= stream_get_contents($pipes[1]);
            $output .= stream_get_contents($pipes[2]);
            break;
        }

        // Abort if too many SCSI read errors (cdparanoia retries forever on
        // badly damaged sectors). Count errors seen so far in collected output.
        if ($maxReadErrors > 0) {
            $errorCount = preg_match_all('/scsi_read error/', $output);
            if ($errorCount >= $maxReadErrors) {
                $abortedOnErrors = true;
                $shPid = proc_get_status($proc)['pid'];
                if ($shPid > 0) {
                    exec('pkill -KILL -P ' . (int)$shPid);
                    usleep(50000);
                    proc_terminate($proc, SIGKILL);
                } else {
                    proc_terminate($proc, SIGKILL);
                }
                exec('killall -9 cdparanoia 2>/dev/null');
                usleep(300000);
                $output .= stream_get_contents($pipes[1]);
                $output .= stream_get_contents($pipes[2]);
                break;
            }
        }

        // Update progress from growing WAV file size if params were supplied
        if ($watchFile !== '' && $expectedBytes > 0 && $totalSectors > 0) {
            clearstatcache(true, $watchFile);
            $currentBytes = file_exists($watchFile) ? (int) filesize($watchFile) : 0;
            if ($currentBytes > 0) {
                $ripFraction                  = min(0.99, $currentBytes / $expectedBytes);
                $state['progress_pct']        = discProgress(
                    $sectorsDone, $ripFraction, $currentTrackSectors, $totalSectors
                );
                $state['track_progress_pct']  = (int) round($ripFraction * 100);
                writeState($stateFile, $state);
            }
        }

        // Update encode progress by parsing LAME's "NNN/TOTAL ( PCT%)|" output
        if ($parseLameProgress && preg_match_all('/\d+\/\d+\s+\(\s*(\d+)%\)/', $output, $pm)) {
            $pct = min(99, (int) $pm[1][count($pm[1]) - 1]);
            $state['track_progress_pct'] = $pct;
            writeState($stateFile, $state);
        }

        usleep(200000); // 200 ms
    }

    // Drain any remaining output after process ends
    $output .= stream_get_contents($pipes[1]);
    $output .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    proc_close($proc); // resource cleanup only — exit code already captured above

    return [$exitCode, $output, $cancelRequested, $abortedOnErrors];
}

// ── Update state with our PID ─────────────────────────────────
$state      = readState($stateFile);
$state['pid'] = getmypid();
writeState($stateFile, $state);

wDebugLog('worker started', [
    'pid'          => getmypid(),
    'location_id'  => $locationId,
    'in_catalogue' => $inCatalogue,
    'track_count'  => $trackCount,
    'dir_name'     => $dirName,
    'device'       => $device,
    'lame_options' => $cfg['lame_options'],
]);

// ── Initialise working state ──────────────────────────────────
$ripStartedAt = gmdate('c');
$badSectors   = 0;
$logTail      = '';
$fullLog      = '';
$rippedTracks = 0;
$outcome        = 'ok'; // ok | failed | cancelled
$failureMessage = '';
$totalSectors = (int) array_sum($trackSectors); // sum of all track sector counts
$sectorsDone  = 0;                              // sectors fully ripped so far

$tempDir = $cfg['temp_dir'] . '/' . $dirName . '_work_' . getmypid();

if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

$ripCommand    = 'cdparanoia -d ' . $device;
$encodeCommand = 'lame ' . $cfg['lame_options'];

// ── Process tracks ────────────────────────────────────────────
for ($track = 1; $track <= $trackCount; $track++) {
    $pad     = str_pad((string) $track, 2, '0', STR_PAD_LEFT);
    $wavFile = $tempDir . '/track' . $pad . '.wav';
    $mp3File = $tempDir . '/track' . $pad . '.mp3';

    // ─ Update state: about to rip ─────────────────────────────
    $currentTrackSectors = (int) ($trackSectors[$track] ?? 0);
    $state = readState($stateFile);
    $state['current_track']       = $track;
    $state['current_track_phase'] = 'ripping';
    $state['progress_pct']        = discProgress($sectorsDone, 0.0, 0, $totalSectors);
    $state['track_progress_pct']  = 0;
    $state['bad_sectors']         = $badSectors;
    $state['log_tail']            = $logTail;
    writeState($stateFile, $state);

    // ─ Rip track ──────────────────────────────────────────────
    $ripCmd = 'cdparanoia ' . $cfg['cdparanoia_options']
            . ' -d ' . escapeshellarg($device)
            . ' ' . $track
            . ' ' . escapeshellarg($wavFile)
            . ' 2>&1';

    // Expected WAV size: 44-byte header + sectors × 2352 bytes/sector (CD audio)
    $expectedWavBytes = $currentTrackSectors > 0
        ? 44 + ($currentTrackSectors * 2352)
        : 0;

    wDebugLog("track $track: ripping", [
        'cmd'                => $ripCmd,
        'wav'                => $wavFile,
        'expected_wav_bytes' => $expectedWavBytes,
        'track_sectors'      => $currentTrackSectors,
        'sectors_done'       => $sectorsDone,
        'total_sectors'      => $totalSectors,
    ]);

    [$ripExit, $ripOutput, $cancelled, $abortedOnErrors] = runWithCancelCheck(
        $ripCmd, $stateFile, $wavFile, $expectedWavBytes,
        $sectorsDone, $currentTrackSectors, $totalSectors,
        $cfg['max_read_errors']
    );

    $fullLog .= "=== Track $track — rip ===\n" . $ripOutput . "\n";

    // Count bad sectors: cdparanoia prefixes error lines with !!!
    $trackErrors = preg_match_all('/^!!!/m', $ripOutput);
    $badSectors += $trackErrors;

    $logTail = tailLines($logTail, "Track $track rip: exit=$ripExit, errors=$trackErrors\n$ripOutput");

    wDebugLog("track $track: rip done", [
        'exit'         => $ripExit,
        'track_errors' => $trackErrors,
        'bad_sectors'  => $badSectors,
        'cancelled'    => $cancelled,
        'wav_exists'   => file_exists($wavFile),
    ]);

    if ($cancelled) {
        $outcome = 'cancelled';
        break;
    }

    if ($abortedOnErrors) {
        $logTail        = tailLines($logTail, "Track $track aborted — too many read errors\n");
        $fullLog       .= "ABORTED on track $track — exceeded max read errors\n";
        $outcome        = 'failed';
        $failureMessage = "Track $track aborted — too many read errors";
        break;
    }

    // Hard failure: no WAV produced
    if (!file_exists($wavFile) || filesize($wavFile) === 0) {
        $logTail        = tailLines($logTail, "Track $track rip FAILED — no output file\n");
        $fullLog       .= "RIPPING FAILED on track $track — no output file\n";
        $outcome        = 'failed';
        $failureMessage = "Track $track rip failed — no output file produced";
        break;
    }

    // Read errors — disc needs manual review even though the WAV exists.
    // Don't break: continue encoding remaining tracks so the reviewer has
    // as much audio as possible in the failed output directory.
    if ($trackErrors > 0) {
        $outcome        = 'failed';
        $failureMessage = $failureMessage ?: 'Disc has read errors — audio may be imperfect';
        wDebugLog("track $track: read errors — outcome set to failed", [
            'track_errors' => $trackErrors,
            'bad_sectors'  => $badSectors,
        ]);
    }

    // ─ Update state: about to encode ─────────────────────────
    $state = readState($stateFile);
    // Re-check cancel in case it arrived between proc runs
    if (!empty($state['cancel_requested'])) {
        if (file_exists($wavFile)) {
            unlink($wavFile);
        }
        $outcome = 'cancelled';
        break;
    }

    $state['current_track_phase'] = 'encoding';
    $state['progress_pct']        = discProgress($sectorsDone, 1.0, $currentTrackSectors, $totalSectors);
    $state['track_progress_pct']  = 0;
    $state['bad_sectors']         = $badSectors;
    $state['log_tail']            = $logTail;
    writeState($stateFile, $state);

    // ─ Encode to MP3 ──────────────────────────────────────────
    $lameCmd = 'lame ' . $cfg['lame_options']
             . ' ' . escapeshellarg($wavFile)
             . ' ' . escapeshellarg($mp3File)
             . ' 2>&1';

    wDebugLog("track $track: encoding", ['cmd' => $lameCmd]);

    [$lameExit, $lameOutput, $cancelled,] = runWithCancelCheck(
        $lameCmd, $stateFile, '', 0, 0, 0, 0, 0, true
    );

    $fullLog .= "=== Track $track — encode ===\n" . $lameOutput . "\n";
    $logTail  = tailLines($logTail, "Track $track encode: exit=$lameExit");

    // Delete WAV immediately — never hold more than one at a time
    if (file_exists($wavFile)) {
        unlink($wavFile);
    }

    wDebugLog("track $track: encode done", [
        'exit'      => $lameExit,
        'mp3_exists' => file_exists($mp3File),
        'cancelled' => $cancelled,
    ]);

    if ($cancelled) {
        $outcome = 'cancelled';
        break;
    }

    if ($lameExit !== 0 || !file_exists($mp3File)) {
        $logTail        = tailLines($logTail, "Track $track encode FAILED\n");
        $fullLog       .= "ENCODE FAILED on track $track\n";
        $outcome        = 'failed';
        $failureMessage = "Track $track encode failed";
        break;
    }

    // ─ Track complete ─────────────────────────────────────────
    $rippedTracks++;
    $sectorsDone += $currentTrackSectors;

    $state = readState($stateFile);
    $state['tracks_done']         = $rippedTracks;
    $state['current_track_phase'] = '';
    $state['progress_pct']        = discProgress($sectorsDone, 0.0, 0, $totalSectors);
    $state['bad_sectors']         = $badSectors;
    $state['log_tail']            = $logTail;
    writeState($stateFile, $state);
}

$ripCompletedAt = gmdate('c');

wDebugLog('processing loop ended', [
    'outcome'       => $outcome,
    'ripped_tracks' => $rippedTracks,
    'bad_sectors'   => $badSectors,
    'started_at'    => $ripStartedAt,
    'completed_at'  => $ripCompletedAt,
]);

// ── Finalise ──────────────────────────────────────────────────

if ($outcome === 'cancelled') {
    // Clean up temp directory
    foreach (glob($tempDir . '/*') ?: [] as $f) {
        unlink($f);
    }
    if (is_dir($tempDir)) {
        rmdir($tempDir);
    }

    $state                    = readState($stateFile);
    $state['state']           = 'cancelled';
    $state['current_track']   = 0;
    $state['current_track_phase'] = '';
    $state['progress_pct']    = 0;
    $state['cancel_requested'] = false;
    writeState($stateFile, $state);
    wDebugLog('worker finished: cancelled', ['temp_dir_cleaned' => !is_dir($tempDir)]);
    exit(0);
}

// Build meta.json
$meta = [
    'metadata_version' => 1,
    'location_id'      => $locationId,
    'directory_name'   => $dirName,
    'subject'          => $subject,
    'description'      => $description,
    'rip_started_at'   => $ripStartedAt,
    'rip_completed_at' => $ripCompletedAt,
    'status'           => $outcome,
    'track_count'      => ($outcome === 'ok') ? $trackCount : $rippedTracks,
    'bad_sector_count' => $badSectors,
    'rip_command'      => $ripCommand,
    'encode_command'   => $encodeCommand,
    'rip_log'          => $fullLog,
    'in_catalogue'     => $inCatalogue,
];

try {
    if ($outcome === 'ok') {
        // Write meta.json into temp dir, then move the whole dir to output
        file_put_contents($tempDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));

        $outputDir = $cfg['output_dir'] . '/' . $dirName;
        // Remove any previous incomplete attempt at the same path
        if (is_dir($outputDir)) {
            foreach (glob($outputDir . '/*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($outputDir);
        }
        moveDir($tempDir, $outputDir);

        // Update state
        $state = readState($stateFile);
        $state['state']               = 'complete';
        $state['tracks_done']         = $trackCount;
        $state['progress_pct']        = 100;
        $state['current_track']       = 0;
        $state['current_track_phase'] = '';
        $state['log_tail']            = $logTail;
        $state['bad_sectors']         = $badSectors;
        writeState($stateFile, $state);

        // Eject the disc
        shell_exec('eject ' . escapeshellarg($device) . ' 2>/dev/null');
        wDebugLog('worker finished: complete', ['output_dir' => $outputDir, 'bad_sectors' => $badSectors]);

    } else {
        // Failed — write meta.json and move to a dated failed directory
        $meta['directory_name'] = $dirName . '_failed_' . gmdate('Ymd\THis');
        $meta['failure_message'] = $failureMessage;
        file_put_contents($tempDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));

        $failedDir = $cfg['output_dir'] . '/' . $meta['directory_name'];
        moveDir($tempDir, $failedDir);

        $state = readState($stateFile);
        $state['state']               = 'failed';
        $state['failure_message']     = $failureMessage;
        $state['current_track']       = 0;
        $state['current_track_phase'] = '';
        $state['log_tail']            = $logTail;
        $state['bad_sectors']         = $badSectors;
        writeState($stateFile, $state);
        // Copy the state snapshot into the failed directory for post-mortem diagnosis
        @copy($stateFile, $failedDir . '/rip_state.json');
        wDebugLog('worker finished: failed', ['failed_dir' => $failedDir, 'bad_sectors' => $badSectors, 'ripped_tracks' => $rippedTracks, 'failure_message' => $failureMessage]);
    }

} catch (RuntimeException $e) {
    // moveDir (or another file operation) failed — write a failed state so
    // the UI shows the error screen rather than silently stalling in 'ripping'
    // with a dead PID and eventually snapping back to WAITING_FOR_DISC.
    // The ripped files remain in $tempDir so no audio data is lost.
    $failureMessage = 'Output copy failed: ' . $e->getMessage()
                    . ' (files preserved in ' . $tempDir . ')';
    $logTail = tailLines($logTail, $failureMessage);

    $state = readState($stateFile);
    $state['state']               = 'failed';
    $state['failure_message']     = $failureMessage;
    $state['current_track']       = 0;
    $state['current_track_phase'] = '';
    $state['log_tail']            = $logTail;
    $state['bad_sectors']         = $badSectors;
    writeState($stateFile, $state);
    // Copy state snapshot into the stranded temp directory for post-mortem diagnosis
    @copy($stateFile, $tempDir . '/rip_state.json');

    shell_exec('eject ' . escapeshellarg($device) . ' 2>/dev/null');
    wDebugLog('worker error: output copy failed', ['error' => $e->getMessage(), 'temp_dir' => $tempDir]);
}
