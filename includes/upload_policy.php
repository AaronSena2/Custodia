<?php
/**
 * Server-side upload policy: which file types Custodia accepts, how large
 * each category may be, and a best-effort check that the actual file
 * content matches what its extension claims — so a renamed executable or
 * script can't ride in disguised as a PDF/image/audio/video file. Enforced
 * in custodia_upload_document_version() before the file ever reaches
 * includes/storage.php, on top of (not instead of) storage/.htaccess, which
 * blocks direct web access to whatever ends up on disk.
 */

// extension => [category, max bytes]. Sizes are generous for the category
// but still finite — mainly a backstop against accidental huge uploads,
// since the real ceiling in practice is php.ini's upload_max_filesize/
// post_max_size (see README for the video-sized values this needs).
const CUSTODIA_UPLOAD_CATEGORIES = [
    'pdf'  => ['document', 50 * 1024 * 1024],
    'docx' => ['document', 50 * 1024 * 1024],
    'doc'  => ['document', 50 * 1024 * 1024],
    'xlsx' => ['document', 50 * 1024 * 1024],
    'xls'  => ['document', 50 * 1024 * 1024],
    'pptx' => ['document', 100 * 1024 * 1024],
    'ppt'  => ['document', 100 * 1024 * 1024],
    'rtf'  => ['document', 20 * 1024 * 1024],
    'txt'  => ['document', 20 * 1024 * 1024],
    'md'   => ['document', 20 * 1024 * 1024],
    'csv'  => ['document', 20 * 1024 * 1024],
    'log'  => ['document', 20 * 1024 * 1024],

    'jpg'  => ['image', 25 * 1024 * 1024],
    'jpeg' => ['image', 25 * 1024 * 1024],
    'png'  => ['image', 25 * 1024 * 1024],
    'gif'  => ['image', 25 * 1024 * 1024],
    'webp' => ['image', 25 * 1024 * 1024],
    'bmp'  => ['image', 25 * 1024 * 1024],
    'tif'  => ['image', 25 * 1024 * 1024],
    'tiff' => ['image', 25 * 1024 * 1024],

    'mp3'  => ['audio', 100 * 1024 * 1024],
    'wav'  => ['audio', 200 * 1024 * 1024],
    'ogg'  => ['audio', 100 * 1024 * 1024],
    'm4a'  => ['audio', 100 * 1024 * 1024],
    'aac'  => ['audio', 100 * 1024 * 1024],
    'flac' => ['audio', 200 * 1024 * 1024],

    'mp4'  => ['video', 1024 * 1024 * 1024],
    'webm' => ['video', 1024 * 1024 * 1024],
    'mov'  => ['video', 1024 * 1024 * 1024],
    'avi'  => ['video', 1024 * 1024 * 1024],
    'mkv'  => ['video', 1024 * 1024 * 1024],
];

// Always refused outright regardless of detected content — the classic
// web-shell/script/executable disguise vectors. Belt-and-suspenders with
// storage/.htaccess (which stops one of these from being *executed* even if
// it somehow got stored) — rejecting at upload time is the primary defense
// and gives the uploader an immediate, honest error instead of a silent 403
// the next time someone tries to open it.
const CUSTODIA_BLOCKED_UPLOAD_EXTENSIONS = [
    'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
    'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'dll', 'apk',
    'sh', 'bash', 'ps1', 'vbs', 'vbe', 'wsf', 'js', 'jse', 'jar',
    'html', 'htm', 'svg', 'swf',
];

// Acceptable detected MIME prefixes per category. finfo's output varies
// across platforms/PHP builds — a .docx is a zip container and sometimes
// reports as plain application/zip, an audio-only .webm can report as
// video/webm, etc. — so this checks the broad category, not an exact MIME
// string, and only rejects a clear mismatch (e.g. an .mp4 that's actually a
// Windows executable).
const CUSTODIA_UPLOAD_CATEGORY_MIME_PREFIXES = [
    'document' => ['application/pdf', 'application/msword', 'application/vnd.', 'text/', 'application/zip', 'application/xml', 'application/octet-stream', 'inode/x-empty'],
    'image'    => ['image/', 'inode/x-empty'],
    'audio'    => ['audio/', 'video/', 'application/ogg', 'inode/x-empty'],
    'video'    => ['video/', 'audio/', 'application/octet-stream', 'inode/x-empty'],
];

/**
 * Throws a CustodiaHttpException (400) if the upload should be refused;
 * returns silently otherwise. Call after the file is on a local temp path
 * but before custodia_storage_save() persists it.
 */
function custodia_validate_upload(string $originalName, string $tmpPath, int $sizeBytes): void
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === '' || in_array($ext, CUSTODIA_BLOCKED_UPLOAD_EXTENSIONS, true)) {
        throw custodia_bad_request('Files of type .' . ($ext ?: '(none)') . ' are not allowed.');
    }

    if (!isset(CUSTODIA_UPLOAD_CATEGORIES[$ext])) {
        throw custodia_bad_request("Unsupported file type: .{$ext}. Allowed: PDF, Word/Excel/PowerPoint, plain text, images, audio, and video.");
    }

    [$category, $maxBytes] = CUSTODIA_UPLOAD_CATEGORIES[$ext];
    if ($sizeBytes > $maxBytes) {
        throw custodia_bad_request(sprintf(
            'This %s file is %.0f MB, which is over the %.0f MB limit for .%s uploads.',
            $category, $sizeBytes / 1_000_000, $maxBytes / 1_000_000, $ext
        ));
    }

    $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: 'application/octet-stream';
    $allowedPrefixes = CUSTODIA_UPLOAD_CATEGORY_MIME_PREFIXES[$category];
    $matches = false;
    foreach ($allowedPrefixes as $prefix) {
        if (str_starts_with($detectedMime, $prefix)) {
            $matches = true;
            break;
        }
    }
    if (!$matches) {
        throw custodia_bad_request("This file's content doesn't look like a .{$ext} file, so it can't be accepted.");
    }
}
