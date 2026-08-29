<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/reports.php';

$user = custodia_require_login();
custodia_require_role($user, ['SYSTEM_ADMIN', 'RECORDS_MANAGER']);
$pdo = custodia_db();

$reportId = $_GET['id'] ?? '';
$report = null;
$content = null;
if ($reportId !== '') {
    try {
        $report = custodia_get_report($pdo, $reportId);
        $content = json_decode($report['content_json'], true);
    } catch (CustodiaHttpException $e) {
        http_response_code($e->status);
        $pageTitle = 'Reports';
        $activeNav = 'reports';
        require __DIR__ . '/includes/layout_header.php';
        echo '<div class="alert alert-danger">' . e($e->getMessage()) . '</div>';
        require __DIR__ . '/includes/layout_footer.php';
        exit;
    }
}

$reports = custodia_list_reports($pdo);

$pageTitle = 'Reports';
$activeNav = 'reports';
require __DIR__ . '/includes/layout_header.php';
?>

<?php if ($report && $content): ?>

  <div class="page-breadcrumb"><a href="reports.php">Reports</a> / Weekly Operations Report</div>
  <div class="page-header">
    <div>
      <div class="page-title">Weekly Operations Report</div>
      <div class="page-subtitle"><?= e(custodia_format_date($content['periodStart'])) ?> – <?= e(custodia_format_date($content['periodEnd'])) ?> · generated <?= e(custodia_format_datetime($report['created_at'])) ?> by <?= e($report['generated_by_name']) ?> (<?= $report['generated_via'] === 'MANUAL' ? 'manual' : 'scheduled' ?>)</div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="stat-tile"><div class="stat-label">New Matters</div><div class="stat-value"><?= (int) $content['newMattersCount'] ?></div></div>
    </div>
    <div class="col-md-3">
      <div class="stat-tile"><div class="stat-label">New Clients</div><div class="stat-value"><?= (int) $content['newClientsCount'] ?></div></div>
    </div>
    <div class="col-md-3">
      <div class="stat-tile stat-tile-alert"><div class="stat-label">Overdue Files</div><div class="stat-value"><?= (int) $content['overdueFilesCount'] ?></div></div>
    </div>
    <div class="col-md-3">
      <div class="stat-tile"><div class="stat-label">Pending Approvals</div><div class="stat-value"><?= (int) $content['pendingMovementsCount'] + (int) $content['pendingAccessRequestsCount'] ?></div></div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-3">
      <div class="stat-tile"><div class="stat-label">Pending Destruction Review</div><div class="stat-value"><?= (int) $content['pendingDestructionReviewCount'] ?></div></div>
    </div>
    <div class="col-md-3">
      <div class="stat-tile"><div class="stat-label">Documents Uploaded</div><div class="stat-value"><?= (int) $content['documentsUploadedCount'] ?></div></div>
    </div>
    <div class="col-md-3">
      <div class="stat-tile"><div class="stat-label">Audit Events</div><div class="stat-value"><?= (int) $content['auditEventsCount'] ?></div></div>
    </div>
    <div class="col-md-3">
      <div class="stat-tile"><div class="stat-label">Unstaffed Practice Groups</div><div class="stat-value"><?= (int) $content['groupsWithNoMembersCount'] ?> / <?= (int) $content['totalGroupsCount'] ?></div></div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">New Matters</div>
        <div class="card-body p-0">
          <?php if (empty($content['newMatters'])): ?>
            <div class="text-center text-muted py-4">No matters opened this period.</div>
          <?php else: ?>
            <table class="table table-hover mb-0 align-middle">
              <thead class="table-light"><tr><th>Matter</th><th>Client</th><th>Practice Area</th><th>Opened</th></tr></thead>
              <tbody>
                <?php foreach ($content['newMatters'] as $m): ?>
                  <tr>
                    <td class="small"><?= e($m['matter_number']) ?></td>
                    <td class="small"><?= e($m['client_name']) ?></td>
                    <td class="small"><?= e($m['practice_area']) ?></td>
                    <td class="small text-muted"><?= e(custodia_format_date($m['open_date'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header bg-white fw-semibold">New Clients</div>
        <div class="card-body p-0">
          <?php if (empty($content['newClients'])): ?>
            <div class="text-center text-muted py-4">No clients added this period.</div>
          <?php else: ?>
            <table class="table table-hover mb-0 align-middle">
              <thead class="table-light"><tr><th>Client</th><th>Added</th></tr></thead>
              <tbody>
                <?php foreach ($content['newClients'] as $c): ?>
                  <tr>
                    <td class="small"><?= e($c['name']) ?></td>
                    <td class="small text-muted"><?= e(custodia_format_date($c['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>

  <div class="page-header">
    <div>
      <div class="page-title">Reports</div>
      <div class="page-subtitle">Automated weekly operations digest, generated every Friday and shared with System Admin/Records Manager.</div>
    </div>
    <button type="button" class="btn btn-primary btn-sm" onclick="custodiaGenerateReport()">Generate Report Now</button>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <?php if (empty($reports)): ?>
        <div class="text-center text-muted py-5">No reports yet. Click "Generate Report Now" to create the first one, or wait for Friday's scheduled run.</div>
      <?php else: ?>
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light"><tr><th>Period</th><th>Generated</th><th>By</th><th>Via</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($reports as $r): $c = json_decode($r['content_json'], true); ?>
              <tr onclick="window.location='reports.php?id=<?= e($r['id']) ?>'" class="table-clickable-row">
                <td class="small"><?= e(custodia_format_date($c['periodStart'])) ?> – <?= e(custodia_format_date($c['periodEnd'])) ?></td>
                <td class="small text-muted"><?= e(custodia_format_datetime($r['created_at'])) ?></td>
                <td class="small"><?= e($r['generated_by_name']) ?></td>
                <td><span class="badge text-bg-light"><?= $r['generated_via'] === 'MANUAL' ? 'Manual' : 'Scheduled' ?></span></td>
                <td class="text-end small"><a href="reports.php?id=<?= e($r['id']) ?>">View →</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <script>
  async function custodiaGenerateReport() {
    try {
      const data = await custodiaPost('actions/generate_weekly_report.php', {});
      window.location.href = 'reports.php?id=' + encodeURIComponent(data.id);
    } catch (err) {
      custodiaFlash(err.message, 'danger');
    }
  }
  </script>

<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
