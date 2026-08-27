<?php
/**
 * Persisted notification inbox — fed by the access-grant flows in
 * includes/access_requests.php and includes/practice_groups.php, each of
 * which calls custodia_notify_user()/custodia_notify_users() right
 * alongside their existing custodia_audit_record() call, in the same
 * transaction. Deliberately separate from audit_log: notifications are a
 * mutable, per-user "have you seen this" inbox (read_at gets updated in
 * place), not part of the hash-chained, append-only security audit trail.
 */

require_once __DIR__ . '/db.php';

function custodia_notify_user(PDO $pdo, string $userId, string $type, string $title, ?string $body, ?string $entityType = null, ?string $entityId = null): void
{
    $pdo->prepare(
        'INSERT INTO notifications (id, user_id, notification_type, title, body, entity_type, entity_id)
         VALUES (:id, :uid, :type, :title, :body, :etype, :eid)'
    )->execute([
        'id' => custodia_uuid(), 'uid' => $userId, 'type' => $type, 'title' => $title,
        'body' => $body, 'etype' => $entityType, 'eid' => $entityId,
    ]);
}

/** @param string[] $userIds */
function custodia_notify_users(PDO $pdo, array $userIds, string $type, string $title, ?string $body, ?string $entityType = null, ?string $entityId = null): void
{
    foreach ($userIds as $userId) {
        custodia_notify_user($pdo, $userId, $type, $title, $body, $entityType, $entityId);
    }
}

function custodia_list_notifications_for_user(PDO $pdo, string $userId, int $limit = 20): array
{
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim');
    $stmt->bindValue('uid', $userId);
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function custodia_count_unread_notifications(PDO $pdo, string $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL');
    $stmt->execute(['uid' => $userId]);
    return (int) $stmt->fetchColumn();
}

/** Ownership-checked — a user can only mark their own notification read. Silently no-ops if it's not theirs or already read. */
function custodia_mark_notification_read(PDO $pdo, string $userId, string $notificationId): void
{
    $pdo->prepare('UPDATE notifications SET read_at = NOW(6) WHERE id = :id AND user_id = :uid AND read_at IS NULL')
        ->execute(['id' => $notificationId, 'uid' => $userId]);
}

function custodia_mark_all_notifications_read(PDO $pdo, string $userId): void
{
    $pdo->prepare('UPDATE notifications SET read_at = NOW(6) WHERE user_id = :uid AND read_at IS NULL')
        ->execute(['uid' => $userId]);
}
