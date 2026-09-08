<?php
/**
 * Scheduled sweep that OCRs scanned/image-only PDFs (and retries any image
 * Tesseract couldn't read at upload time) — security review 2026-09-03,
 * finding 4.4 flagged these as a distinct `NO_TEXT_LAYER` status but didn't
 * fix them; this job is the fix.
 *
 * Why a background job instead of doing this at upload time: a scanned PDF
 * has to be rasterized page-by-page to an image before Tesseract can even
 * look at it (see custodia_ghostscript_binary()/custodia_ocr_scanned_pdf_text()
 * in includes/text_extract.php) — for a multi-page scan that's easily tens
 * of seconds to minutes, which has no business blocking an upload HTTP
 * request the way the near-instant standalone-image OCR does. Running it out
 * of band also means this job catches the existing backlog automatically:
 * any document already sitting NO_TEXT_LAYER from before this job existed
 * gets picked up the same way a brand-new upload would, with no separate
 * one-off backfill script needed.
 *
 * Requires Ghostscript in addition to the Tesseract install the standalone-
 * image OCR path already needs (Tesseract alone can't rasterize a PDF page).
 * If either is missing, the sweep does nothing and says so — matching rows
 * are left exactly as they are (still NO_TEXT_LAYER, never downgraded to
 * FAILED) rather than erroring, so this is safe to register with Task
 * Scheduler before Ghostscript is actually installed.
 *
 * Processes at most CUSTODIA_OCR_SWEEP_BATCH_SIZE document versions per run,
 * oldest-uploaded first, so one invocation against a large backlog can't run
 * indefinitely — the next scheduled run picks up wherever this one left off.
 *
 * Same "small CLI script" convention as jobs/overdue_sweep.php /
 * jobs/verify_audit_chain.php: no arguments, safe to re-run, nothing to undo
 * if run twice (a version already flipped to DONE is simply not selected
 * again).
 *
 * Register with Windows Task Scheduler to run hourly:
 *   schtasks /create /tn "Custodia OCR Scanned PDF Sweep" /tr "C:\xampp\php\php.exe C:\path\to\Registry System\jobs\ocr_scanned_pdfs.php" /sc hourly
 * (adjust the php.exe and app paths to match your actual install — see
 * DEPLOYMENT.md §4 for the "run whether user is logged on or not" setting
 * every scheduled job here needs.)
 * On a Linux/cron host, the equivalent is a crontab entry:
 *   0 * * * * php /path/to/custodia/jobs/ocr_scanned_pdfs.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/text_extract.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/reports.php';

const CUSTODIA_OCR_SWEEP_BATCH_SIZE = 20;

$pdo = custodia_db();

$ghostscriptAvailable = custodia_ghostscript_binary() !== null;
$tesseractAvailable = custodia_tesseract_binary() !== null;
if (!$ghostscriptAvailable || !$tesseractAvailable) {
    $missing = [];
    if (!$ghostscriptAvailable) {
        $missing[] = 'Ghostscript';
    }
    if (!$tesseractAvailable) {
        $missing[] = 'Tesseract';
    }
    fwrite(STDERR, 'OCR sweep skipped — ' . implode(' and ', $missing) . ' not found on this machine. Install and re-run; nothing else needs to change.' . "\n");
    exit(0); // not a failure — just nothing this run can do yet
}

$stmt = $pdo->prepare(
    "SELECT dv.id, dv.storage_key, dv.original_filename
     FROM document_versions dv
     WHERE dv.extraction_status = 'NO_TEXT_LAYER'
     ORDER BY dv.uploaded_at ASC
     LIMIT " . CUSTODIA_OCR_SWEEP_BATCH_SIZE
);
$stmt->execute();
$rows = $stmt->fetchAll();

$updated = 0;
$stillEmpty = 0;
$skipped = 0;

foreach ($rows as $row) {
    $ext = strtolower(pathinfo((string) $row['original_filename'], PATHINFO_EXTENSION));
    $path = custodia_storage_path($row['storage_key']);

    if (!is_file($path)) {
        fwrite(STDERR, "Skipping version {$row['id']} — stored file missing at {$path}\n");
        $skipped++;
        continue;
    }

    $text = match ($ext) {
        'pdf' => custodia_ocr_scanned_pdf_text($path),
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff' => custodia_ocr_image_text($path),
        // Not a scannable type this job knows how to retry (shouldn't happen —
        // NO_TEXT_LAYER is only ever set for pdf/image extensions — but skip
        // rather than guess if the data ever says otherwise).
        default => null,
    };

    if ($text === null) {
        $skipped++;
        continue;
    }

    if ($text === '') {
        $stillEmpty++; // OCR ran but genuinely found no text — stays NO_TEXT_LAYER, correctly
        continue;
    }

    if (function_exists('mb_strlen') && mb_strlen($text) > CUSTODIA_EXTRACT_MAX_CHARS) {
        $text = mb_substr($text, 0, CUSTODIA_EXTRACT_MAX_CHARS);
    }

    $pdo->prepare('UPDATE document_versions SET extracted_text = :text, extraction_status = :status WHERE id = :id')
        ->execute(['text' => $text, 'status' => 'DONE', 'id' => $row['id']]);
    $updated++;
}

if (!empty($rows)) {
    try {
        $actor = custodia_reports_default_actor($pdo);
        $pdo->beginTransaction();
        custodia_audit_record($pdo, [
            'actorId' => $actor['id'],
            'actionType' => 'OCR_SWEEP_RUN',
            'entityType' => 'DIGITAL_DOCUMENT',
            'entityId' => 'ocr-sweep', // synthetic id — same convention as verify_audit_chain.php's 'chain'/custodia_audit_export_csv()'s 'bulk-export'
            'ipAddress' => '127.0.0.1',
            'metadata' => ['checked' => count($rows), 'newlySearchable' => $updated, 'stillNoText' => $stillEmpty, 'skipped' => $skipped],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'OCR sweep ran but could not record its audit entry: ' . $e->getMessage() . "\n");
    }
}

echo "OCR sweep: checked " . count($rows) . ", newly searchable {$updated}, still no text {$stillEmpty}, skipped {$skipped}.\n";
