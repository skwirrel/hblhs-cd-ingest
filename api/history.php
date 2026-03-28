<?php
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/response.php';

requireMethod('GET');

$config    = loadConfig();
$outputDir = $config['output_dir'];

$limit       = max(1, min(500, (int) ($_GET['limit']        ?? 50)));
$offset      = max(0,          (int) ($_GET['offset']       ?? 0));
$damagedOnly = filter_var($_GET['damaged_only'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

$records = [];

if (is_dir($outputDir)) {
    foreach (glob($outputDir . '/*/meta.json') ?: [] as $metaFile) {
        $meta = json_decode(file_get_contents($metaFile), true);
        if (!is_array($meta)) {
            continue;
        }
        $status = $meta['status'] ?? '';
        if ($damagedOnly && $status === 'ok') {
            continue;
        }
        $records[] = [
            'metadata_version' => (int)   ($meta['metadata_version'] ?? 1),
            'location_id'      => (string) ($meta['location_id']      ?? ''),
            'directory_name'   => (string) ($meta['directory_name']   ?? ''),
            'subject'          => (string) ($meta['subject']          ?? ''),
            'description'      => (string) ($meta['description']      ?? ''),
            'rip_completed_at' => (string) ($meta['rip_completed_at'] ?? ''),
            'status'           => $status,
            'track_count'      => (int)    ($meta['track_count']      ?? 0),
            'bad_sector_count' => (int)    ($meta['bad_sector_count'] ?? 0),
            'in_catalogue'     => (bool)   ($meta['in_catalogue']     ?? true),
        ];
    }
}

// Reverse-chronological
usort($records, static fn($a, $b) => strcmp($b['rip_completed_at'], $a['rip_completed_at']));

$total   = count($records);
$records = array_values(array_slice($records, $offset, $limit));

jsonOk([
    'total'   => $total,
    'limit'   => $limit,
    'offset'  => $offset,
    'records' => $records,
]);
