<?php
/**
 * Enqueueing side of the email feature: deciding whether a notification
 * should also become an email, and writing the outbox row.
 *
 * This is called from custodia_notify_user() — one chokepoint, the same
 * approach that fixed the missing security headers under review finding
 * 1.5, and for the same reason: a new notification site added later gets
 * email automatically instead of quietly not getting it.
 *
 * TWO RULES THIS FILE EXISTS TO KEEP:
 *
 * 1. It must never throw. Every caller of custodia_notify_user() is inside
 *    a transaction that also carries a custody movement or an access
 *    decision and its audit entry. If email enqueueing fails — the
 *    migration hasn't been run, a column is missing, anything — the custody
 *    movement must still commit. Everything here is wrapped accordingly and
 *    degrades to "no email".
 *
 * 2. It must never talk to the network. Enqueue only. Microsoft is
 *    contacted by jobs/send_email_queue.php, outside any transaction.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/app_settings.php';
require_once __DIR__ . '/graph_mailer.php';
require_once __DIR__ . '/email_templates.php';

/**
 * Resolves the matter a notification concerns, for the send-time
 * confidentiality and ethical-wall checks. Stored on the row so the queue
 * job doesn't have to re-resolve four entity types.
 */
function custodia_email_resolve_matter_id(PDO $pdo, ?string $entityType, ?string $entityId): ?string
{
    if ($entityType === null || $entityId === null || $entityId === '') {
        return null;
    }
    try {
        if ($entityType === 'MATTER') {
            return $entityId;
        }
        if ($entityType === 'PHYSICAL_FILE') {
            $stmt = $pdo->prepare('SELECT matter_id FROM physical_files WHERE id = :id');
        } elseif ($entityType === 'DIGITAL_DOCUMENT') {
            $stmt = $pdo->prepare('SELECT matter_id FROM digital_documents WHERE id = :id');
        } else {
            return null;
        }
        $stmt->execute(['id' => $entityId]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string) $value : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Whether this recipient's preferences allow an immediate email of this
 * type. Mandatory security alerts ignore preferences entirely.
 *
 * @return array{send:bool,digest:bool,reason:?string}
 */
function custodia_email_pref_allows(array $recipient, string $notificationType): array
{
    if (in_array($notificationType, CUSTODIA_EMAIL_MANDATORY_TYPES, true)) {
        return ['send' => true, 'digest' => false, 'reason' => null];
    }
    if (empty($recipient['is_active'])) {
        return ['send' => false, 'digest' => false, 'reason' => 'Recipient account is inactive'];
    }
    if (!(bool) ($recipient['email_notifications_enabled'] ?? 1)) {
        return ['send' => false, 'digest' => false, 'reason' => 'Recipient has email notifications switched off'];
    }
    if ((bool) ($recipient['email_digest_only'] ?? 0)
        && !in_array($notificationType, CUSTODIA_EMAIL_NEVER_DIGEST_TYPES, true)) {
        return ['send' => true, 'digest' => true, 'reason' => null];
    }
    return ['send' => true, 'digest' => false, 'reason' => null];
}

/**
 * Queues an email for a notification that has just been written. Called
 * from custodia_notify_user(), inside that caller's transaction.
 *
 * Returns silently in every "shouldn't send" case rather than signalling —
 * an unsent email is never worth failing a custody transfer over.
 */
function custodia_email_enqueue_for_notification(
    PDO $pdo,
    string $userId,
    string $notificationType,
    string $title,
    ?string $body,
    ?string $entityType,
    ?string $entityId
): void {
    try {
        if (!custodia_email_tables_present($pdo)) {
            return; // upgrade_022 not run yet — notifications still work
        }

        $settings = custodia_mail_settings($pdo);
        if (!$settings['enabled'] || !custodia_mail_is_configured($settings)) {
            return;
        }

        $stmt = $pdo->prepare('SELECT id, full_name, email, is_active, email_notifications_enabled, email_digest_only FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $recipient = $stmt->fetch();
        if (!$recipient) {
            return;
        }

        $pref = custodia_email_pref_allows($recipient, $notificationType);
        if (!$pref['send']) {
            return;
        }
        if (!custodia_mail_is_deliverable($recipient['email'])) {
            return; // placeholder/import accounts — see CUSTODIA_UNDELIVERABLE_DOMAIN_SUFFIXES
        }

        // A digest row is queued with next_attempt_at set to the next digest
        // run, so it simply isn't picked up by the immediate drain. The
        // digest job (a later pass) collects them; until then they sit
        // visible in the outbox rather than being silently dropped.
        $nextAttempt = $pref['digest']
            ? (new DateTimeImmutable('tomorrow 07:00'))->format('Y-m-d H:i:s.u')
            : null;

        $pdo->prepare(
            'INSERT INTO email_outbox
             (id, user_id, to_email, subject, body_text, notification_type, entity_type, entity_id, matter_id, next_attempt_at)
             VALUES (:id, :uid, :to, :subject, :body, :type, :etype, :eid, :mid, :next)'
        )->execute([
            'id' => custodia_uuid(),
            'uid' => $recipient['id'],
            'to' => $recipient['email'],
            // subject/body_text hold the SOURCE notification text at this
            // point. The queue job re-renders both through
            // custodia_email_render() at send time (so redaction reflects
            // the matter's tier then, not now) and writes the final version
            // back over these columns, leaving the row an accurate record
            // of what was actually sent.
            'subject' => custodia_mail_truncate($title, 250),
            'body' => (string) $body,
            'type' => $notificationType,
            'etype' => $entityType,
            'eid' => $entityId,
            'mid' => custodia_email_resolve_matter_id($pdo, $entityType, $entityId),
            'next' => $nextAttempt,
        ]);
    } catch (Throwable $e) {
        // Deliberately swallowed — see rule 1 in the file docblock. The
        // notification itself, and whatever state change it accompanies,
        // must still commit.
        error_log('[custodia] email enqueue skipped: ' . $e->getMessage());
    }
}

/**
 * How many emails this user has actually been sent in the last hour —
 * the cap that stops a bulk import or a busy sweep turning into a flood.
 */
function custodia_email_sent_last_hour(PDO $pdo, string $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM email_outbox
         WHERE user_id = :uid AND status = 'SENT' AND sent_at > DATE_SUB(NOW(6), INTERVAL 1 HOUR)"
    );
    $stmt->execute(['uid' => $userId]);
    return (int) $stmt->fetchColumn();
}
