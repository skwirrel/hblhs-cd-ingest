<?php
/**
 * JSON response helpers.
 * All API handlers include this file and use these functions exclusively
 * for producing output — never echo directly.
 */

/**
 * Write a timestamped line to data/logs/debug.log when debug mode is on.
 * Safe to call anywhere — silently no-ops if config is unavailable or debug=false.
 */
function debugLog(string $message, array $context = []): void
{
    try {
        $config = loadConfig();
        if (empty($config['debug'])) {
            return;
        }
        $logFile = $config['log_dir'] . '/debug.log';
    } catch (Throwable $e) {
        return;
    }

    $ts    = gmdate('Y-m-d\TH:i:s\Z');
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $src   = isset($trace[0]['file']) ? basename($trace[0]['file']) : '?';
    $ctx   = !empty($context)
        ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : '';

    file_put_contents($logFile, "[$ts] [$src] $message$ctx\n", FILE_APPEND | LOCK_EX);
}

function jsonOk(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);

    // Strip high-volume text fields before logging so the debug log stays readable
    $loggable = array_diff_key($data, array_flip(['log_tail', 'rip_log', 'records']));
    debugLog("→ $status OK", $loggable);
}

function jsonError(string $code, string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]]);

    debugLog("→ $status ERROR", ['code' => $code, 'message' => $message]);
}

function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        jsonError('METHOD_NOT_ALLOWED', "This endpoint only accepts $method requests.", 405);
        exit;
    }
}

function getJsonBody(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    // The router may have pre-read the body (to log it); use that buffer if available
    $raw = $GLOBALS['_RAW_INPUT'] ?? file_get_contents('php://input');
    if (empty($raw)) {
        return $cache = [];
    }
    $decoded = json_decode($raw, true);
    return $cache = is_array($decoded) ? $decoded : [];
}

/**
 * If the state file shows 'ripping' but the worker process is no longer alive,
 * reset it to idle and return the fresh state.  Called wherever code branches
 * on the 'ripping' state so a killed worker never permanently blocks the UI.
 */
function resolveStaleRip(string $path, array $state): array
{
    if ($state['state'] !== 'ripping') {
        return $state;
    }

    $pid     = (int) ($state['pid'] ?? 0);
    $isStale = false;

    if ($pid > 0 && function_exists('posix_kill')) {
        // Signal 0 checks process existence without sending any signal
        $isStale = !posix_kill($pid, 0);
    } elseif (file_exists($path)) {
        // Fallback: worker writes the state file every 200 ms while alive
        $isStale = (time() - filemtime($path)) > 60;
    }

    if (!$isStale) {
        return $state;
    }

    debugLog('stale rip detected — auto-resetting', ['pid' => $pid]);
    $fresh = [
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
    ];
    writeStateFile($path, $fresh);
    return $fresh;
}

/**
 * Read the rip state file, returning defaults if missing or unreadable.
 */
function readStateFile(string $path): array
{
    $defaults = [
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
    ];

    if (!file_exists($path)) {
        return $defaults;
    }

    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? array_merge($defaults, $data) : $defaults;
}

/**
 * Atomically write the state file.
 */
function writeStateFile(string $path, array $state): void
{
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($state));
    rename($tmp, $path);
}

/**
 * Build the slug+hash directory name from a Location ID.
 */
function locationDirName(string $locationId): string
{
    $slug = preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]/', '_', strtolower($locationId)));
    $slug = trim($slug, '_');
    // Hash from the spaces-stripped, lowercased form so spacing variants always
    // resolve to the same directory (e.g. "ARC 1 M" and "ARC1M" are the same disc).
    $norm = strtolower(str_replace(' ', '', $locationId));
    $hash = substr(md5($norm), 0, 8);
    return $slug . '_' . $hash;
}
