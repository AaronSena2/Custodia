<?php
/**
 * Scheduled job — drains the email_outbox and sends through Microsoft Graph.
 *
 * Register with Windows Task Scheduler (adjust the path to wherever the app
 * actually lives on the machine):
 *
 *   schtasks /create /tn "Custodia Send Email Queue" ^
 *     /tr "C:\xampp\php\php.exe C:\path\to\Registry System\jobs\send_email_queue.php" ^
 *     /sc minute /mo 5
 *
 * cron equivalent, for a future non-Windows host:
 *   *_/5 * * * * /usr/bin/php /srv/custodia/jobs/send_email_queue.php
 *
 * Set it to "run whether user is logged on or not", per DEPLOYMENT.md.
 * Safe to re-run, takes no arguments, and is idempotent: a row only leaves
 * PENDING once, and a run with nothing to do exits silently.
 *
 * WHY A JOB AND NOT AN INLINE SEND. Every custodia_notify_user() call sits
 * inside a transaction that also carries a custody movement or an access
 * decision plus its audit entry. Sending inline would put an HTTPS round
 * trip to Microsoft inside that transaction, so a mail-service outage or a
 * slow token endpoint could roll back a completed custody transfer. The
 * outbox breaks that coupling: enqueueing is a local INSERT that commits
 * with the movement, and everything that can fail slowly happens here.
 *
 * WHAT IS RE-CHECKED HERE RATHER THAN AT ENQUEUE. Up to five minutes pass
 * between the two, and in that window an ethical wall can be raised, a
 * grant revoked, or a matter reclassified. So this job re-resolves the
 * matter's confidentiality tier (driving redaction) and re-runs the app's
 * own access check against the recipient before sending. A row that no
 * longer passes is marked SKIPPED with a reason, not sent.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/app_settings.php';
require_once __DIR__ . '/../includes/graph_mailer.php';
require_once __DIR__ . '/../includes/email_queue.php';
require_once __DIR__ . '/../includes/email_templates.php';
require_once __DIR__ . '/../includes/matter_access.php';
require_once __DIR__ . '/../includes/reports.php';

/** How many messages one run will attempt. Exchange Online throttles a mailbox at roughly 30 messages/minute, so a 5-minute cadence has plenty of headroom here. */
const CUSTODIA_EMAIL_BATCH_SIZE = 25;
/** Give up after this many attempts and leave the row FAILED for an admin to see. */
const CUSTODIA_EMAIL_MAX_ATTEMPTS = 5;
const CUSTODIA_EMAIL_JOB_IP = '127.0.0.1';

$pdo = custodia_db();

if (!custodia_email_tables_present($pdo)) {
    fwrite(STDERR, "email_outbox/app_settings not found — run sql/upgrade_022_email_notifications.sql first.\n");
    exit(1);
}

$settings = custodia_mail_settings($pdo);
if (!$settings['enabled']) {
    // Not an error: the kill switch is off, which is the shipped default.
    exit(0);
}
if (!custodia_mail_is_configured($settings)) {
    fwrite(STDERR, "Email is switched on but not fully configured (tenant, client ID, secret, sender). See Admin → Email Settings.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    "SELECT * FROM email_outbox
     WHERE status = 'PENDING' AND (next_attempt_at IS NULL OR next_attempt_at <= NOW(6))
     ORDER BY created_at ASC LIMIT " . CUSTODIA_EMAIL_BATCH_SIZE
);
$stmt->execute();
$rows = $stmt->fetchAll();

$sent = 0;
$skipped = 0;
$failed = 0;

foreach ($rows as $row) {
    try {
        $recipient = null;
        if (!empty($row['user_id'])) {
            $rs = $pdo->prepare('SELECT id, full_name, email, role, is_active, email_notifications_enabled, email_digest_only FROM users WHERE id = :id');
            $rs->execute(['id' => $row['user_id']]);
            $recipient = $rs->fetch() ?: null;
        }

        $skipReason = custodia_email_skip_reason($pdo, $row, $recipient, $settings);
        if ($skipReason !== null) {
            custodia_email_mark($pdo, $row['id'], 'SKIPPED', $skipReason);
            $skipped++;
            continue;
        }

        $matter = custodia_email_matter_context($pdo, $row['matter_id']);
        $rendered = custodia_email_render(
            $row,
            $matter,
            (string) ($settings['baseUrl'] ?? ''),
            $recipient['full_name'] ?? ''
        );

        // The redirect is the safety catch for a first run against real
        // data: every message goes to one address instead of to actual
        // partners and clients' contacts. The intended recipient is kept in
        // the subject so a test run is still legible.
        $toEmail = $row['to_email'];
        $subject = $rendered['subject'];
        if (!empty($settings['redirectTo'])) {
            $subject = '[test → ' . $toEmail . '] ' . $subject;
            $toEmail = $settings['redirectTo'];
        }

        $result = custodia_graph_send_mail($pdo, $settings, $toEmail, $subject, $rendered['text'], $rendered['html']);

        if ($result['ok']) {
            // Overwrite the source text with what was actually sent, so the
            // outbox is an accurate delivery record rather than a record of
            // the notification that prompted it.
            $pdo->prepare(
                "UPDATE email_outbox
                 SET status = 'SENT', sent_at = NOW(6), attempts = attempts + 1, last_error = NULL,
                     subject = :subject, body_text = :text, body_html = :html
                 WHERE id = :id"
            )->execute([
                'subject' => $subject, 'text' => $rendered['text'], 'html' => $rendered['html'], 'id' => $row['id'],
            ]);
            $sent++;
            continue;
        }

        $attempts = (int) $row['attempts'] + 1;
        if (!$result['retryable'] || $attempts >= CUSTODIA_EMAIL_MAX_ATTEMPTS) {
            $pdo->prepare("UPDATE email_outbox SET status = 'FAILED', attempts = :a, last_error = :e WHERE id = :id")
                ->execute(['a' => $attempts, 'e' => $result['error'], 'id' => $row['id']]);
            $failed++;
        } else {
            // Exponential backoff: 2, 4, 8, 16 minutes.
            // Interpolated rather than bound: this PDO runs with
            // ATTR_EMULATE_PREPARES => false, and MySQL will not accept a
            // placeholder inside an INTERVAL expression. The value is a
            // computed integer, never user input.
            $delayMinutes = (int) (2 ** $attempts);
            $pdo->prepare(
                "UPDATE email_outbox SET attempts = :a, last_error = :e,
                 next_attempt_at = DATE_ADD(NOW(6), INTERVAL {$delayMinutes} MINUTE) WHERE id = :id"
            )->execute(['a' => $attempts, 'e' => $result['error'], 'id' => $row['id']]);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Outbox row ' . $row['id'] . ' errored: ' . $e->getMessage() . "\n");
        $failed++;
    }
}

// One audit entry per run. Unchained: routine automated bookkeeping, not a
// security-relevant event — the same call the existing sweeps make (review
// finding 4.3).
if ($sent > 0 || $skipped > 0 || $failed > 0) {
    $actor = custodia_reports_default_actor($pdo);
    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'EMAIL_QUEUE_RUN', 'entityType' => 'NOTIFICATION', 'entityId' => 'email-queue',
            'ipAddress' => CUSTODIA_EMAIL_JOB_IP,
            'metadata' => ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed, 'considered' => count($rows)],
            'chained' => false,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, 'Failed to record email-queue audit entry: ' . $e->getMessage() . "\n");
    }
}

echo "Email queue: {$sent} sent, {$skipped} skipped, {$failed} failed (of " . count($rows) . " considered).\n";

/**
 * Why this row must not be sent, or null if it may be. Everything checked
 * here is something that can have changed since the row was queued.
 */
function custodia_email_skip_reason(PDO $pdo, array $row, ?array $recipient, array $settings): ?string
{
    if ($row['user_id'] !== null && $recipient === null) {
        return 'Recipient account no longer exists';
    }
    if ($recipient !== null) {
        $pref = custodia_email_pref_allows($recipient, $row['notification_type']);
        if (!$pref['send']) {
            return $pref['reason'] ?? 'Recipient preferences';
        }
        if (custodia_email_sent_last_hour($pdo, $recipient['id']) >= $settings['maxPerHour']
            && !in_array($row['notification_type'], CUSTODIA_EMAIL_MANDATORY_TYPES, true)) {
            return 'Hourly email limit reached for this recipient';
        }
    }

    $to = $row['to_email'];
    if (!custodia_mail_is_deliverable($to)) {
        return 'Address is not deliverable (' . $to . ')';
    }

    // The one that actually matters: an ethical wall raised, or access
    // revoked, between enqueue and now. custodia_assert_matter_access() is
    // the app's own three-layer check, so this can't drift from what the UI
    // enforces.
    if (!empty($row['matter_id']) && $recipient !== null) {
        try {
            custodia_assert_matter_access($pdo, $recipient, $row['matter_id']);
        } catch (Throwable $e) {
            return 'Recipient no longer has access to this matter';
        }
    }

    return null;
}

function custodia_email_mark(PDO $pdo, string $id, string $status, ?string $skipReason): void
{
    $pdo->prepare("UPDATE email_outbox SET status = :s, skip_reason = :r, attempts = attempts + 1 WHERE id = :id")
        ->execute(['s' => $status, 'r' => $skipReason, 'id' => $id]);
}
