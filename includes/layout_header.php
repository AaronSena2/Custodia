<?php
/**
 * Shared page shell. Usage in every page:
 *
 *   require __DIR__ . '/includes/auth.php';
 *   $user = custodia_require_login();
 *   $pageTitle = 'Dashboard';
 *   require __DIR__ . '/includes/layout_header.php';
 *   // ... page body ...
 *   require __DIR__ . '/includes/layout_footer.php';
 *
 * $user and $pageTitle must be set before including this file. $activeNav
 * (optional) highlights the matching nav link — one of: dashboard, matters,
 * scan, documents, approvals, admin, help. Audit Log is a tab within Admin
 * (admin.php?tab=audit), not its own nav item — use 'admin' there too.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

$activeNav = $activeNav ?? '';

/** Inline outline-icon SVGs for the sidebar (kept dependency-free, no icon-font CDN). */
const CUSTODIA_NAV_ICONS = [
    'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    'matters' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    'clients' => '<path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'scan' => '<path d="M4 4h4M4 4v4M20 4h-4M20 4v4M4 20h4M4 20v-4M20 20h-4M20 20v-4"/><path d="M4 12h16" stroke-dasharray="2 2"/>',
    'documents' => '<path d="M6 2h9l5 5v15a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z"/><path d="M14 2v6h6"/>',
    'shared_with_me' => '<path d="M18 8a3 3 0 1 0-2.83-4H15a3 3 0 0 0 .05 2.11L8.91 9.59a3 3 0 1 0 0 4.82l6.14 3.48A3 3 0 1 0 18 16a2.98 2.98 0 0 0-.09.7l-6.14-3.48a3 3 0 0 0 0-1.44l6.14-3.48c.16.06.32.1.09.2Z"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    'approvals' => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
    'audit' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
    'admin' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    'reports' => '<path d="M6 2h9l5 5v15a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z"/><path d="M9 13h6M9 17h6M9 9h2"/>',
    'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.9.8c0 1.7-2.4 2-2.4 3.4"/><circle cx="12" cy="16.8" r="0.6" fill="currentColor" stroke="none"/>',
];

$navItems = [
    'dashboard' => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
    'matters' => ['label' => 'Matters', 'href' => 'matters.php'],
    'clients' => ['label' => 'Clients', 'href' => 'clients.php'],
    'scan' => ['label' => 'Physical Registry', 'href' => 'scan.php'],
    'documents' => ['label' => 'Digital Documents', 'href' => 'documents.php'],
    'shared_with_me' => ['label' => 'Shared With Me', 'href' => 'shared_with_me.php'],
    'approvals' => ['label' => 'Approvals', 'href' => 'approvals.php'],
];
if (in_array($user['role'], ['SYSTEM_ADMIN', 'RECORDS_MANAGER'], true)) {
    // Audit Log now lives as a tab within Admin (admin.php?tab=audit — see
    // includes/permissions.php's view_audit_log entry) rather than its own
    // top-level nav item, matching Practice Groups/Locations/Permissions.
    $navItems['admin'] = ['label' => 'Admin', 'href' => 'admin.php'];
    $navItems['reports'] = ['label' => 'Reports', 'href' => 'reports.php'];
}
$navItems['help'] = ['label' => 'User Manual', 'href' => 'help.php'];

// custodia_list_pending_for_approver()/custodia_list_pending_access_requests_for_approver()
// already return [] internally for a user with no relevant permission and no
// file currently in their custody, so there's no separate role pre-check here
// — that would go stale the moment an admin grants either permission to a
// role that isn't SYSTEM_ADMIN/RECORDS_MANAGER/PARTNER by default.
$sidebarApprovalsCount = 0;
try {
    require_once __DIR__ . '/custody.php';
    require_once __DIR__ . '/access_requests.php';
    $pdo = $pdo ?? custodia_db();
    $sidebarApprovalsCount = count(custodia_list_pending_for_approver($pdo, $user))
        + count(custodia_list_pending_access_requests_for_approver($pdo, $user));
} catch (Throwable $e) {
    $sidebarApprovalsCount = 0;
}

$sidebarNotifications = [];
$sidebarUnreadNotificationCount = 0;
try {
    require_once __DIR__ . '/notifications.php';
    $sidebarNotifications = custodia_list_notifications_for_user($pdo, $user['id']);
    $sidebarUnreadNotificationCount = custodia_count_unread_notifications($pdo, $user['id']);
} catch (Throwable $e) {
    $sidebarNotifications = [];
    $sidebarUnreadNotificationCount = 0;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= e(custodia_csrf_token()) ?>">
  <title><?= e($pageTitle ?? 'Custodia') ?> · Custodia</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/app.css?v=<?= e(custodia_asset_version('assets/css/app.css')) ?>" rel="stylesheet">
  <!--
    Loaded here (not at the end of <body> like Bootstrap's JS bundle) because
    every page's inline <script> block calls custodiaWireActionForm()/etc. at
    parse time, not inside an event handler — if app.js loaded after those
    inline scripts, every one of those calls would throw "custodiaWireActionForm
    is not defined" and silently leave the form's submit button doing a plain
    page reload instead of the intended AJAX submit. app.js only defines
    functions (no top-level DOM access), so loading it this early is safe.
  -->
  <script src="assets/js/app.js?v=<?= e(custodia_asset_version('assets/js/app.js')) ?>"></script>
</head>
<body>
<div class="app-shell">
  <aside class="app-sidebar">
    <div class="sidebar-brand">
      <div class="sidebar-brand-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 4v16"/></svg>
      </div>
      <div class="sidebar-brand-text">
        <div class="sidebar-brand-title">CUSTODIA</div>
        <div class="sidebar-brand-subtitle">Business File Registry</div>
      </div>
    </div>
    <ul class="sidebar-nav">
      <?php foreach ($navItems as $key => $item): ?>
        <li>
          <a class="sidebar-nav-link <?= $activeNav === $key ? 'active' : '' ?>" href="<?= e($item['href']) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= CUSTODIA_NAV_ICONS[$key] ?? '' ?></svg>
            <span><?= e($item['label']) ?></span>
            <?php if ($key === 'approvals' && $sidebarApprovalsCount > 0): ?>
              <span class="sidebar-nav-badge"><?= (int) $sidebarApprovalsCount ?></span>
            <?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="dropdown px-3 mb-2">
      <button class="btn btn-sm btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2 position-relative" type="button" id="notificationBellToggle" data-bs-toggle="dropdown" data-bs-strategy="fixed" aria-expanded="false">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        Notifications
        <span class="sidebar-nav-badge <?= $sidebarUnreadNotificationCount > 0 ? '' : 'd-none' ?>" id="notificationBellBadge"><?= (int) $sidebarUnreadNotificationCount ?></span>
      </button>
      <div class="dropdown-menu shadow-sm" style="width: 340px; max-height: 420px; overflow-y: auto;" aria-labelledby="notificationBellToggle">
        <div class="d-flex justify-content-between align-items-center px-3 py-2">
          <span class="fw-semibold small">Notifications</span>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-link btn-sm p-0 text-body-secondary" onclick="custodiaToggleNotificationSound()" title="Toggle notification sound" id="notificationSoundToggle">
              <svg class="sound-icon-on" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/></svg>
              <svg class="sound-icon-off" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="m17 9 5 6M22 9l-5 6"/></svg>
            </button>
            <button type="button" class="btn btn-link btn-sm p-0 <?= $sidebarUnreadNotificationCount > 0 ? '' : 'd-none' ?>" onclick="custodiaMarkAllNotificationsRead()" id="notificationMarkAllBtn">Mark all read</button>
          </div>
        </div>
        <div class="dropdown-divider"></div>
        <div id="notificationDropdownBody">
        <?php if (empty($sidebarNotifications)): ?>
          <div class="text-center text-muted small py-4" id="notificationEmptyState">Nothing yet.</div>
        <?php else: ?>
          <?php foreach ($sidebarNotifications as $n): ?>
            <?php $notifHref = custodia_notification_link($n); ?>
            <a href="<?= e($notifHref) ?>" class="dropdown-item py-2 <?= $n['read_at'] ? '' : 'bg-light-unread' ?>" onclick="return custodiaOpenNotification(event, '<?= e($n['id']) ?>', '<?= e($notifHref) ?>')" style="white-space: normal;">
              <div class="small fw-semibold"><?= e($n['title']) ?><?= $n['read_at'] ? '' : ' <span class="badge text-bg-primary ms-1">new</span>' ?></div>
              <?php if ($n['body']): ?><div class="small text-muted"><?= e($n['body']) ?></div><?php endif; ?>
              <div class="small text-muted"><?= custodia_format_date($n['created_at']) ?></div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
        </div>
        <div class="dropdown-divider"></div>
        <a href="notifications.php" class="dropdown-item py-2 text-center small fw-semibold">See all notifications</a>
      </div>
    </div>
    <script>
    let custodiaLastUnreadNotificationCount = <?= (int) $sidebarUnreadNotificationCount ?>;

    function custodiaOpenNotification(evt, id, href) {
      evt.preventDefault();
      custodiaPost('actions/mark_notification_read.php', { notificationId: id })
        .catch(() => {})
        .finally(() => { window.location.href = href; });
      return false;
    }
    async function custodiaMarkAllNotificationsRead() {
      try {
        await custodiaPost('actions/mark_all_notifications_read.php', {});
        window.location.reload();
      } catch (e) {}
    }

    /**
     * Rebuilds the bell badge + dropdown list from a fresh
     * actions/list_recent_notifications.php payload — same shape as the
     * PHP-rendered markup above, so a page left open keeps showing new
     * notifications (and the count that matters for custodiaPollNotifications()'s
     * sound trigger) without a manual refresh, same "quiet poll + redraw"
     * pattern dashboard.php uses for its analytics charts.
     */
    function custodiaRenderNotificationDropdown(payload) {
      const badge = document.getElementById('notificationBellBadge');
      badge.textContent = String(payload.unreadCount);
      badge.classList.toggle('d-none', payload.unreadCount === 0);
      document.getElementById('notificationMarkAllBtn').classList.toggle('d-none', payload.unreadCount === 0);

      const body = document.getElementById('notificationDropdownBody');
      body.innerHTML = '';
      if (payload.notifications.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'text-center text-muted small py-4';
        empty.textContent = 'Nothing yet.';
        body.appendChild(empty);
        return;
      }
      payload.notifications.forEach((n) => {
        const a = document.createElement('a');
        a.href = n.href;
        a.className = 'dropdown-item py-2' + (n.isUnread ? ' bg-light-unread' : '');
        a.style.whiteSpace = 'normal';
        a.onclick = (evt) => custodiaOpenNotification(evt, n.id, n.href);

        const titleRow = document.createElement('div');
        titleRow.className = 'small fw-semibold';
        titleRow.textContent = n.title;
        if (n.isUnread) {
          const badgeEl = document.createElement('span');
          badgeEl.className = 'badge text-bg-primary ms-1';
          badgeEl.textContent = 'new';
          titleRow.appendChild(badgeEl);
        }
        a.appendChild(titleRow);

        if (n.body) {
          const bodyRow = document.createElement('div');
          bodyRow.className = 'small text-muted';
          bodyRow.textContent = n.body;
          a.appendChild(bodyRow);
        }

        const dateRow = document.createElement('div');
        dateRow.className = 'small text-muted';
        dateRow.textContent = n.createdAt;
        a.appendChild(dateRow);

        body.appendChild(a);
      });
    }

    /** Quiet poll for new notifications — plays a sound the moment the unread count goes up. See app.js for custodiaPlayNotificationSound(). */
    async function custodiaPollNotifications() {
      try {
        const payload = await custodiaGet('actions/list_recent_notifications.php');
        if (payload.unreadCount > custodiaLastUnreadNotificationCount) {
          custodiaPlayNotificationSound();
        }
        custodiaLastUnreadNotificationCount = payload.unreadCount;
        custodiaRenderNotificationDropdown(payload);
      } catch (e) {
        // Transient failure — skip this tick, try again next interval.
      }
    }
    setInterval(custodiaPollNotifications, 20000);
    </script>
    <div class="sidebar-footer">
      <div class="sidebar-avatar"><?= e(custodia_initials($user['full_name'])) ?></div>
      <div>
        <div class="sidebar-user-name"><?= e($user['full_name']) ?></div>
        <div class="sidebar-user-role"><?= e(custodia_role_label($pdo, $user['role'])) ?></div>
      </div>
      <button type="button" class="sidebar-user-link theme-toggle-btn" onclick="custodiaToggleTheme()" title="Toggle dark mode">
        <svg class="theme-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>
        <svg class="theme-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
      </button>
      <a href="logout.php" class="sidebar-user-link" title="Sign out">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>
      </a>
    </div>
  </aside>
  <div class="app-content">
  <?= custodia_flash_render() ?>
