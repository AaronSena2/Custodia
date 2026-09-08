<?php
/**
 * Scheduled entry point for purging old, already-read notifications —
 * user request (2026-09-06), right after notifications.php (the full
 * notification inbox) shipped: a per-user notification history with no
 * cleanup would otherwise grow forever. Deletes every notification whose
 * read_at is more than CUSTODIA_NOTIFICATION_PURGE_AFTER_MONTHS old (see
 * includes/notifications.php) — unread notifications are never touched, no
 * matter how old, since only something the user has actually seen is safe
 * to discard.
 *
 * Same "small CLI script" convention as jobs/verify_audit_chain.php and
 * jobs/overdue_sweep.php: no arguments, safe to re-run any time — a run
 * that finds nothing past the cutoff just deletes 0 rows.
 *
 * Purely routine housekeeping, not a security-relevant event, so its audit
 * entry is unchained (NOTIFICATIONS_PURGED — see finding 4.3's 'chained'
 * doc comment in includes/audit.php) and nobody is notified about it; it's
 * visible in the Audit Log for anyone who wants to confirm it's running.
 *
 * Register with Windows Task Scheduler to run nightly at 3am (any daily-or-
 * looser cadence is plenty for a monthly-scale retention window):
 *   schtasks /create /tn "Custodia Purge Read Notifications" /tr "C:\xampp\php\php.exe C:\path\to\Registry System\jobs\purge_read_notifications.php" /sc daily /st 03:00
 * Cron equivalent: 0 3 * * * php /path/to/Registry System/jobs/purge_read_notifications.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/notifications.php';

const CUSTODIA_SWEEP_IP = '127.0.0.1';

$pdo = custodia_db();

try {
    $actor = custodia_reports_default_actor($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot run purge: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $deleted = custodia_purge_read_notifications($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Notification purge failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    custodia_audit_record($pdo, [
        'actorId' => $actor['id'], 'actionType' => 'NOTIFICATIONS_PURGED', 'entityType' => 'NOTIFICATION', 'entityId' => 'purge',
        'ipAddress' => CUSTODIA_SWEEP_IP, 'metadata' => ['deletedCount' => $deleted, 'olderThanMonths' => CUSTODIA_NOTIFICATION_PURGE_AFTER_MONTHS],
        'chained' => false, // routine automated bookkeeping, not a security-relevant event — see finding 4.3
    ]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed to record purge audit entry: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Notification purge complete: {$deleted} read notification(s) older than " . CUSTODIA_NOTIFICATION_PURGE_AFTER_MONTHS . " month(s) deleted.\n";
