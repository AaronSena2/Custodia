<?php
/**
 * Full notification inbox — the sidebar bell (includes/layout_header.php)
 * only ever shows the most recent 20, with no way to page back further or
 * filter down to just what's unread. This is that missing "see everything"
 * page, reached via the bell dropdown's "See all notifications" link.
 *
 * Deliberately its own top-level page rather than a tab bolted onto
 * dashboard.php or admin.php — a notification inbox belongs to the signed-in
 * user personally (like Shared With Me), not to a role-gated admin area or
 * the firm-wide dashboard.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/listing.php';

$user = custodia_require_login();
$pdo = custodia_db();

$unreadOnly = ($_GET['filter'] ?? '') === 'unread';
$params = custodia_listing_params(['created_at'], 'created_at', 'DESC', 25);

$result = custodia_list_notifications_for_user_paginated($pdo, $user['id'], $params['page'], $params['pageSize'], $unreadOnly);
$result['totalPages'] = max(1, (int) ceil($result['total'] / $result['pageSize']));

$unreadCount = custodia_count_unread_notifications($pdo, $user['id']);

$pageTitle = 'Notifications';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Notifications</div>
    <div class="page-subtitle">Everything you've been notified about — access grants, approvals, and other account activity.</div>
  </div>
  <?php if ($unreadCount > 0): ?>
    <button type="button" class="btn btn-outline-secondary" onclick="custodiaMarkAllNotificationsRead()">Mark all read (<?= (int) $unreadCount ?>)</button>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="btn-group btn-group-sm" role="group">
      <a href="?<?= e(custodia_query_with(['filter' => null, 'page' => 1])) ?>" class="btn <?= $unreadOnly ? 'btn-outline-secondary' : 'btn-secondary' ?>">All</a>
      <a href="?<?= e(custodia_query_with(['filter' => 'unread', 'page' => 1])) ?>" class="btn <?= $unreadOnly ? 'btn-secondary' : 'btn-outline-secondary' ?>">Unread<?= $unreadCount > 0 ? ' (' . (int) $unreadCount . ')' : '' ?></a>
    </div>
  </div>
  <div class="card-body p-0">
    <?php if (empty($result['entries'])): ?>
      <div class="text-center text-muted py-5"><?= $unreadOnly ? "You're all caught up — nothing unread." : 'Nothing here yet.' ?></div>
    <?php else: ?>
      <?php foreach ($result['entries'] as $n): ?>
        <?php $notifHref = custodia_notification_link($n); ?>
        <a href="<?= e($notifHref) ?>"
           class="notification-row d-block text-decoration-none text-body px-3 py-3 border-bottom <?= $n['read_at'] ? '' : 'unread' ?>"
           onclick="return custodiaOpenNotification(event, '<?= e($n['id']) ?>', '<?= e($notifHref) ?>')">
          <div class="d-flex justify-content-between align-items-start gap-3">
            <div>
              <div class="fw-semibold"><?= e($n['title']) ?><?= $n['read_at'] ? '' : ' <span class="badge text-bg-primary ms-1">new</span>' ?></div>
              <?php if ($n['body']): ?><div class="small text-muted mt-1"><?= e($n['body']) ?></div><?php endif; ?>
            </div>
            <div class="small text-muted flex-shrink-0"><?= custodia_format_date($n['created_at']) ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php if (!empty($result['entries'])): ?><?= custodia_pagination_bar($result) ?><?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
