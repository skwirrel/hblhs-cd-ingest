<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/local_catalogue.php';

requireMethod('GET');

$id = trim($_GET['id'] ?? '');
if ($id === '') {
    jsonError('MISSING_PARAM', 'Query parameter "id" is required.', 400);
    exit;
}

$config  = loadConfig();
$csvFile = $config['catalogue_csv'];

if (!file_exists($csvFile)) {
    jsonError('CATALOGUE_UNAVAILABLE', 'Local catalogue CSV not found. Run scripts/fetch_catalogue.php first.', 500);
    exit;
}

$fh = fopen($csvFile, 'r');
if (!$fh) {
    jsonError('CATALOGUE_UNAVAILABLE', 'Could not open catalogue CSV.', 500);
    exit;
}

// Read header and locate required columns
$header = fgetcsv($fh);
if ($header === false) {
    fclose($fh);
    jsonError('CATALOGUE_UNAVAILABLE', 'Catalogue CSV is empty or unreadable.', 500);
    exit;
}

$lower = array_map(static fn($v) => strtolower(trim($v)), $header);
$colSubject  = array_search('subject',     $lower, true);
$colLocation = array_search('location',    $lower, true);
$colDesc     = array_search('description', $lower, true);

if ($colSubject === false || $colLocation === false || $colDesc === false) {
    fclose($fh);
    jsonError('CATALOGUE_PARSE_ERROR', 'Catalogue CSV is missing required columns (Subject, Location, Description).', 500);
    exit;
}

// Normalise: strip spaces and lowercase for a forgiving comparison
$idNorm = strtolower(str_replace(' ', '', $id));
$result = null;

while (($row = fgetcsv($fh)) !== false) {
    $rowNorm = strtolower(str_replace(' ', '', trim($row[$colLocation] ?? '')));
    if ($rowNorm === $idNorm) {
        $result = [
            'found'       => true,
            'location'    => trim($row[$colLocation]),
            'subject'     => trim($row[$colSubject]  ?? ''),
            'description' => trim($row[$colDesc]     ?? ''),
        ];
        break;
    }
}

fclose($fh);

if ($result !== null) {
    jsonOk($result);
    exit;
}

// Fall back to local acquisition catalogue
$localEntry = localCatalogueFind($config['local_catalogue_csv'], $idNorm);
if ($localEntry !== null) {
    $desc = localCatalogueSynthDesc($localEntry);
    jsonOk([
        'found'       => true,
        'location'    => $localEntry['id'],
        'subject'     => $localEntry['title'],
        'description' => $desc,
    ]);
    exit;
}

jsonOk(['found' => false]);
