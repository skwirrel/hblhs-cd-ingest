<?php
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/local_catalogue.php';

requireMethod('GET');

$config  = loadConfig();
$mode    = $_GET['mode'] ?? 'all';

if ($mode !== 'all' && $mode !== 'new') {
    jsonError('INVALID_PARAM', 'Parameter "mode" must be "all" or "new".', 400);
    exit;
}

$csvFile = $config['local_catalogue_csv'];

// Send CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="local_catalogue_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-cache, no-store');

// Column header row
echo "ID,Title,People,Date,DownloadDate\n";

if (!file_exists($csvFile)) {
    exit;
}

if ($mode === 'all') {
    // Output all rows; trim the fixed-width DownloadDate field for cleanliness
    $fh = fopen($csvFile, 'r');
    if (!$fh) {
        exit;
    }
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 5) {
            continue;
        }
        $row[4] = trim($row[4]); // trim the download date padding
        $fields = array_map('csvQuoteField', $row);
        echo implode(',', $fields) . "\n";
    }
    fclose($fh);
} else {
    // mode=new: collect IDs of undownloaded rows, output them, then mark as downloaded
    $fh = fopen($csvFile, 'r');
    if (!$fh) {
        exit;
    }

    $newIds = [];
    $newRows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 5) {
            continue;
        }
        $downloadDate = $row[4]; // raw 16-byte field
        if (trim($downloadDate) === '') {
            $newIds[]  = trim($row[0]);
            $row[4]    = ''; // output with blank download date
            $newRows[] = $row;
        }
    }
    fclose($fh);

    foreach ($newRows as $row) {
        $fields = array_map('csvQuoteField', $row);
        echo implode(',', $fields) . "\n";
    }

    if (!empty($newIds)) {
        $dateStr = date('d/m/y H:i'); // exactly 14 chars
        localCatalogueMarkDownloaded($csvFile, $newIds, $dateStr);
    }
}
