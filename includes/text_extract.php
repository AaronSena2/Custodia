<?php
/**
 * Real (not stubbed) plain-text extraction, run inline at upload time, so
 * documents become full-text searchable and comparable without waiting on
 * any external service or job queue. Deliberately dependency-free for
 * DOCX/PDF/plain-text — uses only PHP's bundled zip and zlib extensions,
 * both enabled by default on a stock XAMPP install. Images are the one
 * exception: they're OCR'd by shelling out to the Tesseract binary (see
 * custodia_ocr_image_text() below) if it's installed — the one place this
 * module isn't dependency-free, because no dependency-free OCR exists.
 *
 * Scanned/image-only PDFs are still NOT OCR'd — that would require
 * rasterizing each page to an image first (Ghostscript or poppler, neither
 * of which this deployment has), which is a materially bigger dependency
 * than "OCR this image file directly." They're flagged 'FAILED' with no
 * text, same as before, rather than silently guessed at.
 *
 * Honest about its other limits: DOCX extraction is reliable (a .docx is
 * just a zip of XML, parsed structurally). PDF extraction is best-effort —
 * it reads the text-showing operators out of each page's content stream,
 * which works well for typical born-digital PDFs (Word/PDF exports, the
 * common case for legal documents) but can miss or garble text in PDFs
 * that use heavily subsetted/custom-encoded fonts.
 */

const CUSTODIA_EXTRACT_MAX_CHARS = 2_000_000; // guardrail so one huge file can't bloat a row indefinitely

const CUSTODIA_SUPPORTED_EXTRACTION_EXTENSIONS = [
    'txt', 'md', 'csv', 'log', 'docx', 'pdf',
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff',
];

/**
 * @return array{status: string, text: ?string} status is DONE, UNSUPPORTED, or FAILED.
 */
function custodia_extract_text_for_upload(string $filePath, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    try {
        $text = match ($ext) {
            'txt', 'md', 'csv', 'log' => custodia_extract_plain_text($filePath),
            'docx' => custodia_extract_docx_text($filePath),
            'pdf' => custodia_extract_pdf_text($filePath),
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff' => custodia_ocr_image_text($filePath),
            default => null,
        };
    } catch (Throwable $e) {
        error_log('[custodia] text extraction failed for ' . $originalName . ': ' . $e->getMessage());
        return ['status' => 'FAILED', 'text' => null];
    }

    if (!in_array($ext, CUSTODIA_SUPPORTED_EXTRACTION_EXTENSIONS, true)) {
        return ['status' => 'UNSUPPORTED', 'text' => null];
    }

    $text = trim((string) $text);
    if ($text === '') {
        // Parsed successfully but found no extractable text — most often a
        // scanned/image-only PDF with no text layer, or an empty file.
        return ['status' => 'FAILED', 'text' => null];
    }

    if (function_exists('mb_strlen') && mb_strlen($text) > CUSTODIA_EXTRACT_MAX_CHARS) {
        $text = mb_substr($text, 0, CUSTODIA_EXTRACT_MAX_CHARS);
    } elseif (strlen($text) > CUSTODIA_EXTRACT_MAX_CHARS) {
        $text = substr($text, 0, CUSTODIA_EXTRACT_MAX_CHARS);
    }

    return ['status' => 'DONE', 'text' => $text];
}

function custodia_extract_plain_text(string $filePath): string
{
    $raw = file_get_contents($filePath);
    if ($raw === false) {
        return '';
    }
    // Normalize to UTF-8 so it stores/searches cleanly regardless of source encoding.
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
        $raw = $converted !== false ? $converted : $raw;
    }
    return $raw;
}

/**
 * A .docx file is a zip archive; the document body lives at word/document.xml
 * as a sequence of <w:p> paragraphs containing <w:t> text runs. We keep
 * paragraph boundaries (needed for version-comparison quality) by joining
 * each paragraph's runs and separating paragraphs with a blank line.
 */
function custodia_extract_docx_text(string $filePath): string
{
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return '';
    }

    // Split on paragraph boundaries first so we can preserve them as newlines.
    $paragraphs = preg_split('/<w:p[ >]/', $xml) ?: [];
    $lines = [];
    foreach ($paragraphs as $chunk) {
        if (!preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $chunk, $matches)) {
            continue;
        }
        $line = implode('', array_map(
            fn ($t) => html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $matches[1]
        ));
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return implode("\n\n", $lines);
}

/**
 * Best-effort PDF text extraction with zero external dependencies: walks the
 * raw PDF byte stream for `stream ... endstream` blocks, inflates FlateDecode
 * content streams (PDF's zlib format matches PHP's gzuncompress() directly),
 * then pulls literal strings out of Tj/TJ text-showing operators in document
 * order. A `Td`/`TD`/`T*` positioning operator between text runs is treated
 * as a line break, giving comparison a usable paragraph structure.
 */
function custodia_extract_pdf_text(string $filePath): string
{
    $raw = file_get_contents($filePath);
    if ($raw === false) {
        return '';
    }

    $lines = [];
    $currentLine = [];

    if (!preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streamMatches)) {
        return '';
    }

    foreach ($streamMatches[1] as $streamData) {
        $content = @gzuncompress($streamData);
        if ($content === false) {
            // Not FlateDecode (or already-plain content stream) — try it raw;
            // harmless if it isn't actually a text-showing content stream,
            // since the operator regex below just won't match anything.
            $content = $streamData;
        }
        if (!is_string($content) || $content === '') {
            continue;
        }

        // Walk the stream left-to-right so line breaks land in the right place.
        $pattern = '/\((?:[^()\\\\]|\\\\.)*\)\s*Tj'      // (text) Tj
            . '|\[(?:[^\[\]]*)\]\s*TJ'                    // [ (a) -100 (b) ] TJ
            . '|\bT\*'                                     // explicit next-line
            . '|-?\d*\.?\d+\s+-?\d*\.?\d+\s+Td'            // relative move → new line
            . '|-?\d*\.?\d+\s+-?\d*\.?\d+\s+-?\d*\.?\d+\s+-?\d*\.?\d+\s+-?\d*\.?\d+\s+-?\d*\.?\d+\s+Tm/';
        if (!preg_match_all($pattern, $content, $ops)) {
            continue;
        }

        foreach ($ops[0] as $op) {
            if (str_ends_with($op, 'Tj')) {
                $currentLine[] = custodia_unescape_pdf_string(substr($op, 0, -2));
            } elseif (str_ends_with($op, 'TJ')) {
                $inner = substr($op, 0, -2);
                if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/', $inner, $strMatches)) {
                    foreach ($strMatches[0] as $s) {
                        $currentLine[] = custodia_unescape_pdf_string($s);
                    }
                }
            } else {
                // A positioning/line-break operator — flush the current line.
                if (!empty($currentLine)) {
                    $lines[] = implode(' ', $currentLine);
                    $currentLine = [];
                }
            }
        }
    }

    if (!empty($currentLine)) {
        $lines[] = implode(' ', $currentLine);
    }

    // Collapse noise: runs of many single-character "words" left over from
    // per-glyph positioning are common in PDF output; light cleanup only,
    // we don't try to perfectly reconstruct word boundaries.
    $text = implode("\n", array_filter(array_map('trim', $lines), fn ($l) => $l !== ''));
    return preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
}

function custodia_unescape_pdf_string(string $literal): string
{
    $literal = trim($literal);
    if (strlen($literal) >= 2 && $literal[0] === '(' && $literal[-1] === ')') {
        $literal = substr($literal, 1, -1);
    }
    $literal = preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', function ($m) {
        return match ($m[1]) {
            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c",
            '(' => '(', ')' => ')', '\\' => '\\',
            default => chr(intval($m[1], 8) & 0xFF),
        };
    }, $literal);
    return $literal ?? '';
}

// Default install location for the winget/official Windows installer of
// Tesseract OCR. Checked first so this works without depending on PATH,
// which a long-running Apache process won't pick up after an install
// anyway without a restart; custodia_tesseract_binary() falls back to a
// PATH lookup for non-Windows or differently-installed setups.
const CUSTODIA_TESSERACT_DEFAULT_PATH = 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe';

function custodia_tesseract_binary(): ?string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved === '' ? null : $resolved;
    }

    if (is_file(CUSTODIA_TESSERACT_DEFAULT_PATH)) {
        $resolved = CUSTODIA_TESSERACT_DEFAULT_PATH;
        return $resolved;
    }

    if (function_exists('exec')) {
        $lookupCmd = str_starts_with(PHP_OS, 'WIN') ? 'where tesseract' : 'command -v tesseract';
        exec($lookupCmd . ' 2>&1', $out, $code);
        if ($code === 0 && !empty($out[0])) {
            $resolved = trim($out[0]);
            return $resolved;
        }
    }

    $resolved = '';
    return null;
}

/**
 * Runs Tesseract OCR against an image file and returns whatever text it
 * finds ('' if Tesseract isn't installed, the image has no text, or OCR
 * fails for any reason — callers treat empty text as FAILED, same as an
 * unreadable PDF, not a hard error). $filePath is always a server-generated
 * storage path, never a user-supplied name, so this is safe to shell out to
 * despite not being parameterizable like a SQL query.
 */
function custodia_ocr_image_text(string $filePath): string
{
    $binary = custodia_tesseract_binary();
    if ($binary === null || !function_exists('exec')) {
        return '';
    }

    $outputBase = tempnam(sys_get_temp_dir(), 'custodia_ocr_');
    if ($outputBase === false) {
        return '';
    }
    unlink($outputBase); // tesseract creates "<outputBase>.txt" itself; the placeholder file would only be in the way

    $cmd = escapeshellarg($binary) . ' ' . escapeshellarg($filePath) . ' ' . escapeshellarg($outputBase) . ' -l eng --psm 3 2>&1';
    exec($cmd, $unused, $exitCode);

    $txtPath = $outputBase . '.txt';
    $text = '';
    if (is_file($txtPath)) {
        $text = (string) file_get_contents($txtPath);
        unlink($txtPath);
    }
    return trim($text);
}
