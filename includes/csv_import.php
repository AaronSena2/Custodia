<?php
/**
 * Shared CSV-reading helper for bulk import (Clients, User Accounts).
 * Dependency-free by design, same as the rest of this app — plain
 * fgetcsv(), no Excel-parsing library. A file saved as .xlsx needs to be
 * "Save As… CSV" first; the app doesn't read .xlsx directly.
 */

require_once __DIR__ . '/errors.php';

/**
 * Reads an uploaded CSV into an array of associative rows keyed by the
 * (lowercased, trimmed) header row. Blank lines are skipped. A UTF-8 BOM
 * (common when a file is saved from Excel) is stripped if present.
 *
 * @return array<int, array<string, string>>
 */
function custodia_read_csv_rows(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        throw custodia_bad_request('Could not read the uploaded file.');
    }

    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        throw custodia_bad_request('The file is empty.');
    }
    $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        if (count($line) === 1 && trim((string) $line[0]) === '') {
            continue; // blank line
        }
        $row = [];
        foreach ($header as $i => $key) {
            if ($key === '') {
                continue;
            }
            $row[$key] = trim((string) ($line[$i] ?? ''));
        }
        $rows[] = $row;
    }
    fclose($handle);

    if (empty($rows)) {
        throw custodia_bad_request('The file has a header row but no data rows.');
    }

    return $rows;
}
