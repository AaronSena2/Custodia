<?php
/**
 * Turns a queued notification into the email that actually gets sent, and
 * decides how much of it the recipient is allowed to see in their inbox.
 *
 * REDACTION IS THE POINT OF THIS FILE. An in-app notification lives inside
 * the audit perimeter: to read it you signed in, RBAC applied, and the view
 * was logged. An email does not — it lands in a mailbox, on a phone, in a
 * backup, and can be forwarded to anyone. For a legal registry that
 * difference matters more than the convenience of a detailed subject line.
 *
 * So the body is rendered against the matter's confidentiality tier as it
 * stands AT SEND TIME (not at enqueue time — a matter can be reclassified
 * in between):
 *
 *   STANDARD              → full detail: matter number, client, the
 *                           notification's own title and body.
 *   RESTRICTED/PRIVILEGED → matter number and a sign-in link. No client
 *                           name, no case title, and no free-text reason,
 *                           which routinely quotes case detail — the
 *                           retention sweep's DESTRUCTION_REVIEW reason
 *                           string in jobs/overdue_sweep.php interpolates
 *                           the matter number and close date directly, and
 *                           an access-request reason is whatever the
 *                           requester typed.
 *
 * Links always point at a page that requires signing in. There is
 * deliberately no tokenised one-click approve/reject: that would be a
 * second, weaker authentication path into exactly the custody and
 * confidential-access decisions the audit trail exists to defend.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/notifications.php';

/**
 * A short human lead-in per notification type, so the email reads as a
 * sentence rather than a bare database row. Anything not listed falls back
 * to the notification's own title, which is already written for humans.
 */
const CUSTODIA_EMAIL_TYPE_LEAD = [
    'CHECK_OUT_REQUESTED'       => 'A colleague has requested a physical file that needs your approval.',
    'CHECK_OUT_APPROVED'        => 'Your request for a physical file was approved.',
    'CHECK_OUT_REJECTED'        => 'Your request for a physical file was declined.',
    'TRANSFER_REQUESTED'        => 'Someone has asked you to hand over a file you currently hold. It stays in your custody until you approve.',
    'TRANSFER_APPROVED'         => 'A file transfer was approved and custody has moved.',
    'TRANSFER_REJECTED'         => 'Your request to take custody of a file was declined.',
    'OVERRIDE_CHECK_IN'         => 'A file that was in your custody has been returned on your behalf by Records Management.',
    'FILE_OVERDUE'              => 'A file in your custody is past its return date.',
    'ACCESS_REQUESTED'          => 'Someone has requested access to a matter and is waiting on your decision.',
    'ACCESS_REQUEST_APPROVED'   => 'Your access request was approved.',
    'ACCESS_REQUEST_DENIED'     => 'Your access request was declined.',
    'ACCESS_GRANTED'            => 'You have been granted access.',
    'DOCUMENT_SHARED'           => 'A document has been shared with you.',
    'GROUP_MATTER_ACCESS_GRANTED' => 'Your practice group was granted access to a matter.',
    'ACCOUNT_LOCKED'            => 'Your Custodia account has been temporarily locked after repeated failed sign-in attempts.',
    'RETENTION_REVIEW_NEEDED'   => 'Matters are due for retention review and need sign-off.',
    'AUDIT_CHAIN_BROKEN'        => 'The scheduled audit-log integrity check has FAILED. This needs investigating now.',
    'THREAT_ALERT'              => 'Unusual activity was detected in the registry.',
    'WEEKLY_REPORT_READY'       => 'This week\'s operations report is ready.',
];

/**
 * Security alerts that ignore a recipient's email preferences. A tamper
 * alarm somebody can switch off is not an alarm.
 */
const CUSTODIA_EMAIL_MANDATORY_TYPES = ['AUDIT_CHAIN_BROKEN', 'THREAT_ALERT', 'ACCOUNT_LOCKED'];

/** Types that must never be held back into a digest — they are time-critical by nature. */
const CUSTODIA_EMAIL_NEVER_DIGEST_TYPES = [
    'AUDIT_CHAIN_BROKEN', 'THREAT_ALERT', 'ACCOUNT_LOCKED',
    'CHECK_OUT_REQUESTED', 'TRANSFER_REQUESTED', 'ACCESS_REQUESTED',
];

/**
 * The confidentiality tier governing an outbox row, resolved fresh at send
 * time. Returns null when the row isn't tied to a matter at all (a weekly
 * report, an account lockout), which is treated as "nothing to redact".
 */
function custodia_email_matter_context(PDO $pdo, ?string $matterId): ?array
{
    if ($matterId === null || $matterId === '') {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT m.id, m.matter_number, m.confidentiality, c.name AS client_name
         FROM matters m JOIN clients c ON c.id = m.client_id WHERE m.id = :id'
    );
    $stmt->execute(['id' => $matterId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function custodia_email_is_restricted_tier(?array $matter): bool
{
    return $matter !== null && in_array($matter['confidentiality'], ['RESTRICTED', 'PRIVILEGED'], true);
}

/**
 * Renders the final email.
 *
 * @param array  $row      an email_outbox row (subject/body_text hold the source notification's title/body)
 * @param ?array $matter   from custodia_email_matter_context(), or null
 * @param string $baseUrl  configured app URL; when blank the email says where to go instead of linking
 * @return array{subject:string,text:string,html:string}
 */
function custodia_email_render(array $row, ?array $matter, string $baseUrl, string $recipientName = ''): array
{
    $type = $row['notification_type'];
    $restricted = custodia_email_is_restricted_tier($matter);
    $lead = CUSTODIA_EMAIL_TYPE_LEAD[$type] ?? null;

    $link = custodia_notification_link([
        'notification_type' => $type,
        'entity_type' => $row['entity_type'],
        'entity_id' => $row['entity_id'],
    ]);
    $url = $baseUrl !== '' ? rtrim($baseUrl, '/') . '/' . ltrim($link, '/') : '';

    if ($restricted) {
        // Matter number only. No client name, no title, no body — the title
        // and body are written for an in-app reader who is already inside
        // the access-controlled surface.
        $subject = 'Custodia: action needed on matter ' . $matter['matter_number'];
        $lines = [
            $recipientName !== '' ? "Hello {$recipientName}," : 'Hello,',
            '',
            $lead ?? 'There is an update waiting for you in Custodia.',
            '',
            'Matter: ' . $matter['matter_number'] . ' (' . $matter['confidentiality'] . ')',
            '',
            'Details are withheld from email because this matter is '
                . $matter['confidentiality'] . '. Sign in to Custodia to see them.',
        ];
    } else {
        $subject = 'Custodia: ' . custodia_mail_strip($row['subject']);
        $lines = [
            $recipientName !== '' ? "Hello {$recipientName}," : 'Hello,',
            '',
            $lead ?? custodia_mail_strip($row['subject']),
            '',
            custodia_mail_strip($row['subject']),
        ];
        if (!empty($row['body_text'])) {
            $lines[] = '';
            $lines[] = custodia_mail_strip($row['body_text']);
        }
        if ($matter !== null) {
            $lines[] = '';
            $lines[] = 'Matter: ' . $matter['matter_number'] . ' — ' . $matter['client_name'];
        }
    }

    $lines[] = '';
    $lines[] = $url !== '' ? 'Open in Custodia: ' . $url : 'Sign in to Custodia to review it.';
    $lines[] = '';
    $lines[] = '—';
    $lines[] = 'This is an automated message from the Custodia legal file registry. Please do not reply.';
    $lines[] = 'To change which emails you receive, open Custodia and visit your notification settings.';

    $text = implode("\n", $lines);

    // Light HTML: a readable wrapper around the same words, not a design.
    // Legal inboxes are full of locked-down clients, so the plain-text part
    // has to stand on its own regardless.
    $htmlBody = '';
    foreach ($lines as $line) {
        $htmlBody .= $line === ''
            ? '<div style="height:10px"></div>'
            : '<div>' . e($line) . '</div>';
    }
    if ($url !== '') {
        $htmlBody .= '<div style="margin-top:18px"><a href="' . e($url)
            . '" style="background:#0d9488;color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none;display:inline-block">Open in Custodia</a></div>';
    }
    $html = '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
        . $htmlBody . '</div>';

    return ['subject' => custodia_mail_truncate($subject, 250), 'text' => $text, 'html' => $html];
}

/** Collapses newlines/control characters so a stored title can't inject header-like content or wreck the layout. */
function custodia_mail_strip(?string $value): string
{
    return trim(preg_replace('/[\r\n\t]+/', ' ', (string) $value) ?? '');
}
