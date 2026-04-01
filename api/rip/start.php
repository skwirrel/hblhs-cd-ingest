<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/local_catalogue.php';

requireMethod('POST');

$config = loadConfig();
$body   = getJsonBody();

// ── Input validation ──────────────────────────────────────────
$locationId = trim($body['location_id'] ?? '');

debugLog('rip start requested', ['location_id' => $locationId]);

if ($locationId === '') {
    jsonError('MISSING_LOCATION_ID', 'No location_id provided in request body.', 400);
    exit;
}

// ── Not already ripping ───────────────────────────────────────
$stateFile = $config['state_file'];
$state     = resolveStaleRip($stateFile, readStateFile($stateFile));

debugLog('current rip state', ['state' => $state['state']]);

if ($state['state'] === 'ripping') {
    jsonError('ALREADY_RIPPING', 'A rip is already in progress.', 409);
    exit;
}

// ── Disc present? ─────────────────────────────────────────────
$cdstat = escapeshellarg($config['cdstat_script']);
$device = escapeshellarg($config['drive']);
$ioctl  = (int) trim((string) shell_exec("perl $cdstat $device 2>/dev/null"));

debugLog('disc check', ['ioctl' => $ioctl, 'disc_ok' => ($ioctl === 4)]);

if ($ioctl !== 4) { // 4 = CDS_DISC_OK
    jsonError('NO_DISC', 'No disc is present or the drive is not ready.', 409);
    exit;
}

// ── Catalogue lookup ──────────────────────────────────────────
$inCatalogue    = false;
$catalogueEntry = null;
$csvFile        = $config['catalogue_csv'];

if (file_exists($csvFile)) {
    $fh     = fopen($csvFile, 'r');
    $header = fgetcsv($fh);
    $lower  = array_map(static fn($v) => strtolower(trim($v)), $header);
    $cLoc   = array_search('location',    $lower, true);
    $cSub   = array_search('subject',     $lower, true);
    $cDesc  = array_search('description', $lower, true);
    // Normalise: strip spaces and lowercase for a forgiving comparison
    $idNorm = strtolower(str_replace(' ', '', $locationId));

    while (($row = fgetcsv($fh)) !== false) {
        $rowNorm = strtolower(str_replace(' ', '', trim($row[$cLoc] ?? '')));
        if ($rowNorm === $idNorm) {
            $inCatalogue = true;
            $locationId  = trim($row[$cLoc]); // adopt canonical form from DB
            $catalogueEntry = [
                'location'    => $locationId,
                'subject'     => trim($row[$cSub]  ?? ''),
                'description' => trim($row[$cDesc] ?? ''),
            ];
            break;
        }
    }
    fclose($fh);
}

// ── Local catalogue fallback ──────────────────────────────────
if (!$inCatalogue) {
    $idNorm      = strtolower(str_replace(' ', '', $locationId));
    $localEntry  = localCatalogueFind($config['local_catalogue_csv'], $idNorm);
    if ($localEntry !== null) {
        $inCatalogue    = true;
        $locationId     = $localEntry['id'];
        $desc           = localCatalogueSynthDesc($localEntry);
        $catalogueEntry = [
            'location'    => $locationId,
            'subject'     => $localEntry['title'],
            'description' => $desc,
        ];
    }
}

debugLog('catalogue lookup', ['in_catalogue' => $inCatalogue, 'entry' => $catalogueEntry]);

if (!$inCatalogue) {
    jsonError(
        'UNKNOWN_LOCATION',
        'Location ID not found in catalogue or local acquisition records.',
        400
    );
    exit;
}

// ── Already complete? ─────────────────────────────────────────
$dirName    = locationDirName($locationId);
$outputPath = $config['output_dir'] . '/' . $dirName;
$metaFile   = $outputPath . '/meta.json';

$alreadyComplete = false;
if (file_exists($metaFile)) {
    $existingMeta    = json_decode(file_get_contents($metaFile), true);
    $alreadyComplete = (($existingMeta['status'] ?? '') === 'ok');
}
debugLog('already complete check', ['dir' => $dirName, 'already_complete' => $alreadyComplete]);

if ($alreadyComplete) {
    jsonError('ALREADY_COMPLETE', 'A rip with status: ok already exists for this Location ID.', 409);
    exit;
}

// ── Get track count and per-track sector counts ───────────────
$qOut        = shell_exec("cdparanoia -Q -d $device 2>&1");
$trackCount  = 0;
$trackSectors = []; // track_number (1-based int) => sector count
preg_match_all('/^\s+(\d+)\.\s+(\d+)\s+\[/m', (string) $qOut, $m);
if (!empty($m[1])) {
    $trackCount = (int) max($m[1]);
    foreach ($m[1] as $i => $tNum) {
        $trackSectors[(int) $tNum] = (int) $m[2][$i];
    }
}

if ($trackCount === 0) {
    jsonError('NO_DISC', 'Could not read disc structure — disc may not be audio.', 409);
    exit;
}

// ── Write rip info file (passed to worker) ────────────────────
$ripInfoFile = $config['temp_dir'] . '/rip_info_' . md5($locationId) . '_' . time() . '.json';
file_put_contents($ripInfoFile, json_encode([
    'location_id'    => $locationId,
    'in_catalogue'   => $inCatalogue,
    'subject'        => $catalogueEntry['subject']     ?? '',
    'description'    => $catalogueEntry['description'] ?? '',
    'track_count'    => $trackCount,
    'track_sectors'  => $trackSectors,
    'dir_name'       => $dirName,
]));

// ── Write initial state ───────────────────────────────────────
$newState = [
    'state'               => 'ripping',
    'location_id'         => $locationId,
    'progress_pct'        => 0,
    'tracks_done'         => 0,
    'tracks_total'        => $trackCount,
    'current_track'       => 0,
    'current_track_phase' => '',
    'bad_sectors'         => 0,
    'log_tail'            => '',
    'pid'                 => null,
    'cancel_requested'    => false,
];
writeStateFile($stateFile, $newState);

// ── Spawn worker ──────────────────────────────────────────────
$phpBin    = PHP_BINARY;
$worker    = __DIR__ . '/../../scripts/rip_worker.php';
$configIni = __DIR__ . '/../../config.ini';
$logFile   = $config['log_dir'] . '/rip_' . date('Ymd_His') . '_' . substr(md5($locationId), 0, 6) . '.log';

$cmd = escapeshellarg($phpBin)
     . ' ' . escapeshellarg($worker)
     . ' ' . escapeshellarg($configIni)
     . ' ' . escapeshellarg($ripInfoFile)
     . ' > ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';

$pid = (int) trim((string) shell_exec($cmd));

debugLog('worker spawned', ['pid' => $pid, 'log_file' => $logFile, 'dir_name' => $dirName, 'tracks' => $trackCount]);

// Update state with worker PID
$newState['pid'] = $pid;
writeStateFile($stateFile, $newState);

jsonOk([
    'state'          => 'ripping',
    'location_id'    => $locationId,
    'directory_name' => $dirName,
    'track_count'    => $trackCount,
]);
