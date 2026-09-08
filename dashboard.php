<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/matters.php';
require_once __DIR__ . '/includes/physical_files.php';
require_once __DIR__ . '/includes/custody.php';
require_once __DIR__ . '/includes/access_requests.php';
require_once __DIR__ . '/includes/analytics.php';
require_once __DIR__ . '/includes/clients.php';

$user = custodia_require_login();
$pdo = custodia_db();

$analytics = custodia_dashboard_analytics_cached($pdo, $user);

$overdueFiles = custodia_list_overdue_files($pdo, $user);
$pendingMovements = custodia_list_pending_for_approver($pdo, $user);
$pendingAccessRequests = custodia_list_pending_access_requests_for_approver($pdo, $user);
$destructionReviews = array_values(array_filter($pendingAccessRequests, fn($r) => $r['request_type'] === 'DESTRUCTION_REVIEW'));
$accessOnly = array_values(array_filter($pendingAccessRequests, fn($r) => $r['request_type'] !== 'DESTRUCTION_REVIEW'));

$mostRequestedMatters = custodia_analytics_most_requested_matters($pdo, $user);
$mostRequestedFiles = custodia_analytics_most_requested_files($pdo, $user);
$mostRequestedClients = custodia_analytics_most_requested_clients($pdo, $user);
$latestDocuments = custodia_analytics_latest_documents($pdo, $user);
$mostRequestedTotal = array_sum(array_column($mostRequestedMatters, 'count'))
    + array_sum(array_column($mostRequestedFiles, 'count'))
    + array_sum(array_column($mostRequestedClients, 'count'));

$clients = custodia_list_clients($pdo, $user);
$clientsWithoutMatters = array_values(array_filter($clients, fn($c) => (int) $c['matter_count'] === 0));

// Top clients by matter count — deliberately NOT gated behind any of the
// $show*Widget permission checks below: every non-Guest/Auditor user already
// sees every client and its matter_count via clients.php itself (see
// custodia_list_clients()), so a ranked view of the same numbers on the
// dashboard discloses nothing new. GUEST_AUDITOR gets an empty $clients
// array already, so this naturally renders as "No clients yet." for them.
$topClientsByMatters = $clients;
usort($topClientsByMatters, fn($a, $b) => (int) $b['matter_count'] <=> (int) $a['matter_count']);
$topClientsByMatters = array_slice($topClientsByMatters, 0, 20);

$groupsWithNoMembers = array_values(array_filter($analytics['groupComparison'], fn($g) => $g['memberCount'] === 0));

$pendingApprovalsCount = count($pendingMovements) + count($accessOnly);
$checkedOutCount = custodia_count_checked_out_files($pdo, $user);

// Role-based dashboard visibility: System Admin/Records Manager (firm-wide
// roles) always see everything; everyone else sees only the widgets
// relevant to their own responsibility. Permission-driven, not role-name-
// hardcoded (beyond the firm-wide-roles concept this codebase already uses
// for matter visibility), so it works correctly for custom roles with no
// special-casing — same discipline as the chatbot's chip visibility and the
// dashboard analytics firm-wide fast path added earlier this session.
$isFirmWide = in_array($user['role'], custodia_firm_wide_roles(), true);
$showUsersWidget = $isFirmWide || custodia_user_has_permission($pdo, $user, 'manage_users');
$showGroupsWidgets = $isFirmWide || custodia_user_has_permission($pdo, $user, 'manage_practice_groups');
// Deliberately NOT OR'd with $isFirmWide — the audit log (and this widget,
// which is built from audit log activity) is System Admin-only by default
// regardless of a role's firm-wide matter visibility; see includes/
// permissions.php's view_audit_log entry.
$showAuditOversight = custodia_user_has_permission($pdo, $user, 'view_audit_log');
$showRequestOversight = $isFirmWide || custodia_user_has_permission($pdo, $user, 'decide_access_requests');

/** Renders a Top Actions status pill — variant is one of critical|needs-action|recommended|healthy. */
function custodia_m365_badge(string $label, string $variant): string
{
    return '<span class="m365-badge m365-badge-' . e($variant) . '">' . e($label) . '</span>';
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <div class="page-title">Dashboard</div>
  <form action="matters.php" method="get" class="d-flex align-items-center gap-2 flex-wrap">
    <input type="search" name="q" class="form-control form-control-sm" style="min-width: 260px;" placeholder="Search matters, files, people…">
    <a href="scan.php" class="btn btn-primary btn-sm">▦ Scan Barcode</a>
  </form>
</div>

<div class="m365-dash">

  <div class="m365-section-title">
    <span>Your organization at a glance</span>
    <span class="live-badge"><span class="live-dot"></span> Live</span>
  </div>

  <div class="m365-glance-row">
    <?php if ($showUsersWidget): ?>
    <div class="m365-card">
      <div class="m365-card-label">Users in your firm</div>
      <div class="m365-card-sub">Active users across all roles</div>
      <div class="m365-card-value" id="glanceUserCount">—</div>
      <div class="m365-card-section-title">Roles by type</div>
      <div id="glanceRoleBars" class="m365-bar-list"></div>
      <div class="m365-card-actions">
        <a href="admin.php?tab=users" class="btn btn-sm m365-btn-outline">Manage Users</a>
      </div>
    </div>
    <?php endif; ?>

    <div class="m365-card">
      <div class="m365-card-label">Matters</div>
      <div class="m365-card-sub">Opened — last 30 days</div>
      <div class="m365-card-value" id="glanceMattersCount">—</div>
      <div class="m365-sparkline-wrap"><canvas id="mattersSparkline"></canvas></div>
      <div class="m365-card-actions">
        <a href="matters.php" class="btn btn-sm m365-btn-outline">View Matters</a>
      </div>
    </div>

    <?php if ($showGroupsWidgets): ?>
    <div class="m365-card">
      <div class="m365-card-label">Practice Groups</div>
      <div class="m365-card-sub">Team coverage across the firm</div>
      <div class="m365-kpi-row" id="glanceGroupKpis"></div>
      <div class="m365-card-section-title">Groups with most team members</div>
      <div id="glanceGroupBars" class="m365-bar-list"></div>
      <div class="m365-card-actions">
        <a href="admin.php?tab=practicegroups" class="btn btn-sm m365-btn-outline">Manage Groups</a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($showAuditOversight): ?>
    <div class="m365-card">
      <div class="m365-card-label">Activity</div>
      <div class="m365-card-sub">Audit events — last 30 days</div>
      <div class="m365-card-value" id="glanceActivityCount">—</div>
      <div class="m365-card-section-title">Trending users</div>
      <div id="glanceTrendingUsers" class="m365-trend-list"></div>
      <div class="m365-card-actions">
        <a href="admin.php?tab=audit" class="btn btn-sm m365-btn-outline">View Audit Log</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="m365-section-title">
    <span>Top actions</span>
  </div>
  <div class="m365-pills">
    <button type="button" class="m365-pill active" data-filter="all">All</button>
    <button type="button" class="m365-pill" data-filter="physical">Physical Registry</button>
    <button type="button" class="m365-pill" data-filter="security">Security</button>
    <button type="button" class="m365-pill" data-filter="compliance">Compliance</button>
    <button type="button" class="m365-pill" data-filter="documents">Documents</button>
    <button type="button" class="m365-pill" data-filter="clients">Clients</button>
  </div>

  <div class="m365-actions-grid" id="m365ActionsGrid">

    <div class="m365-action-card" data-category="physical">
      <div class="m365-action-card-top">
        <span class="m365-action-category">Physical Registry</span>
        <?= custodia_m365_badge(count($overdueFiles) > 0 ? 'Critical' : 'Healthy', count($overdueFiles) > 0 ? 'critical' : 'healthy') ?>
      </div>
      <div class="m365-action-title"><?= count($overdueFiles) ?> file<?= count($overdueFiles) === 1 ? '' : 's' ?> overdue</div>
      <div class="m365-action-body">
        <?php if (empty($overdueFiles)): ?>
          No overdue files. Nice work.
        <?php else: ?>
          <?php foreach (array_slice($overdueFiles, 0, 3) as $f): ?>
            <div class="m365-action-body-row"><span><?= e($f['barcode']) ?></span><span><?= custodia_days_overdue($f['due_back_at']) ?>d</span></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="m365-card-actions"><a href="approvals.php" class="btn btn-sm m365-btn-outline">View all issues</a></div>
    </div>

    <div class="m365-action-card" data-category="physical">
      <div class="m365-action-card-top">
        <span class="m365-action-category">Custody Movements</span>
        <?= custodia_m365_badge(count($pendingMovements) > 0 ? 'Needs action' : 'Healthy', count($pendingMovements) > 0 ? 'needs-action' : 'healthy') ?>
      </div>
      <div class="m365-action-title"><?= count($pendingMovements) ?> movement<?= count($pendingMovements) === 1 ? '' : 's' ?> pending approval</div>
      <div class="m365-action-body">Issue/return, transfer, and archive requests waiting on a decision.</div>
      <div class="m365-card-actions"><a href="approvals.php" class="btn btn-sm m365-btn-outline">Review approvals</a></div>
    </div>

    <div class="m365-action-card" data-category="security">
      <div class="m365-action-card-top">
        <span class="m365-action-category">Security</span>
        <?= custodia_m365_badge(count($accessOnly) > 0 ? 'Needs action' : 'Healthy', count($accessOnly) > 0 ? 'needs-action' : 'healthy') ?>
      </div>
      <div class="m365-action-title"><?= count($accessOnly) ?> access request<?= count($accessOnly) === 1 ? '' : 's' ?> pending</div>
      <div class="m365-action-body">Confidential matter and document access requests awaiting review.</div>
      <div class="m365-card-actions"><a href="approvals.php" class="btn btn-sm m365-btn-outline">Review requests</a></div>
    </div>

    <div class="m365-action-card" data-category="compliance">
      <div class="m365-action-card-top">
        <span class="m365-action-category">Compliance</span>
        <?= custodia_m365_badge(count($destructionReviews) > 0 ? 'Needs action' : 'Healthy', count($destructionReviews) > 0 ? 'needs-action' : 'healthy') ?>
      </div>
      <div class="m365-action-title"><?= count($destructionReviews) ?> pending destruction review</div>
      <div class="m365-action-body">Retention-flagged files waiting on a keep/destroy decision.</div>
      <div class="m365-card-actions"><a href="approvals.php" class="btn btn-sm m365-btn-outline">Review</a></div>
    </div>

    <?php if ($showGroupsWidgets): ?>
    <div class="m365-action-card" data-category="security">
      <div class="m365-action-card-top">
        <span class="m365-action-category">Practice Groups</span>
        <?= custodia_m365_badge(count($groupsWithNoMembers) > 0 ? 'Needs action' : 'Healthy', count($groupsWithNoMembers) > 0 ? 'needs-action' : 'healthy') ?>
      </div>
      <div class="m365-action-title"><?= count($groupsWithNoMembers) ?> of <?= count($analytics['groupComparison']) ?> groups have no members</div>
      <div class="m365-action-body">
        <?php if (empty($groupsWithNoMembers)): ?>
          Every practice group has at least one member.
        <?php else: ?>
          <?php foreach (array_slice($groupsWithNoMembers, 0, 3) as $g): ?>
            <div class="m365-action-body-row"><span><?= e($g['name']) ?></span><span>0 members</span></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="m365-card-actions"><a href="admin.php?tab=practicegroups" class="btn btn-sm m365-btn-outline">Manage groups</a></div>
    </div>
    <?php endif; ?>

    <div class="m365-action-card" data-category="documents">
      <div class="m365-action-card-top">
        <span class="m365-action-category">Digital Documents</span>
        <?= custodia_m365_badge(empty($latestDocuments) ? 'Recommended' : 'Healthy', empty($latestDocuments) ? 'recommended' : 'healthy') ?>
      </div>
      <div class="m365-action-title"><?= empty($latestDocuments) ? 'No documents uploaded yet' : count($latestDocuments) . ' recently added' ?></div>
      <div class="m365-action-body">
        <?php if (empty($latestDocuments)): ?>
          Get started by uploading documents to a matter.
        <?php else: ?>
          <?php foreach (array_slice($latestDocuments, 0, 3) as $d): ?>
            <div class="m365-action-body-row"><span><?= e($d['title']) ?></span><span><?= e(date('M j', strtotime($d['created_at']))) ?></span></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="m365-card-actions"><a href="documents.php" class="btn btn-sm m365-btn-outline">Upload documents</a></div>
    </div>

    <?php if ($showRequestOversight): ?>
    <div class="m365-action-card" data-category="documents">
      <div class="m365-action-card-top">
        <span class="m365-action-category">Access Requests</span>
        <?= custodia_m365_badge('Healthy', 'healthy') ?>
      </div>
      <div class="m365-action-title"><?= $mostRequestedTotal ?> request<?= $mostRequestedTotal === 1 ? '' : 's' ?> logged</div>
      <div class="m365-action-body">
        <?php if ($mostRequestedTotal === 0): ?>
          No access requests logged yet.
        <?php else: ?>
          <?php foreach (array_slice($mostRequestedMatters, 0, 3) as $r): ?>
            <div class="m365-action-body-row"><span><?= e($r['label']) ?></span><span><?= $r['count'] ?></span></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="m365-card-actions"><a href="approvals.php" class="btn btn-sm m365-btn-outline">View requests</a></div>
    </div>
    <?php endif; ?>

    <div class="m365-action-card" data-category="clients">
      <div class="m365-action-card-top">
        <span class="m365-action-category">Clients</span>
        <?= custodia_m365_badge('Healthy', 'healthy') ?>
      </div>
      <div class="m365-action-title"><?= count($clients) ?> clients on file</div>
      <div class="m365-action-body">
        <?= count($clientsWithoutMatters) ?> client<?= count($clientsWithoutMatters) === 1 ? '' : 's' ?> with no matters yet. All clients and matters are being tracked normally.
      </div>
      <div class="m365-card-actions"><a href="clients.php" class="btn btn-sm m365-btn-outline">View clients</a></div>
    </div>

  </div>

  <div class="d-flex align-items-center justify-content-between mt-4 mb-2">
    <button type="button" class="m365-analytics-toggle" data-bs-toggle="collapse" data-bs-target="#analyticsSection" aria-expanded="true" aria-controls="analyticsSection">
      <svg class="m365-chevron" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
      Analytics
    </button>
    <span class="live-badge"><span class="live-dot"></span> Live</span>
  </div>

  <div class="collapse show" id="analyticsSection">

  <div class="row g-3 mb-3">
    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Activity Over Time <span class="text-muted fw-normal small">— last 30 days</span></div>
        <div class="chart-card-body">
          <canvas id="activityChart" height="90"></canvas>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">File Types</div>
        <div class="chart-card-body">
          <canvas id="fileTypeChart" height="90"></canvas>
          <div id="fileTypeEmpty" class="chart-empty-state d-none">No documents yet.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Most Accessed Documents</div>
        <div class="chart-card-body">
          <canvas id="mostAccessedChart" height="110"></canvas>
          <div id="mostAccessedEmpty" class="chart-empty-state d-none">No downloads recorded yet.</div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Activity by Type</div>
        <div class="chart-card-body">
          <canvas id="actionTypeChart" height="110"></canvas>
          <div id="actionTypeEmpty" class="chart-empty-state d-none">No audit activity in your view yet.</div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($showAuditOversight || $showGroupsWidgets): ?>
  <div class="row g-3 mb-3">
    <?php if ($showAuditOversight): ?>
    <div class="<?= $showGroupsWidgets ? 'col-lg-6' : 'col-lg-12' ?>">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Top Users</div>
        <div class="chart-card-body">
          <canvas id="topUsersChart" height="110"></canvas>
          <div id="topUsersEmpty" class="chart-empty-state d-none">No activity in your view yet.</div>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($showGroupsWidgets): ?>
    <div class="<?= $showAuditOversight ? 'col-lg-6' : 'col-lg-12' ?>">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">Group / Team Comparison <span class="text-muted fw-normal small">— team size vs. active matter workload</span></div>
        <div class="chart-card-body">
          <canvas id="groupComparisonChart" height="110"></canvas>
          <div id="groupComparisonEmpty" class="chart-empty-state d-none">No practice groups yet.</div>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($showRequestOversight): ?>
  <div class="card">
    <div class="card-header bg-white fw-semibold">Most Requested</div>
    <div class="card-body">
      <div class="row g-4">
        <div class="col-md-4">
          <div class="small text-muted fw-semibold mb-2">Matters</div>
          <?php if (empty($mostRequestedMatters)): ?>
            <div class="text-muted small py-2">No access requests yet.</div>
          <?php else: ?>
            <?php foreach ($mostRequestedMatters as $r): ?>
              <div class="d-flex justify-content-between align-items-center border-bottom py-2 small">
                <span><?= e($r['label']) ?></span>
                <span class="badge text-bg-light"><?= $r['count'] ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <div class="small text-muted fw-semibold mb-2">Files</div>
          <?php if (empty($mostRequestedFiles)): ?>
            <div class="text-muted small py-2">No access requests yet.</div>
          <?php else: ?>
            <?php foreach ($mostRequestedFiles as $r): ?>
              <div class="d-flex justify-content-between align-items-center border-bottom py-2 small">
                <span><?= e($r['label']) ?></span>
                <span class="badge text-bg-light"><?= $r['count'] ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <div class="small text-muted fw-semibold mb-2">Clients</div>
          <?php if (empty($mostRequestedClients)): ?>
            <div class="text-muted small py-2">No access requests yet.</div>
          <?php else: ?>
            <?php foreach ($mostRequestedClients as $r): ?>
              <div class="d-flex justify-content-between align-items-center border-bottom py-2 small">
                <span><?= e($r['label']) ?></span>
                <span class="badge text-bg-light"><?= $r['count'] ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header bg-white fw-semibold">Top Clients by Matter Count <span class="text-muted fw-normal small">— top 20, visible to everyone</span></div>
    <div class="chart-card-body chart-card-body-tall">
      <canvas id="topClientsChart" height="640"></canvas>
      <div id="topClientsEmpty" class="chart-empty-state d-none">No clients yet.</div>
    </div>
  </div>

  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const CUSTODIA_INITIAL_ANALYTICS = <?= json_encode($analytics, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Top Clients by Matter Count — not part of the cached/live-refreshing
// analytics payload above (it doesn't need a 45s refresh), so it's embedded
// as its own constant the same way CUSTODIA_INITIAL_ANALYTICS is, and
// rendered as its own chart inside custodiaRenderAnalyticsCharts() below.
const CUSTODIA_TOP_CLIENTS = <?= json_encode(array_map(
    fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'matterCount' => (int) $c['matter_count']],
    $topClientsByMatters
), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

// Same hex values as .action-badge-* in assets/css/app.css, so a bar's color
// always matches that action type's badge color everywhere else in the app.
const CUSTODIA_ACTION_COLORS = {
  DOWNLOAD: '#0f766e', CHECK_OUT: '#475569', CHECK_IN: '#166534', OVERRIDE_CHECK_IN: '#92400e',
  ACCESS_APPROVED: '#6b21a8', ACCESS_DENIED: '#991b1b', SHARE_LINK_ACCESSED: '#475569', SHARE_LINK_CREATED: '#0f766e',
  EDIT_CHECKIN: '#166534', CHECKOUT_LOCK: '#92400e', ETHICAL_WALL_BYPASS: '#991b1b', TRANSFER_APPROVED: '#475569',
  TRANSFER_INITIATED: '#1e40af', TRANSFER_REJECTED: '#991b1b', MOVEMENT_APPROVED: '#475569', MOVEMENT_REJECTED: '#991b1b',
  AUDIT_EXPORT: '#1e40af', LOGIN_FAIL: '#991b1b', VIEW: '#475569',
};
const CUSTODIA_PALETTE = ['#0d9488', '#1e40af', '#6b21a8', '#92400e', '#166534', '#991b1b', '#475569', '#14b8a6'];

let custodiaCharts = {};

function custodiaDestroyChart(key) {
  if (custodiaCharts[key]) { custodiaCharts[key].destroy(); delete custodiaCharts[key]; }
}

function custodiaFormatDay(iso) {
  const d = new Date(iso + 'T00:00:00');
  return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function custodiaToggleEmpty(canvasId, emptyId, isEmpty) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return; // widget hidden for this viewer's role — nothing to toggle
  canvas.classList.toggle('d-none', isEmpty);
  const empty = document.getElementById(emptyId);
  if (empty) empty.classList.toggle('d-none', !isEmpty);
}

// Some "at a glance"/analytics widgets are hidden per-role (see dashboard.php's
// $showUsersWidget/$showGroupsWidgets/etc.), so their DOM elements may not
// exist for every viewer — every render function below must go through these
// instead of calling document.getElementById(...).textContent/.innerHTML
// directly, or the first missing element throws and silently kills every
// remaining widget update in that render pass.
function custodiaSetText(id, value) {
  const el = document.getElementById(id);
  if (el) el.textContent = value;
}
function custodiaSetHtml(id, html) {
  const el = document.getElementById(id);
  if (el) el.innerHTML = html;
}

function custodiaEscapeHtmlDash(s) {
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Populates the four "at a glance" cards — plain HTML bars/lists, not canvas charts, same visual language as the M365 admin dashboard's license/usage cards. */
function custodiaRenderGlanceCards(data) {
  const totalUsers = data.activeUsersByRole.reduce((sum, r) => sum + r.count, 0);
  custodiaSetText('glanceUserCount', totalUsers);
  const maxRole = Math.max(1, ...data.activeUsersByRole.map(r => r.count));
  custodiaSetHtml('glanceRoleBars', data.activeUsersByRole.slice(0, 4).map(r => `
    <div class="m365-bar-row">
      <div class="m365-bar-row-top"><span>${custodiaEscapeHtmlDash(r.label)}</span><span>${r.count}</span></div>
      <div class="m365-bar-track"><div class="m365-bar-fill" style="width:${(r.count / maxRole) * 100}%"></div></div>
    </div>
  `).join('') || '<div class="text-muted small">No active users.</div>');

  const mattersTotal = data.mattersOpenedSeries.reduce((sum, r) => sum + r.count, 0);
  custodiaSetText('glanceMattersCount', mattersTotal);

  const totalGroups = data.groupComparison.length;
  const totalMembers = data.groupComparison.reduce((sum, g) => sum + g.memberCount, 0);
  const totalActiveMatters = data.groupComparison.reduce((sum, g) => sum + g.activeMatterCount, 0);
  custodiaSetHtml('glanceGroupKpis', `
    <div><div class="m365-kpi-value">${totalGroups}</div><div class="m365-kpi-label">Groups</div></div>
    <div><div class="m365-kpi-value">${totalMembers}</div><div class="m365-kpi-label">Members</div></div>
    <div><div class="m365-kpi-value">${totalActiveMatters}</div><div class="m365-kpi-label">Active Matters</div></div>
  `);
  const topGroups = [...data.groupComparison].sort((a, b) => b.memberCount - a.memberCount).slice(0, 4);
  const maxMembers = Math.max(1, ...topGroups.map(g => g.memberCount));
  custodiaSetHtml('glanceGroupBars', topGroups.map(g => `
    <div class="m365-bar-row">
      <div class="m365-bar-row-top"><span>${custodiaEscapeHtmlDash(g.name)}</span><span>${g.memberCount}</span></div>
      <div class="m365-bar-track"><div class="m365-bar-fill" style="width:${(g.memberCount / maxMembers) * 100}%"></div></div>
    </div>
  `).join('') || '<div class="text-muted small">No practice groups.</div>');

  const activityTotal = data.auditActivity.reduce((sum, r) => sum + r.count, 0);
  custodiaSetText('glanceActivityCount', activityTotal);
  custodiaSetHtml('glanceTrendingUsers', data.topUsers.slice(0, 3).map(u => `
    <div><div class="m365-trend-name">${custodiaEscapeHtmlDash(u.label)}</div><div class="m365-trend-meta">${u.count} events</div></div>
  `).join('') || '<div class="text-muted small">No activity yet.</div>');
}

/**
 * The 6 charts inside the collapsible #analyticsSection. Kept separate from
 * custodiaRenderCharts() because Chart.js sizes a canvas from its container
 * at creation time — destroying/recreating these while the section is
 * collapsed (display:none) leaves them permanently 0×0 even after
 * re-expanding. custodiaRenderCharts() below only calls this when the
 * section is actually visible, and a shown.bs.collapse listener re-renders
 * with the latest data whenever the user expands it.
 */
function custodiaRenderAnalyticsCharts(data) {
  const labels = data.uploadActivity.map(r => custodiaFormatDay(r.day));

  custodiaDestroyChart('activity');
  custodiaCharts.activity = new Chart(document.getElementById('activityChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Documents Uploaded',
          data: data.uploadActivity.map(r => r.count),
          borderColor: '#0d9488',
          backgroundColor: 'rgba(13, 148, 136, 0.12)',
          tension: 0.35,
          fill: true,
          pointRadius: 0,
          borderWidth: 2,
        },
        {
          label: 'Audit Events',
          data: data.auditActivity.map(r => r.count),
          borderColor: '#1e40af',
          backgroundColor: 'rgba(30, 64, 175, 0.08)',
          tension: 0.35,
          fill: true,
          pointRadius: 0,
          borderWidth: 2,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
    },
  });

  const hasFileTypes = data.fileTypeBreakdown.length > 0;
  custodiaToggleEmpty('fileTypeChart', 'fileTypeEmpty', !hasFileTypes);
  custodiaDestroyChart('fileType');
  if (hasFileTypes) {
    custodiaCharts.fileType = new Chart(document.getElementById('fileTypeChart'), {
      type: 'doughnut',
      data: {
        labels: data.fileTypeBreakdown.map(r => r.category),
        datasets: [{
          data: data.fileTypeBreakdown.map(r => r.count),
          backgroundColor: CUSTODIA_PALETTE,
          borderWidth: 2,
          borderColor: '#ffffff',
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } },
      },
    });
  }

  const hasAccessed = data.mostAccessedDocuments.length > 0;
  custodiaToggleEmpty('mostAccessedChart', 'mostAccessedEmpty', !hasAccessed);
  custodiaDestroyChart('mostAccessed');
  if (hasAccessed) {
    const sorted = [...data.mostAccessedDocuments].reverse(); // horizontal bar draws bottom-up
    custodiaCharts.mostAccessed = new Chart(document.getElementById('mostAccessedChart'), {
      type: 'bar',
      data: {
        labels: sorted.map(r => r.label.length > 42 ? r.label.slice(0, 39) + '…' : r.label),
        datasets: [{ data: sorted.map(r => r.count), backgroundColor: '#14b8a6', borderRadius: 4, maxBarThickness: 22 }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }

  const hasActions = data.actionTypeBreakdown.length > 0;
  custodiaToggleEmpty('actionTypeChart', 'actionTypeEmpty', !hasActions);
  custodiaDestroyChart('actionType');
  if (hasActions) {
    const sorted = [...data.actionTypeBreakdown].reverse();
    custodiaCharts.actionType = new Chart(document.getElementById('actionTypeChart'), {
      type: 'bar',
      data: {
        labels: sorted.map(r => r.actionType.replace(/_/g, ' ')),
        datasets: [{
          data: sorted.map(r => r.count),
          backgroundColor: sorted.map(r => CUSTODIA_ACTION_COLORS[r.actionType] || '#475569'),
          borderRadius: 4,
          maxBarThickness: 22,
        }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }

  const hasTopUsers = data.topUsers.length > 0;
  custodiaToggleEmpty('topUsersChart', 'topUsersEmpty', !hasTopUsers);
  custodiaDestroyChart('topUsers');
  if (hasTopUsers && document.getElementById('topUsersChart')) {
    const sorted = [...data.topUsers].reverse();
    custodiaCharts.topUsers = new Chart(document.getElementById('topUsersChart'), {
      type: 'bar',
      data: {
        labels: sorted.map(r => r.label),
        datasets: [{ data: sorted.map(r => r.count), backgroundColor: '#1e40af', borderRadius: 4, maxBarThickness: 22 }],
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }

  const hasGroups = data.groupComparison.length > 0;
  custodiaToggleEmpty('groupComparisonChart', 'groupComparisonEmpty', !hasGroups);
  custodiaDestroyChart('groupComparison');
  if (hasGroups && document.getElementById('groupComparisonChart')) {
    custodiaCharts.groupComparison = new Chart(document.getElementById('groupComparisonChart'), {
      type: 'bar',
      data: {
        labels: data.groupComparison.map(r => r.name),
        datasets: [
          { label: 'Team Members', data: data.groupComparison.map(r => r.memberCount), backgroundColor: '#0d9488', borderRadius: 4, maxBarThickness: 26 },
          { label: 'Active Matters', data: data.groupComparison.map(r => r.activeMatterCount), backgroundColor: '#1e40af', borderRadius: 4, maxBarThickness: 26 },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
      },
    });
  }

  const hasTopClients = CUSTODIA_TOP_CLIENTS.length > 0;
  custodiaToggleEmpty('topClientsChart', 'topClientsEmpty', !hasTopClients);
  custodiaDestroyChart('topClients');
  if (hasTopClients) {
    // Left-to-right in rank order (#1 first) — unlike the horizontal bar
    // charts above, a line reads naturally left-to-right, so this one isn't
    // reversed.
    custodiaCharts.topClients = new Chart(document.getElementById('topClientsChart'), {
      type: 'line',
      data: {
        labels: CUSTODIA_TOP_CLIENTS.map(c => c.name.length > 20 ? c.name.slice(0, 17) + '…' : c.name),
        datasets: [{
          data: CUSTODIA_TOP_CLIENTS.map(c => c.matterCount),
          borderColor: '#0d9488',
          backgroundColor: 'rgba(13, 148, 136, 0.12)',
          tension: 0.3,
          fill: true,
          pointRadius: 3,
          pointHoverRadius: 6,
          pointBackgroundColor: '#0d9488',
          borderWidth: 2,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        onHover: (evt, elements) => { evt.native.target.style.cursor = elements.length ? 'pointer' : 'default'; },
        onClick: (evt, elements) => {
          if (elements.length) window.location = 'client.php?id=' + encodeURIComponent(CUSTODIA_TOP_CLIENTS[elements[0].index].id);
        },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: (items) => CUSTODIA_TOP_CLIENTS[items[0].dataIndex].name,
              label: (ctx) => `${ctx.parsed.y} matter${ctx.parsed.y === 1 ? '' : 's'}`,
            },
          },
        },
        scales: {
          x: { ticks: { maxRotation: 60, minRotation: 60, autoSkip: false, font: { size: 10 } } },
          y: { beginAtZero: true, ticks: { precision: 0 } },
        },
      },
    });
  }
}

function custodiaAnalyticsSectionExpanded() {
  const section = document.getElementById('analyticsSection');
  return !section || section.classList.contains('show');
}

let custodiaLatestAnalytics = CUSTODIA_INITIAL_ANALYTICS;

/** Entry point for both the initial render and the 45s live refresh. The sparkline and "at a glance" cards live outside the collapsible section, so they're always cheap to update; the 6 heavier charts only redraw while #analyticsSection is actually visible — see custodiaRenderAnalyticsCharts()'s comment. */
function custodiaRenderCharts(data) {
  custodiaLatestAnalytics = data;

  custodiaDestroyChart('mattersSparkline');
  custodiaCharts.mattersSparkline = new Chart(document.getElementById('mattersSparkline'), {
    type: 'line',
    data: {
      labels: data.mattersOpenedSeries.map(r => custodiaFormatDay(r.day)),
      datasets: [{
        data: data.mattersOpenedSeries.map(r => r.count),
        borderColor: '#2dd4bf',
        backgroundColor: 'rgba(45, 212, 191, 0.15)',
        tension: 0.35,
        fill: true,
        pointRadius: 0,
        borderWidth: 2,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { color: '#64748b', maxTicksLimit: 4, font: { size: 10 } }, grid: { display: false } },
        y: { display: false },
      },
    },
  });

  custodiaRenderGlanceCards(data);

  if (custodiaAnalyticsSectionExpanded()) {
    custodiaRenderAnalyticsCharts(data);
  }
}

const custodiaAnalyticsSectionEl = document.getElementById('analyticsSection');
if (custodiaAnalyticsSectionEl) {
  custodiaAnalyticsSectionEl.addEventListener('shown.bs.collapse', () => custodiaRenderAnalyticsCharts(custodiaLatestAnalytics));
}

custodiaRenderCharts(CUSTODIA_INITIAL_ANALYTICS);

document.querySelectorAll('.m365-pill').forEach(pill => {
  pill.addEventListener('click', () => {
    document.querySelectorAll('.m365-pill').forEach(p => p.classList.remove('active'));
    pill.classList.add('active');
    const filter = pill.dataset.filter;
    document.querySelectorAll('#m365ActionsGrid .m365-action-card').forEach(card => {
      card.classList.toggle('d-none', filter !== 'all' && card.dataset.category !== filter);
    });
  });
});

// "Live" — quietly re-fetches and redraws on an interval, same JSON shape as
// the initial embedded payload, so a dashboard left open keeps reflecting
// new uploads/downloads/audit activity without a manual refresh.
setInterval(async () => {
  try {
    const fresh = await custodiaGet('actions/dashboard_analytics.php');
    custodiaRenderCharts(fresh);
  } catch (err) {
    // A transient failure here shouldn't be alarming — just skip this tick and retry next interval.
  }
}, 45000);
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
