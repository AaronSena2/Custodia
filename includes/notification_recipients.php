<?php
/**
 * Who to tell about a pending approval.
 *
 * This is the inverse of the queries the Approvals page already runs, and
 * it is genuinely a different question. custodia_list_pending_for_approver()
 * and custodia_list_pending_access_requests_for_approver() are PULL queries:
 * given a user, what is waiting for them? Notifying is PUSH: given a request,
 * who should hear about it?
 *
 * Inverting the pull queries naively would be wrong — and expensive.
 * approve_custody_movements and decide_access_requests are FIRM-WIDE
 * permissions, so "everyone who could approve this" is every holder of that
 * permission, for every matter in the system. On this install that would
 * mean notifying (and emailing) the same handful of people about check-out
 * requests across ~4,273 matters.
 *
 * So the rule here is narrower than the permission model on purpose:
 *
 *   1. the matter's own incharge (matters.managing_partner_id), who can
 *      always act on their own matter regardless of role — the data-driven
 *      floor established by security review finding 2.2;
 *   2. failing that, active Records Managers.
 *
 * Nobody loses any authority: every firm-wide approver still sees the full
 * queue in the Approvals inbox exactly as before. This decides only who gets
 * actively pinged, and it deliberately errs towards the person closest to
 * the matter.
 *
 * Undeliverable placeholder incharges are treated as "no incharge" and fall
 * through to rule 2 — see CUSTODIA_UNDELIVERABLE_DOMAIN_SUFFIXES in
 * includes/graph_mailer.php for why that matters so much on this install.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/graph_mailer.php';

/**
 * @return string[] user ids to notify about something needing approval on this matter
 */
function custodia_matter_approver_ids(PDO $pdo, string $matterId, ?string $excludeUserId = null): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.email FROM matters m
         JOIN users u ON u.id = m.managing_partner_id
         WHERE m.id = :id AND u.is_active = 1'
    );
    $stmt->execute(['id' => $matterId]);
    $incharge = $stmt->fetch();

    $ids = [];
    if ($incharge) {
        // The incharge always gets the in-app notification, placeholder or
        // not — the inbox costs nothing and the row is a real user.
        $ids[] = $incharge['id'];
    }

    // Fall through to Records Managers when there is no incharge at all, or
    // when the incharge is one of the import placeholders that can never
    // receive mail. Without this, a request on any of the ~4,273 imported
    // matters would be "notified" to an address that hard-bounces and to
    // nobody who could actually act on it.
    if (!$incharge || !custodia_mail_is_deliverable($incharge['email'])) {
        $ids = array_merge($ids, $pdo->query(
            "SELECT id FROM users WHERE role = 'RECORDS_MANAGER' AND is_active = 1"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    if ($excludeUserId !== null) {
        $ids = array_values(array_filter($ids, static fn ($id) => $id !== $excludeUserId));
    }
    return array_values(array_unique($ids));
}

/** Active System Administrators and Records Managers — the standing recipients for security-relevant events. */
function custodia_security_recipient_ids(PDO $pdo): array
{
    return $pdo->query(
        "SELECT id FROM users WHERE role IN ('SYSTEM_ADMIN', 'RECORDS_MANAGER') AND is_active = 1"
    )->fetchAll(PDO::FETCH_COLUMN);
}

/** A short "PF-000482912 — Smith v Jones (SL/12345)" label for a physical file, for notification bodies. */
function custodia_physical_file_label(PDO $pdo, string $fileId): string
{
    $stmt = $pdo->prepare(
        'SELECT pf.barcode, pf.jacket_label, m.matter_number FROM physical_files pf
         JOIN matters m ON m.id = pf.matter_id WHERE pf.id = :id'
    );
    $stmt->execute(['id' => $fileId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'a physical file';
    }
    return trim($row['barcode'] . ' — ' . $row['jacket_label'] . ' (' . $row['matter_number'] . ')');
}
