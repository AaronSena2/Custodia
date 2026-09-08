<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/clients.php';
require_once __DIR__ . '/includes/matters.php';
require_once __DIR__ . '/includes/listing.php';

$user = custodia_require_login();
$pdo = custodia_db();

$clientId = $_GET['id'] ?? '';
if ($clientId === '') {
    header('Location: clients.php');
    exit;
}

try {
    $client = custodia_client_detail($pdo, $user, $clientId, custodia_client_ip());
} catch (CustodiaHttpException $e) {
    http_response_code($e->status);
    $pageTitle = 'Client';
    $activeNav = 'clients';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="alert alert-danger">' . e($e->getMessage()) . '</div>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$canEdit = custodia_user_has_permission($pdo, $user, 'edit_clients');
$canDeactivate = custodia_user_has_permission($pdo, $user, 'deactivate_clients');

$matterFilters = ['status' => $_GET['status'] ?? '', 'confidentiality' => $_GET['confidentiality'] ?? ''];
$matterSearch = trim($_GET['q'] ?? '');
$matterListParams = custodia_listing_params(['matter_number', 'practice_area', 'status', 'open_date'], 'open_date', 'DESC');
$matterResult = custodia_apply_listing($client['matters'], $matterListParams, $matterFilters, $matterSearch, ['matter_number', 'practice_area']);

$pageTitle = $client['name'];
$activeNav = 'clients';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-breadcrumb"><a href="clients.php">Clients</a> / <?= e($client['name']) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
  <div class="d-flex align-items-center gap-2">
    <span class="page-title mb-0"><?= e($client['name']) ?></span>
    <?= $client['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Deactivated</span>' ?>
  </div>
  <div class="btn-group btn-group-sm">
    <?php if ($canEdit): ?>
      <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editClientModal">Edit</button>
    <?php endif; ?>
    <?php if ($canDeactivate): ?>
      <?php if ($client['is_active']): ?>
        <button class="btn btn-outline-danger" onclick="deactivateClient()">Deactivate</button>
      <?php else: ?>
        <button class="btn btn-outline-success" onclick="reactivateClient()">Reactivate</button>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header bg-white fw-semibold">Client Details</div>
  <div class="card-body">
    <dl class="row mb-0 small">
      <dt class="col-2 text-muted">Client Name</dt><dd class="col-4"><?= e($client['name']) ?></dd>
      <dt class="col-2 text-muted">Email</dt><dd class="col-4"><?= e($client['email'] ?: '—') ?></dd>
      <dt class="col-2 text-muted">Contact</dt><dd class="col-4"><?= e($client['phone'] ?: '—') ?></dd>
      <dt class="col-2 text-muted">Address</dt><dd class="col-4"><?= nl2br(e($client['address'] ?: '—')) ?></dd>
      <dt class="col-2 text-muted">Date Created</dt><dd class="col-4"><?= custodia_format_date($client['created_at']) ?></dd>
    </dl>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-2">
  <h2 class="h6 mb-0">Matters</h2>
</div>

<?php if (empty($client['matters'])): ?>
  <div class="card"><div class="text-center text-muted py-5">No matters visible to your account for this client yet.</div></div>
<?php else: ?>
  <form method="get" action="client.php" class="filter-bar">
    <input type="hidden" name="id" value="<?= e($clientId) ?>">
    <div class="filter-col filter-col-search">
      <input class="form-control form-control-sm" name="q" placeholder="Search matter #, practice area…" value="<?= e($matterSearch) ?>">
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (['ACTIVE' => 'Active', 'ON_HOLD' => 'On Hold', 'CLOSED' => 'Closed', 'ARCHIVED' => 'Archived'] as $val => $label): ?>
          <option value="<?= e($val) ?>" <?= $matterFilters['status'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="confidentiality" onchange="this.form.submit()">
        <option value="">All confidentiality</option>
        <?php foreach (['STANDARD', 'RESTRICTED', 'PRIVILEGED'] as $val): ?>
          <option value="<?= e($val) ?>" <?= $matterFilters['confidentiality'] === $val ? 'selected' : '' ?>><?= e($val) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col" style="flex: 0 0 auto;"><button class="btn btn-sm btn-primary" type="submit">Filter</button></div>
  </form>

  <div class="card">
    <?php if (empty($matterResult['rows'])): ?>
      <div class="text-center text-muted py-5">No matters match these filters.</div>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th><?= custodia_sort_link('Matter', 'matter_number', $matterListParams) ?></th>
            <th><?= custodia_sort_link('Practice Area', 'practice_area', $matterListParams) ?></th>
            <th><?= custodia_sort_link('Status', 'status', $matterListParams) ?></th>
            <th><?= custodia_sort_link('Opened', 'open_date', $matterListParams) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($matterResult['rows'] as $m): ?>
            <tr class="table-clickable-row" onclick="window.location='matter.php?id=<?= e($m['id']) ?>'">
              <td class="fw-semibold"><?= custodia_matter_number_chip($m['matter_number'], $m['confidentiality']) ?></td>
              <td><?= e($m['practice_area']) ?></td>
              <td><?= custodia_status_badge($m['status']) ?> <?= custodia_confidentiality_badge($m['confidentiality']) ?></td>
              <td class="text-muted"><?= custodia_format_date($m['open_date']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?= custodia_pagination_bar($matterResult) ?>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal fade" id="editClientModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Client</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="editClientForm" data-action-url="actions/update_client.php">
        <input type="hidden" name="clientId" value="<?= e($clientId) ?>">
        <div class="modal-body">
          <div class="form-error alert alert-danger d-none"></div>
          <div class="mb-3"><label class="form-label">Client Name</label><input class="form-control" name="name" value="<?= e($client['name']) ?>" required></div>
          <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" value="<?= e($client['email'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label">Contact</label><input class="form-control" name="phone" value="<?= e($client['phone'] ?? '') ?>"></div>
          <div class="mb-3"><label class="form-label">Address</label><textarea class="form-control" name="address"><?= e($client['address'] ?? '') ?></textarea></div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary w-100">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
custodiaWireActionForm(document.getElementById('editClientForm'), () => window.location.reload());
</script>
<?php endif; ?>

<?php if ($canDeactivate): ?>
<script>
async function deactivateClient() {
  try {
    await custodiaPost('actions/deactivate_client.php', { clientId: <?= json_encode($clientId) ?> });
    custodiaFlash('Client deactivated.');
    window.location.reload();
  } catch (err) { custodiaFlash(err.message, 'danger'); }
}

async function reactivateClient() {
  try {
    await custodiaPost('actions/reactivate_client.php', { clientId: <?= json_encode($clientId) ?> });
    custodiaFlash('Client reactivated.');
    window.location.reload();
  } catch (err) { custodiaFlash(err.message, 'danger'); }
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
