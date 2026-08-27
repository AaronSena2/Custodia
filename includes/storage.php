<?php
/**
 * Local-filesystem stand-in for S3-compatible object storage — same
 * swap-in-later approach as the Node version's StorageService. Swap this
 * for an S3 SDK client to go to production; nothing else in the codebase
 * needs to change since callers only see storageKey/sha256Hash/fileSizeBytes/
 * originalFilename/mimeType.
 */

require_once __DIR__ . '/config.php';

function custodia_storage_save(string $matterId, string $originalName, string $tmpPath): array
{
    $base = custodia_config()['storage_path'];
    $dir = $base . '/' . $matterId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName);
    $storageKey = $matterId . '/' . uniqid('', true) . '-' . $safeName;
    $destPath = $base . '/' . $storageKey;

    if (!move_uploaded_file($tmpPath, $destPath) && !copy($tmpPath, $destPath)) {
        throw new RuntimeException('Failed to store uploaded file.');
    }

    // Detected from the actual bytes on disk, not trusted from the client's
    // declared Content-Type — this is what actions/download_document.php
    // serves back, so it has to reflect what the file really is.
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($destPath) ?: 'application/octet-stream';

    return [
        'storageKey' => $storageKey,
        'sha256Hash' => hash_file('sha256', $destPath),
        'fileSizeBytes' => filesize($destPath),
        'originalFilename' => $originalName,
        'mimeType' => $mimeType,
    ];
}

function custodia_storage_path(string $storageKey): string
{
    return custodia_config()['storage_path'] . '/' . $storageKey;
}

/** Recovers the original uploaded filename from a storage key (matterId/uniqid-originalName). */
function custodia_storage_original_name(string $storageKey): string
{
    $base = basename($storageKey);
    $sepPos = strpos($base, '-');
    return $sepPos === false ? $base : substr($base, $sepPos + 1);
}
