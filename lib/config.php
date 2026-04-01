<?php
/**
 * Loads config.ini and returns a normalised config array.
 * Relative paths are resolved against base_dir.
 */
function loadConfig(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $configFile = __DIR__ . '/../config.ini';
    if (!file_exists($configFile)) {
        throw new RuntimeException('config.ini not found at ' . realpath(__DIR__ . '/..'));
    }

    $ini = parse_ini_file($configFile, true, INI_SCANNER_RAW);
    if ($ini === false) {
        throw new RuntimeException('Failed to parse config.ini — check syntax.');
    }

    $baseDir = rtrim($ini['general']['base_dir'] ?? __DIR__ . '/..', '/');

    $resolve = static function (string $path) use ($baseDir): string {
        return ($path[0] === '/') ? $path : $baseDir . '/' . $path;
    };

    $debug = filter_var($ini['general']['debug'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

    $cache = [
        'base_dir'      => $baseDir,
        'debug'         => $debug,
        'drive'         => $ini['device']['drive']        ?? '/dev/sr0',
        'cdstat_script' => $resolve($ini['device']['cdstat_script'] ?? 'scripts/cdstat.pl'),
        'output_dir'    => $resolve($ini['paths']['output_dir']    ?? 'data/output'),
        'temp_dir'      => $resolve($ini['paths']['temp_dir']      ?? 'data/temp'),
        'catalogue_csv' => $resolve($ini['paths']['catalogue_csv'] ?? 'data/catalogue.csv'),
        'log_dir'       => $resolve($ini['paths']['log_dir']       ?? 'data/logs'),
        'state_file'    => $resolve($ini['paths']['state_file']    ?? 'data/rip_state.json'),
        'port'          => (int) ($ini['server']['port']    ?? 8080),
        'doc_root'      => $resolve($ini['server']['doc_root'] ?? 'public'),
        'ui' => [
            'inactivityBeepHoldoffSeconds' => (int) ($ini['ui']['inactivity_beep_holdoff_seconds'] ?? 5),
            'beepIntervalSeconds'          => (int) ($ini['ui']['beep_interval_seconds']           ?? 2),
            'beepDurationMs'               => (int) ($ini['ui']['beep_duration_ms']                ?? 200),
            'beepFrequencyHz'              => (int) ($ini['ui']['beep_frequency_hz']               ?? 880),
            'debug'                        => $debug,
        ],
        'encoding' => [
            'format'       => $ini['encoding']['format']       ?? 'mp3',
            'lame_options' => $ini['encoding']['lame_options'] ?? '--preset voice',
        ],
        'catalogue' => [
            'source_url'            => $ini['catalogue']['source_url']            ?? '',
            'auto_refresh_on_start' => filter_var(
                $ini['catalogue']['auto_refresh_on_start'] ?? 'false',
                FILTER_VALIDATE_BOOLEAN
            ),
        ],
    ];

    return $cache;
}
