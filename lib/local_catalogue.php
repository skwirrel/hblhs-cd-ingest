<?php
/**
 * Local acquisition catalogue helpers.
 *
 * The local catalogue CSV (data/local_catalogue.csv) has no header row.
 * Fields: ID, Title, People, Date, DownloadDate
 *
 * DownloadDate is always exactly 16 bytes in the stored file:
 *   - 16 spaces  => not yet downloaded
 *   - "dd/mm/yy HH:MM" (14 chars) + 2 spaces => downloaded at that time
 *
 * This fixed-width last field lets localCatalogueMarkDownloaded() use
 * fseek() to overwrite just that field without rewriting the whole file.
 */

/**
 * Scan the local catalogue for a matching (normalised) ID.
 * Returns [id, title, people, date] or null if not found.
 */
function localCatalogueFind(string $csvFile, string $idNorm): ?array
{
    if (!file_exists($csvFile)) {
        return null;
    }

    $fh = fopen($csvFile, 'r');
    if (!$fh) {
        return null;
    }

    $result = null;
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 4) {
            continue;
        }
        $rowNorm = strtolower(str_replace(' ', '', trim($row[0])));
        if ($rowNorm === $idNorm) {
            $result = [
                'id'     => trim($row[0]),
                'title'  => trim($row[1]),
                'people' => trim($row[2]),
                'date'   => trim($row[3]),
            ];
            break;
        }
    }

    fclose($fh);
    return $result;
}

/**
 * Append a new entry to the local catalogue CSV with a blank download date.
 */
function localCatalogueAppend(string $csvFile, string $id, string $title, string $people, string $date): void
{
    $fields = [
        csvQuoteField($id),
        csvQuoteField($title),
        csvQuoteField($people),
        csvQuoteField($date),
        str_repeat(' ', 16),  // fixed-width DownloadDate placeholder
    ];
    $line = implode(',', $fields) . "\n";

    file_put_contents($csvFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Seek-and-overwrite the DownloadDate field for the given IDs.
 * $dateStr must be exactly 14 chars (e.g. "01/04/26 14:30") — 2 trailing spaces are added.
 */
function localCatalogueMarkDownloaded(string $csvFile, array $ids, string $dateStr): void
{
    if (!file_exists($csvFile) || empty($ids)) {
        return;
    }

    // Build a normalised set of IDs to mark
    $normSet = [];
    foreach ($ids as $id) {
        $normSet[strtolower(str_replace(' ', '', $id))] = true;
    }

    // Pad/truncate dateStr to exactly 14 chars, then add 2 trailing spaces = 16 bytes
    $dateStr = substr(str_pad($dateStr, 14), 0, 14);
    $stamp   = $dateStr . '  '; // exactly 16 bytes

    $fh = fopen($csvFile, 'r+');
    if (!$fh) {
        return;
    }

    $lineStartByte = 0;
    while (($line = fgets($fh)) !== false) {
        // Parse just the ID field (first CSV field) to check match
        $row = str_getcsv($line);
        if (!empty($row[0])) {
            $rowNorm = strtolower(str_replace(' ', '', trim($row[0])));
            if (isset($normSet[$rowNorm])) {
                // Seek formula: $lineStartByte + strlen($line) - 17 (16 chars + \n)
                $dateOffset = $lineStartByte + strlen($line) - 17;
                if ($dateOffset >= 0) {
                    fseek($fh, $dateOffset);
                    fwrite($fh, $stamp);
                    fseek($fh, $lineStartByte + strlen($line)); // restore position
                }
            }
        }
        $lineStartByte += strlen($line);
    }

    fclose($fh);
}

/**
 * Build a readable description from a local catalogue entry.
 */
function localCatalogueSynthDesc(array $entry): string
{
    $parts = array_filter([
        $entry['title']  ?? '',
        $entry['people'] ?? '',
        $entry['date']   ?? '',
    ], static fn($v) => $v !== '');
    return implode(' — ', $parts);
}

/**
 * Minimal CSV field quoting: quote if value contains comma or double-quote.
 */
function csvQuoteField(string $value): string
{
    if (strpos($value, ',') !== false || strpos($value, '"') !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}
