<?php
/**
 * Scheduled entry point for the audit hash-chain integrity check —
 * security review 2026-09-03, finding 4.2: "Verify Chain Integrity" in the
 * Audit Explorer was a click-only admin action with no scheduled
 * equivalent, and the check itself already takes several seconds at
 * current volume (89,851 entries in 30 days). Between manual clicks,
 * tampering — however unlikely, given the hash chain — would go
 * undetected indefinitely.
 *
 * Runs the exact same custodia_audit_verify_chain() the Audit Explorer
 * button calls, records an AUDIT_CHAIN_VERIFIED (or AUDIT_CHAIN_BROKEN)
 * entry either way, and — only when the chain is actually broken —
 * notifies every active SYSTEM_ADMIN/RECORDS_MANAGER so a failure is
 * never silent between someone remembering to look.
 *
 * Same "small CLI script" convention as jobs/weekly_report.php: no
 * arguments, run with `php jobs/verify_audit_chain.php`, safe to re-run
 * (each run just checks the chain again and logs another result).
 *
 * Register with Windows Task Scheduler to run nightly at 2am:
 *   schtasks /create /tn "Custodia Audit Chain Verify" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\custodia-php\jobs\verify_audit_chain.php" /sc daily /st 02:00
 * (adjust the php.exe and htdocs paths to match your actual XAMPP install.)
 * On a Linux/cron host, the equivalent is a crontab entry:
 *   0 2 * * * php /path/to/custodia/jobs/verify_audit_chain.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/notifications.php';

$pdo = custodia_db();

try {
    $result = custodia_audit_verify_chain($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Audit chain verification crashed: ' . $e->getMessage() . "\n");
    exit(1);
}

try {
    $actor = custodia_reports_default_actor($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cannot record result: ' . $e->getMessage() . "\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    custodia_audit_record($pdo, [
        'actorId' => $actor['id'],
        'actionType' => $result['valid'] ? 'AUDIT_CHAIN_VERIFIED' : 'AUDIT_CHAIN_BROKEN',
        'entityType' => 'AUDIT_LOG',
        'entityId' => 'chain', // not a real row id — same "synthetic id for a bulk/system action" convention custodia_audit_export_csv() already uses ('bulk-export')
        'ipAddress' => '127.0.0.1',
        'metadata' => $result,
    ]);

    if (!$result['valid']) {
        $recipients = $pdo->query(
            "SELECT id FROM users WHERE role IN ('SYSTEM_ADMIN', 'RECORDS_MANAGER') AND is_active = 1"
        )->fetchAll(PDO::FETCH_COLUMN);

        custodia_notify_users(
            $pdo,
            $recipients,
            'AUDIT_CHAIN_BROKEN',
            'Audit chain integrity check FAILED',
            "The scheduled integrity check found the audit hash chain broken at entry {$result['brokenAtId']}, "
                . "after {$result['checked']} entries verified clean. Investigate immediately — this may indicate "
                . "the audit_log table was modified outside the application.",
            'AUDIT_LOG',
            null // brokenAtId is an audit_log row id, not a notification-linkable entity the UI resolves — kept in the notification body/audit metadata instead
        );
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed to record chain verification result: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result['valid']) {
    echo "Audit chain verified OK — {$result['checked']} entries checked.\n";
    exit(0);
}

fwrite(STDERR, "AUDIT CHAIN BROKEN at entry {$result['brokenAtId']} (after {$result['checked']} verified entries). Admins notified.\n");
exit(1);
