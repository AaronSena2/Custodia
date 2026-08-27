<?php
/**
 * Streams a document version's stored file to the browser — the HTTP entry
 * point for custodia_download_document_version(), which previously had no
 * caller anywhere in the app (it built the path and wrote the audit record
 * but nothing ever served the bytes). Plain GET, like actions/audit_export.php,
 * since the browser needs to navigate/save or feed a <video>/<audio> element,
 * not receive a fetch() JSON response.
 *
 * Query params:
 *   documentId  required
 *   versionId   optional — defaults to the current version
 *   view        optional flag — requests inline display (used by the preview
 *               modal in documents.php/matter.php, and by <audio>/<video>/
 *               <embed> elements) instead of a forced download; only honored
 *               for the allow-list in CUSTODIA_PREVIEWABLE_MIME_TYPES
 *               (includes/helpers.php), since inline-rendering an uploaded
 *               HTML/SVG file same-origin would be a stored-XSS vector.
 *
 * Supports HTTP Range requests (206 Partial Content) so audio/video elements
 * can seek and large downloads can resume, instead of always sending the
 * whole file from byte 0.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/digital_documents.php';

$user = custodia_current_user();
if (!$user) {
    http_response_code(401);
    exit('Sign in required.');
}

$documentId = $_GET['documentId'] ?? '';
$versionId = $_GET['versionId'] ?? null;
if ($documentId === '') {
    http_response_code(400);
    exit('Missing documentId.');
}

$pdo = custodia_db();
$requestedInline = isset($_GET['view']);
try {
    $result = custodia_download_document_version($pdo, $user, $documentId, $versionId ?: null, $requestedInline, custodia_client_ip());
} catch (CustodiaHttpException $e) {
    http_response_code($e->status);
    exit($e->getMessage());
}

$path = $result['path'];
$version = $result['version'];
$inline = $result['inline'];

if (!is_file($path)) {
    http_response_code(404);
    exit('Stored file is missing from disk.');
}

// Prefer the columns captured at upload time (see includes/storage.php); fall
// back to deriving them for rows written before those columns existed
// (run sql/upgrade_003_file_metadata.sql to backfill those permanently).
$originalName = $version['original_filename'] ?: custodia_storage_original_name($version['storage_key']);
$mimeType = $version['mime_type'] ?: ((new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream');
$fileSize = filesize($path);

header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($originalName) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Accept-Ranges: bytes');

$rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
    $start = $m[1] === '' ? max(0, $fileSize - (int) $m[2]) : (int) $m[1];
    $end = $m[2] === '' || $m[1] === '' ? $fileSize - 1 : min((int) $m[2], $fileSize - 1);

    if ($start > $end || $start >= $fileSize) {
        header('Content-Range: bytes */' . $fileSize);
        http_response_code(416);
        exit;
    }

    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
    header('Content-Length: ' . ($end - $start + 1));

    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = fread($fh, min(65536, $remaining));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($fh);
} else {
    header('Content-Length: ' . $fileSize);
    readfile($path);
}
