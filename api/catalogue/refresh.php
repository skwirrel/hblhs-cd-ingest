<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/response.php';

requireMethod('POST');

$config    = loadConfig();
$sourceUrl = $config['catalogue']['source_url'];

if (empty($sourceUrl)) {
    jsonError('NO_SOURCE_URL', 'No catalogue source_url is configured in config.ini.', 500);
    exit;
}

$tmpFile = $config['temp_dir'] . '/catalogue_refresh_' . uniqid() . '.csv';

// Fetch via curl
$ch = curl_init($sourceUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'HBLHS-CD-Ingest/1.0',
]);
$body     = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($body === false || $httpCode !== 200) {
    jsonError('FETCH_FAILED', "Remote URL returned HTTP $httpCode. $curlErr", 500);
    exit;
}

file_put_contents($tmpFile, $body);

// Validate as CSV
$fh       = fopen($tmpFile, 'r');
$header   = fgetcsv($fh);
$rowCount = 0;
while (fgetcsv($fh) !== false) {
    $rowCount++;
}
fclose($fh);

if ($header === false || $rowCount === 0) {
    unlink($tmpFile);
    jsonError('PARSE_FAILED', 'Downloaded file could not be parsed as valid CSV. Existing local cache retained.', 500);
    exit;
}

// Atomically replace
rename($tmpFile, $config['catalogue_csv']);

jsonOk([
    'record_count' => $rowCount,
    'fetched_at'   => gmdate('c'),
    'source_url'   => $sourceUrl,
]);
