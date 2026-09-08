<?php
/**
 * Scheduled entry point for the overdue-return and retention-review sweep —
 * security review 2026-09-03, finding 4.5 (and the README's own "Known
 * gaps" list, which already named this exact file). Two things this app
 * only ever checked when someone happened to have the Dashboard open:
 *
 *   1. Overdue physical-file checkouts — already computed correctly by
 *      custodia_list_overdue_files(), just never surfaced to anyone who
 *      wasn't actively looking. This job notifies each file's current
 *      custodian directly (deduped so a still-unread reminder isn't
 *      re-sent every run).
 *   2. Retention-eligible matters — CLOSED matters whose practice area has
 *      a retention policy (trigger_event = 'MATTER_CLOSE') and whose
 *      close_date + retention_years has passed. Before this job, nothing
 *      in the codebase ever created the DESTRUCTION_REVIEW access_request
 *      the dashboard's "pending destruction review" tile and the
 *      Approvals page already know how to show — that tile always read
 *      zero because the request was never generated, at any point, by
 *      anything. This job generates it, once per eligible matter, for a
 *      human (dual sign-off, per the existing approval flow) to actually
 *      decide REVIEW/ARCHIVE/DESTROY.
 *
 * Same "small CLI script" convention as jobs/weekly_report.php and
 * jobs/verify_audit_chain.php: no arguments, safe to re-run — a matter
 * already flagged (PENDING or APPROVED) is never flagged twice, and a
 * custodian already sitting on an unread overdue reminder for a given
 * file doesn't get a second one until they clear the first.
 *
 * Register with Windows Task Scheduler to run hourly:
 *   schtasks /create /tn "Custodia Overdue+Retention Sweep" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\custodia-php\jobs\overdue_sweep.php" /sc hourly /st 00:00
 * (adjust the php.exe and htdocs paths to match your actual XAMPP install.)
 * On a Linux/cron host, the equivalent is a crontab entry:
 *   0 * * * * php /path/to/custodia/jobs/overdue_sweep.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/physical_files.php';
require_once __DIR__ . '/../includes/access_requests.php';
require_once __DIR__ . '/../includes/roles.php';
require_once __DIR__ . '/../includes/retention_policies.php';

const CUSTODIA_SWEEP_IP = '127.0.0.1';

$pdo = custodia_db();

try {
    $actor = custodia_reports_default_actor($pdo); // firm-wide role, so custodia_list_overdue_files() returns the unscoped list
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot run sweep: ' . $e->getMessage() . "\n");
    exit(1);
}

// ── 1. Overdue physical files ──────────────────────────────────────────
$overdueNotified = 0;
$overdueTotal = 0;
try {
    $overdueFiles = custodia_list_overdue_files($pdo, $actor);
    $overdueTotal = count($overdueFiles);

    foreach ($overdueFiles as $file) {
        if (empty($file['custodian_id'])) {
            continue; // no current custodian on record to notify
        }

        // Dedup: don't pile up a fresh reminder every run while an earlier
        // one for this exact file is still sitting unread.
        $existing = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = :uid AND notification_type = 'FILE_OVERDUE'
               AND entity_type = 'PHYSICAL_FILE' AND entity_id = :fid AND read_at IS NULL"
        );
        $existing->execute(['uid' => $file['custodian_id'], 'fid' => $file['id']]);
        if ((int) $existing->fetchColumn() > 0) {
            continue;
        }

        $dueBack = new DateTimeImmutable($file['due_back_at']);
        $daysOverdue = max(1, (new DateTimeImmutable('now'))->diff($dueBack)->days);

        custodia_notify_user(
            $pdo,
            $file['custodian_id'],
            'FILE_OVERDUE',
            'Checked-out file is overdue',
            "{$file['barcode']} — {$file['jacket_label']} ({$file['matter_number']}) was due back "
                . "{$daysOverdue} day(s) ago. Please check it in or contact Records Management.",
            'PHYSICAL_FILE',
            $file['id']
        );
        $overdueNotified++;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Overdue-file check failed: ' . $e->getMessage() . "\n");
}

$pdo->beginTransaction();
try {
    custodia_audit_record($pdo, [
        'actorId' => $actor['id'], 'actionType' => 'OVERDUE_SWEEP_RUN', 'entityType' => 'PHYSICAL_FILE', 'entityId' => 'sweep',
        'ipAddress' => CUSTODIA_SWEEP_IP, 'metadata' => ['overdueTotal' => $overdueTotal, 'custodiansNotified' => $overdueNotified],
        'chained' => false, // routine automated bookkeeping, not a security-relevant event — see finding 4.3
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed to record overdue-sweep audit entry: ' . $e->getMessage() . "\n");
}

// ── 2. Retention-eligible matters ──────────────────────────────────────
$retentionFlagged = 0;
try {
    // Only MATTER_CLOSE is a trigger this data model can actually evaluate
    // (matters.close_date is the only date a policy can measure from) — a
    // policy configured with any other trigger_event is skipped rather than
    // guessed at.
    // Security review 2026-09-03, finding 2.1: only 2 of 14 real practice
    // groups had a specific policy, so most CLOSED matters were invisible
    // to this whole query before — an exact-match JOIN against a policy
    // that usually didn't exist. Every matter now also matches against the
    // firm-wide default (CUSTODIA_RETENTION_DEFAULT_PRACTICE_AREA), used
    // only when no policy names that matter's own practice area.
    $stmt = $pdo->prepare(
        "SELECT m.id, m.matter_number, m.practice_area, m.close_date,
                COALESCE(sp.retention_years, dp.retention_years) AS retention_years,
                COALESCE(sp.action, dp.action) AS action,
                (sp.id IS NULL) AS used_default
         FROM matters m
         LEFT JOIN retention_policies sp
           ON sp.practice_area = m.practice_area AND sp.trigger_event = 'MATTER_CLOSE'
         LEFT JOIN retention_policies dp
           ON dp.practice_area = :defaultKey AND dp.trigger_event = 'MATTER_CLOSE'
         WHERE m.status = 'CLOSED'
           AND m.close_date IS NOT NULL
           AND (sp.id IS NOT NULL OR dp.id IS NOT NULL)
           AND DATE_ADD(m.close_date, INTERVAL COALESCE(sp.retention_years, dp.retention_years) YEAR) <= NOW()
           AND NOT EXISTS (
             SELECT 1 FROM access_requests ar
             WHERE ar.entity_type = 'MATTER' AND ar.entity_id = m.id
               AND ar.request_type = 'DESTRUCTION_REVIEW' AND ar.status IN ('PENDING', 'APPROVED')
           )"
    );
    $stmt->execute(['defaultKey' => CUSTODIA_RETENTION_DEFAULT_PRACTICE_AREA]);
    $eligible = $stmt->fetchAll();

    foreach ($eligible as $m) {
        $policyLabel = $m['used_default']
            ? "firm-wide default retention policy ({$m['retention_years']}-year {$m['action']}) — {$m['practice_area']} has no policy of its own"
            : "{$m['practice_area']} retention policy ({$m['retention_years']}-year {$m['action']})";
        $reason = "Automated retention sweep: matter {$m['matter_number']} closed "
            . custodia_format_datetime($m['close_date']) . ", past its {$policyLabel}. Needs sign-off before "
            . strtolower($m['action']) . '.';
        custodia_create_access_request($pdo, $actor, 'MATTER', $m['id'], 'DESTRUCTION_REVIEW', $reason, CUSTODIA_SWEEP_IP);
        $retentionFlagged++;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Retention sweep failed: ' . $e->getMessage() . "\n");
}

if ($retentionFlagged > 0) {
    $recipients = $pdo->query(
        "SELECT id FROM users WHERE role IN ('SYSTEM_ADMIN', 'RECORDS_MANAGER') AND is_active = 1"
    )->fetchAll(PDO::FETCH_COLUMN);
    custodia_notify_users(
        $pdo, $recipients, 'RETENTION_REVIEW_NEEDED',
        $retentionFlagged === 1 ? '1 matter is due for retention review' : "{$retentionFlagged} matters are due for retention review",
        'Generated by the automated retention sweep — see Approvals for the pending destruction reviews.',
        null, null
    );
}

$pdo->beginTransaction();
try {
    custodia_audit_record($pdo, [
        'actorId' => $actor['id'], 'actionType' => 'RETENTION_SWEEP_RUN', 'entityType' => 'MATTER', 'entityId' => 'sweep',
        'ipAddress' => CUSTODIA_SWEEP_IP, 'metadata' => ['matterFlagged' => $retentionFlagged],
        'chained' => false,
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed to record retention-sweep audit entry: ' . $e->getMessage() . "\n");
}

echo "Overdue sweep: {$overdueTotal} overdue file(s), {$overdueNotified} custodian(s) notified.\n";
echo "Retention sweep: {$retentionFlagged} matter(s) newly flagged for destruction review.\n";
