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
require_once __DIR__ . '/helpers.php';

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

/**
 * Full, paginated notification inbox for notifications.php — unlike the
 * sidebar bell's custodia_list_notifications_for_user() (a fixed-size
 * "most recent N" list meant to stay small and fast for every page load),
 * this does real SQL LIMIT/OFFSET pagination the same way
 * custodia_audit_list() does for the audit log, since a long-lived
 * account's notification history is unbounded. $unreadOnly filters to
 * read_at IS NULL.
 *
 * @return array{entries: array, total: int, page: int, pageSize: int}
 */
function custodia_list_notifications_for_user_paginated(PDO $pdo, string $userId, int $page, int $pageSize, bool $unreadOnly = false): array
{
    $where = 'user_id = :uid' . ($unreadOnly ? ' AND read_at IS NULL' : '');

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE {$where}");
    $countStmt->execute(['uid' => $userId]);
    $total = (int) $countStmt->fetchColumn();

    $offset = max(0, ($page - 1) * $pageSize);
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC LIMIT :lim OFFSET :off");
    $stmt->bindValue('uid', $userId);
    $stmt->bindValue('lim', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue('off', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return ['entries' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pageSize' => $pageSize];
}

/**
 * Where clicking a notification should go — shared by the sidebar bell
 * dropdown (includes/layout_header.php) and the full notifications.php
 * inbox, so the two never drift out of sync on how each entity_type is
 * resolved. Falls back to the dashboard for a notification with no
 * entity_id, or an entity_type this app doesn't know how to link to yet.
 */
function custodia_notification_link(array $notification): string
{
    if (!empty($notification['entity_id'])) {
        if ($notification['entity_type'] === 'MATTER') {
            return 'matter.php?id=' . urlencode($notification['entity_id']);
        }
        if ($notification['entity_type'] === 'DIGITAL_DOCUMENT') {
            return 'shared_with_me.php';
        }
        if ($notification['entity_type'] === 'SYSTEM_REPORT') {
            return 'reports.php?id=' . urlencode($notification['entity_id']);
        }
    }
    return 'dashboard.php';
}

function custodia_count_unread_notifications(PDO $pdo, string $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL');
    $stmt->execute(['uid' => $userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * JSON-ready shape of the sidebar bell dropdown — same data
 * includes/layout_header.php uses for the initial server-rendered markup,
 * reused by actions/list_recent_notifications.php so the polling loop in
 * that file's <script> block (see custodiaPollNotifications() in app.js)
 * can redraw the dropdown from the exact same source of truth instead of
 * re-implementing custodia_notification_link()'s routing in JS.
 */
function custodia_sidebar_notifications_payload(PDO $pdo, string $userId, int $limit = 20): array
{
    $notifications = custodia_list_notifications_for_user($pdo, $userId, $limit);
    return [
        'unreadCount' => custodia_count_unread_notifications($pdo, $userId),
        'notifications' => array_map(static fn (array $n) => [
            'id' => $n['id'],
            'title' => $n['title'],
            'body' => $n['body'],
            'createdAt' => custodia_format_date($n['created_at']),
            'isUnread' => empty($n['read_at']),
            'href' => custodia_notification_link($n),
        ], $notifications),
    ];
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

/** How long a READ notification sticks around before jobs/purge_read_notifications.php deletes it. Unread notifications are never purged, however old. */
const CUSTODIA_NOTIFICATION_PURGE_AFTER_MONTHS = 1;

/**
 * Deletes every notification whose read_at is at least
 * CUSTODIA_NOTIFICATION_PURGE_AFTER_MONTHS old — i.e. read notifications
 * older than a month, per explicit firm decision (2026-09-06). Unread
 * notifications are left alone no matter how old, since read_at IS NULL
 * never satisfies the cutoff — only something the user has actually seen
 * is safe to discard. Returns the number of rows deleted.
 */
function custodia_purge_read_notifications(PDO $pdo, int $months = CUSTODIA_NOTIFICATION_PURGE_AFTER_MONTHS): int
{
    $stmt = $pdo->prepare(
        'DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at <= DATE_SUB(NOW(6), INTERVAL :months MONTH)'
    );
    $stmt->bindValue('months', $months, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}
