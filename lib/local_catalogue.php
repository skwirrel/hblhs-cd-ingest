<?php
/**
 * Local acquisition catalogue helpers.
 *
 * The local catalogue CSV (data/local_catalogue.csv) has no header row.
 * Columns: ID, Author, Title, Date, DownloadDate
 *
 * DownloadDate is blank for undownloaded entries. localCatalogueMarkDownloaded()
 * rewrites the file rather than using in-place seeks, which allows any field
 * (notably Abstract) to contain embedded newlines in RFC 4180 quoted form.
 */

/**
 * Minimal CSV field quoting (RFC 4180).
 * Quotes fields that contain commas, double-quotes, or newlines.
 */
function csvQuoteField(string $value): string
{
    if (strpos($value, ',')  !== false
     || strpos($value, '"')  !== false
     || strpos($value, "\n") !== false
     || strpos($value, "\r") !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

/**
 * Format an array of fields as a single CSV line.
 */
function csvFormatRow(array $fields): string
{
    return implode(',', array_map('csvQuoteField', $fields)) . "\n";
}

/**
 * Scan the local catalogue for a matching (normalised) ID.
 * Returns associative array of fields or null if not found.
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
        if (count($row) < 2) {
            continue;
        }
        $rowNorm = strtolower(str_replace(' ', '', trim($row[0])));
        if ($rowNorm === $idNorm) {
            $result = [
                'id'     => trim($row[0]),
                'author' => trim($row[1] ?? ''),
                'title'  => trim($row[2] ?? ''),
                'date'   => trim($row[3] ?? ''),
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
function localCatalogueAppend(
    string $csvFile,
    string $id,
    string $author,
    string $title,
    string $date
): void {
    $line = csvFormatRow([$id, $author, $title, $date, '']);
    file_put_contents($csvFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Mark the given IDs as downloaded by rewriting the catalogue file.
 * Rewiring the whole file (rather than seeking in place) means multiline
 * quoted fields in Abstract (and other columns) are fully supported.
 */
function localCatalogueMarkDownloaded(string $csvFile, array $ids, string $dateStr): void
{
    if (!file_exists($csvFile) || empty($ids)) {
        return;
    }

    $normSet = [];
    foreach ($ids as $id) {
        $normSet[strtolower(str_replace(' ', '', $id))] = true;
    }

    // Read all rows
    $fh = fopen($csvFile, 'r');
    if (!$fh) {
        return;
    }
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        $rows[] = $row;
    }
    fclose($fh);

    // Stamp the DownloadDate (column 6) for matching rows
    foreach ($rows as &$row) {
        if (empty($row[0])) {
            continue;
        }
        $rowNorm = strtolower(str_replace(' ', '', trim($row[0])));
        if (isset($normSet[$rowNorm])) {
            while (count($row) < 5) {
                $row[] = '';
            }
            $row[4] = $dateStr;
        }
    }
    unset($row);

    // Atomically rewrite the file
    $tmp = $csvFile . '.tmp';
    $fh  = fopen($tmp, 'w');
    if (!$fh) {
        return;
    }
    foreach ($rows as $row) {
        fwrite($fh, csvFormatRow($row));
    }
    fclose($fh);
    rename($tmp, $csvFile);
}

/**
 * Build a readable description from a local catalogue entry.
 */
function localCatalogueSynthDesc(array $entry): string
{
    $parts = array_filter([
        $entry['title']  ?? '',
        $entry['author'] ?? '',
        $entry['date']   ?? '',
    ], static fn($v) => $v !== '');
    return implode(' — ', $parts);
}
