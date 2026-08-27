<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/physical_files.php';

$user = custodia_require_login();
$pdo = custodia_db();

$barcode = trim($_GET['barcode'] ?? '');
$file = null;
$scanError = null;

if ($barcode !== '') {
    try {
        $file = custodia_find_file_by_barcode($pdo, $user, $barcode, custodia_client_ip());
    } catch (CustodiaHttpException $e) {
        $scanError = $e->getMessage();
    }
}

$locations = custodia_list_physical_locations($pdo);
$usersStmt = $pdo->query('SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name');
$allUsers = $usersStmt->fetchAll();
$matterId = $file['matter_id'] ?? null; // used by custody_modals.php only as a label context, not required

$pageTitle = 'Scan Station';
$activeNav = 'scan';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-body">
        <h1 class="page-title" style="font-size: 1.3rem;">Registry Scan Station</h1>
        <form method="get" action="scan.php" class="d-flex gap-2">
          <input type="text" name="barcode" class="form-control form-control-lg barcode-display" placeholder="Scan or type barcode…" value="<?= e($barcode) ?>" autofocus>
          <button type="submit" class="btn btn-primary btn-lg">Look Up</button>
        </form>
      </div>
    </div>

    <?php if ($scanError): ?>
      <div class="alert alert-danger"><?= e($scanError) ?></div>
    <?php endif; ?>

    <?php if ($file): ?>
      <div class="card">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="barcode-display h5"><?= e($file['barcode']) ?></div>
              <div class="fw-semibold"><?= e($file['jacket_label']) ?></div>
              <div class="text-muted small mt-1"><?= e($file['matter_number']) ?> · <?= e($file['client_name']) ?></div>
            </div>
            <div class="d-flex gap-1">
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

      <?php require __DIR__ . '/includes/custody_modals.php'; ?>
      <!-- window.location.reload() in custody_modals.php's success handlers preserves the ?barcode= query
           string, so the page naturally re-runs the lookup and shows the file's new status. -->
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
