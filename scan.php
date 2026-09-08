<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/physical_files.php';

$user = custodia_require_login();
$pdo = custodia_db();

// "Recently Scanned This Session" (2026-09-06) — a rolling window of the
// last few successful barcode lookups, most recent first. Session-only, no
// table needed: this is a convenience for someone scanning many files in a
// row (a physical audit, a bulk reshelving pass), not a durable record —
// audit_log's own SCAN entries (written inside custodia_find_file_by_barcode())
// already are that.
const CUSTODIA_SCAN_HISTORY_LIMIT = 10;

if (isset($_GET['clearHistory'])) {
    custodia_start_session();
    unset($_SESSION['custodia_scan_history']);
    header('Location: scan.php');
    exit;
}

$barcode = trim($_GET['barcode'] ?? '');
$searchQuery = trim($_GET['q'] ?? '');
$file = null;
$scanError = null;
$searchResults = [];

if ($barcode !== '') {
    try {
        $file = custodia_find_file_by_barcode($pdo, $user, $barcode, custodia_client_ip());

        custodia_start_session();
        $_SESSION['custodia_scan_history'] = $_SESSION['custodia_scan_history'] ?? [];
        array_unshift($_SESSION['custodia_scan_history'], [
            'barcode' => $file['barcode'],
            'jacketLabel' => $file['jacket_label'],
            'matterNumber' => $file['matter_number'],
            'status' => $file['status'],
        ]);
        $_SESSION['custodia_scan_history'] = array_slice($_SESSION['custodia_scan_history'], 0, CUSTODIA_SCAN_HISTORY_LIMIT);
    } catch (CustodiaHttpException $e) {
        $scanError = $e->getMessage();
    }
} elseif ($searchQuery !== '') {
    $searchResults = custodia_search_files_fallback($pdo, $user, $searchQuery);
}

// Idle state (nothing scanned or searched yet — what a fresh visit to this
// page looks like) is when the "dead space" below the search box used to be
// empty. Fill it with two small worklists instead: files in the signed-in
// user's own custody, and overdue files they're allowed to see — both using
// queries the app already had (custodia_list_overdue_files() already powers
// the Dashboard's own panel).
$isIdle = $barcode === '' && $searchQuery === '';
$checkedOutToYou = $isIdle ? custodia_list_files_checked_out_to_user($pdo, $user['id']) : [];
$allOverdue = $isIdle ? custodia_list_overdue_files($pdo, $user) : [];
$overdueVisible = array_slice($allOverdue, 0, 5);

custodia_start_session();
$scanHistory = $_SESSION['custodia_scan_history'] ?? [];

$locations = custodia_list_physical_locations($pdo);
$usersStmt = $pdo->query('SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name');
$allUsers = $usersStmt->fetchAll();
$matterId = $file['matter_id'] ?? null; // used by custody_modals.php only as a label context, not required

$pageTitle = 'Scan Station';
$activeNav = 'scan';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-body">
        <h1 class="page-title" style="font-size: 1.3rem;">Registry Scan Station</h1>
        <form method="get" action="scan.php" class="d-flex gap-2">
          <input type="text" name="barcode" id="scanBarcodeInput" class="form-control form-control-lg barcode-display" placeholder="Scan or type barcode…" value="<?= e($barcode) ?>" autofocus>
          <button type="submit" class="btn btn-primary btn-lg">Look Up</button>
        </form>
        <details class="mt-2" <?= $searchQuery !== '' ? 'open' : '' ?>>
          <summary class="small text-muted" style="cursor: pointer;">Can't scan it? Search by matter number or jacket label instead</summary>
          <form method="get" action="scan.php" class="d-flex gap-2 mt-2">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Matter number or jacket label…" value="<?= e($searchQuery) ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Search</button>
          </form>
        </details>
      </div>
    </div>

    <?php if ($searchQuery !== ''): ?>
      <div class="card mb-3">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-semibold small">Results for "<?= e($searchQuery) ?>"</span>
            <a href="scan.php" class="small">Clear</a>
          </div>
          <?php if (empty($searchResults)): ?>
            <div class="text-muted small">No physical files match that matter number or jacket label.</div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($searchResults as $r): ?>
                <a href="scan.php?barcode=<?= e($r['barcode']) ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                  <div>
                    <div class="barcode-display small fw-semibold"><?= e($r['barcode']) ?></div>
                    <div class="small"><?= e($r['jacket_label']) ?></div>
                    <div class="small text-muted"><?= e($r['matter_number']) ?> · <?= e($r['client_name']) ?></div>
                  </div>
                  <?= custodia_status_badge($r['status']) ?>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <div id="scanResult">
    <?php if ($scanError): ?>
      <div class="alert alert-danger"><?= e($scanError) ?></div>
    <?php endif; ?>

    <?php if ($file): ?>
      <div class="card scan-result">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="barcode-display h5"><?= e($file['barcode']) ?></div>
              <div class="fw-semibold"><?= e($file['jacket_label']) ?></div>
              <div class="text-muted small mt-1"><?= e($file['matter_number']) ?> · <?= e($file['client_name']) ?></div>
            </div>
            <div class="d-flex gap-1">
              <?php if (custodia_file_is_overdue($file)): ?><span class="badge text-bg-danger">Overdue</span><?php endif; ?>
              <?= custodia_status_badge($file['status']) ?>
              <?= custodia_status_badge($file['lifecycle_status']) ?>
            </div>
          </div>
          <hr>
          <div class="row small mb-3">
            <div class="col-6">
              <div class="text-muted">Location</div>
              <div><?= e(trim(($file['building'] ?? '') . ' ' . ($file['room'] ?? ''))) ?: '—' ?></div>
            </div>
            <div class="col-6">
              <div class="text-muted">Current Custodian</div>
              <div><?= e($file['custodian_name'] ?? '—') ?></div>
            </div>
          </div>
          <?php if ($file['status'] === 'CHECKED_OUT'): ?>
            <div class="small text-muted mb-3"><?= custodia_last_movement_label($file) ?></div>
          <?php endif; ?>

          <div class="d-flex gap-2">
            <?php if ($file['status'] === 'IN_REGISTRY'): ?>
              <button class="btn btn-primary" onclick="openCheckoutModal('<?= e($file['id']) ?>', '<?= e($file['barcode']) ?>')">Issue</button>
            <?php elseif ($file['status'] === 'CHECKED_OUT' && $file['custodian_id'] === $user['id']): ?>
              <button class="btn btn-success" onclick="openCheckinModal('<?= e($file['id']) ?>', '<?= e($file['barcode']) ?>')">Return</button>
            <?php endif; ?>
            <?php if ($file['status'] === 'CHECKED_OUT' && $file['custodian_id'] !== $user['id']): ?>
              <button class="btn btn-outline-secondary" onclick="openTransferModal('<?= e($file['id']) ?>', '<?= e($file['barcode']) ?>')">Request Transfer</button>
            <?php endif; ?>
            <?php if (custodia_user_has_permission($pdo, $user, 'override_custody') && $file['status'] === 'CHECKED_OUT'): ?>
              <button class="btn btn-outline-danger" onclick="openCheckinModal('<?= e($file['id']) ?>', '<?= e($file['barcode']) ?>', true)">Override Return</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
    </div>

    <?php if ($isIdle && (!empty($checkedOutToYou) || !empty($overdueVisible))): ?>
      <div class="row g-3 mb-3">
        <?php if (!empty($checkedOutToYou)): ?>
          <div class="col-md-6">
            <div class="card h-100">
              <div class="card-header bg-white small fw-semibold">Checked Out To You (<?= count($checkedOutToYou) ?>)</div>
              <div class="list-group list-group-flush">
                <?php foreach ($checkedOutToYou as $f): ?>
                  <a href="scan.php?barcode=<?= e($f['barcode']) ?>" class="list-group-item list-group-item-action small">
                    <div class="d-flex justify-content-between align-items-center">
                      <span class="fw-semibold"><?= e($f['jacket_label']) ?></span>
                      <?php if (custodia_file_is_overdue($f)): ?><span class="badge text-bg-danger">Overdue</span><?php endif; ?>
                    </div>
                    <div class="text-muted"><?= e($f['matter_number']) ?> · <?= e($f['client_name']) ?></div>
                  </a>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>
        <?php if (!empty($overdueVisible)): ?>
          <div class="col-md-6">
            <div class="card h-100">
              <div class="card-header bg-white small fw-semibold">Overdue Files (<?= count($allOverdue) ?>)</div>
              <div class="list-group list-group-flush">
                <?php foreach ($overdueVisible as $f): ?>
                  <a href="scan.php?barcode=<?= e($f['barcode']) ?>" class="list-group-item list-group-item-action small">
                    <div class="fw-semibold"><?= e($f['jacket_label']) ?></div>
                    <div class="text-muted"><?= e($f['matter_number']) ?> · <?= e($f['client_name']) ?> · <?= e($f['custodian_name'] ?? '—') ?></div>
                  </a>
                <?php endforeach; ?>
              </div>
              <?php if (count($allOverdue) > 5): ?>
                <div class="card-footer bg-white text-center"><a href="dashboard.php" class="small">View all <?= count($allOverdue) ?> on the Dashboard</a></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($scanHistory)): ?>
      <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="small fw-semibold">Recently Scanned This Session</span>
          <a href="scan.php?clearHistory=1" class="small">Clear</a>
        </div>
        <div class="list-group list-group-flush">
          <?php foreach ($scanHistory as $h): ?>
            <a href="scan.php?barcode=<?= e($h['barcode']) ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center small">
              <div>
                <span class="barcode-display"><?= e($h['barcode']) ?></span> — <?= e($h['jacketLabel']) ?>
                <div class="text-muted"><?= e($h['matterNumber']) ?></div>
              </div>
              <?= custodia_status_badge($h['status']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($file): ?>
      <script>
      // Overrides custody_modals.php's default "flash + reload" for this
      // page only (must run before that file's script — see its own comment)
      // — a scan station is a repeated-scan workflow, so a successful
      // Issue/Return/Transfer should clear the result and hand focus back to
      // the barcode field immediately, not reload onto the same barcode and
      // make the clerk clear it by hand before the next scan.
      window.custodiaCustodyActionComplete = function (message) {
        custodiaFlash(message);
        const input = document.getElementById('scanBarcodeInput');
        const result = document.getElementById('scanResult');
        if (result) { result.innerHTML = ''; }
        if (input) { input.value = ''; }
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', 'scan.php');
        }
        if (input) { input.focus(); }
      };
      </script>
      <?php require __DIR__ . '/includes/custody_modals.php'; ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
