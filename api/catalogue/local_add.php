<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/local_catalogue.php';

requireMethod('POST');

$config = loadConfig();
$body   = getJsonBody();

$id     = trim($body['id']     ?? '');
$author = trim($body['author'] ?? '');
$title  = trim($body['title']  ?? '');
$date   = trim($body['date']   ?? '');

if ($id === '') {
    jsonError('MISSING_PARAM', 'Field "id" is required.', 400);
    exit;
}

$csvFile = $config['local_catalogue_csv'];
$idNorm  = strtolower(str_replace(' ', '', $id));

// Idempotent: if already exists, return the existing synthesized description
$existing = localCatalogueFind($csvFile, $idNorm);
if ($existing !== null) {
    debugLog('local_add: already exists', ['id' => $id]);
    jsonOk([
        'id'          => $existing['id'],
        'title'       => $existing['title'],
        'description' => localCatalogueSynthDesc($existing),
    ]);
    exit;
}

localCatalogueAppend($csvFile, $id, $author, $title, $date);

debugLog('local_add: appended', ['id' => $id, 'title' => $title]);

$entry = compact('id', 'author', 'title', 'date');

jsonOk([
    'id'          => $id,
    'title'       => $title,
    'description' => localCatalogueSynthDesc($entry),
]);
