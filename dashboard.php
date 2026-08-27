<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/matters.php';
require_once __DIR__ . '/includes/physical_files.php';
require_once __DIR__ . '/includes/custody.php';
require_once __DIR__ . '/includes/access_requests.php';
require_once __DIR__ . '/includes/analytics.php';

$user = custodia_require_login();
$pdo = custodia_db();

$analytics = custodia_dashboard_analytics($pdo, $user);

$matters = custodia_list_matters_for_user($pdo, $user);
$activeMatters = array_values(array_filter($matters, fn($m) => $m['status'] === 'ACTIVE'));

$overdueFiles = custodia_list_overdue_files($pdo, $user);
$pendingMovements = custodia_list_pending_for_approver($pdo, $user);
$pendingAccessRequests = custodia_list_pending_access_requests_for_approver($pdo, $user);
$destructionReviews = array_values(array_filter($pendingAccessRequests, fn($r) => $r['request_type'] === 'DESTRUCTION_REVIEW'));
$accessOnly = array_values(array_filter($pendingAccessRequests, fn($r) => $r['request_type'] !== 'DESTRUCTION_REVIEW'));

$byPracticeArea = [];
foreach ($activeMatters as $m) {
    $byPracticeArea[$m['practice_area']] = ($byPracticeArea[$m['practice_area']] ?? 0) + 1;
}
$maxCount = max(1, ...array_values($byPracticeArea) ?: [1]);

$pendingApprovalsCount = count($pendingMovements) + count($accessOnly);
$checkedOutCount = custodia_count_checked_out_files($pdo, $user);

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

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-tile">
      <div class="stat-label">Files Issued</div>
      <div class="stat-value"><?= $checkedOutCount ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-tile stat-tile-alert">
      <div class="stat-label">Overdue Returns</div>
      <div class="stat-value"><?= count($overdueFiles) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-tile">
      <div class="stat-label">Pending Approvals</div>
      <div class="stat-value"><?= $pendingApprovalsCount ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-tile">
      <div class="stat-label">Pending Destruction Review</div>
      <div class="stat-value"><?= count($destructionReviews) ?></div>
    </div>
  </div>
</div>

<div class="d-flex align-items-center justify-content-between mb-2">
  <div class="fw-semibold">Analytics</div>
  <span class="live-badge"><span class="live-dot"></span> Live</span>
</div>

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

<div class="row g-3 mb-4">
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

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center bg-white">
        <span class="fw-semibold">Overdue Returns</span>
        <a href="approvals.php" class="small">View all</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($overdueFiles)): ?>
          <div class="text-center text-muted py-5">No overdue files. Nice work.</div>
        <?php else: ?>
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
              <tr><th>File</th><th>Matter</th><th>Custodian</th><th>Overdue</th></tr>
            </thead>
            <tbody>
              <?php foreach ($overdueFiles as $f): $days = custodia_days_overdue($f['due_back_at']); ?>
                <tr onclick="window.location='matter.php?id=<?= e($f['matter_id']) ?>'" class="table-clickable-row">
                  <td>
                    <span class="barcode-display text-muted small"><?= e($f['barcode']) ?></span><br>
                    <span class="small"><?= e($f['jacket_label']) ?></span>
                  </td>
                  <td><?= e($f['client_name']) ?></td>
                  <td><?= e($f['custodian_name'] ?? '—') ?></td>
                  <td><span class="badge text-bg-danger"><?= $days ?> day<?= $days === 1 ? '' : 's' ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4 d-flex flex-column gap-3">
    <div class="card">
      <div class="card-header bg-white fw-semibold">Pending Destruction Review</div>
      <div class="card-body">
        <?php if (empty($destructionReviews)): ?>
          <div class="text-muted small text-center py-3">Nothing flagged right now.</div>
        <?php else: ?>
          <?php foreach ($destructionReviews as $r): ?>
            <div class="border-bottom py-2 small"><?= e($r['reason']) ?></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <a href="scan.php" class="card scan-cta-card text-white text-decoration-none">
      <div class="card-body">
        <div class="fw-semibold mb-1">▦ Registry Scan Station</div>
        <p class="small text-white-50 mb-0">Jump straight to the issue/return counter for the file room.</p>
      </div>
    </a>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white fw-semibold">Active Matters by Practice Area</div>
  <div class="card-body">
    <?php if (empty($byPracticeArea)): ?>
      <div class="text-muted text-center py-3">No active matters yet.</div>
    <?php else: ?>
      <?php foreach ($byPracticeArea as $area => $count): ?>
        <div class="row align-items-center mb-2 g-2">
          <div class="col-2 small text-muted"><?= e($area) ?></div>
          <div class="col-9">
            <div class="progress" style="height: 10px;">
              <div class="progress-bar" style="width: <?= ($count / $maxCount) * 100 ?>%"></div>
            </div>
          </div>
          <div class="col-1 small text-muted text-end"><?= $count ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const CUSTODIA_INITIAL_ANALYTICS = <?= json_encode($analytics, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

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
  document.getElementById(canvasId).classList.toggle('d-none', isEmpty);
  const empty = document.getElementById(emptyId);
  if (empty) empty.classList.toggle('d-none', !isEmpty);
}

function custodiaRenderCharts(data) {
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
}

custodiaRenderCharts(CUSTODIA_INITIAL_ANALYTICS);

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
