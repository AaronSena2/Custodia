<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/matters.php';
require_once __DIR__ . '/includes/clients.php';
require_once __DIR__ . '/includes/practice_groups.php';
require_once __DIR__ . '/includes/listing.php';

$user = custodia_require_login();
$pdo = custodia_db();

$allMatters = custodia_list_matters_for_user($pdo, $user);
$canCreate = custodia_user_has_permission($pdo, $user, 'create_matters');

$practiceAreas = array_values(array_unique(array_column($allMatters, 'practice_area')));
sort($practiceAreas);

$filters = [
    'status' => trim($_GET['status'] ?? ''),
    'confidentiality' => trim($_GET['confidentiality'] ?? ''),
    'practice_area' => trim($_GET['practiceArea'] ?? ''),
];
$search = trim($_GET['q'] ?? '');

$listParams = custodia_listing_params(
    ['matter_number', 'client_name', 'practice_area', 'status', 'open_date'],
    'open_date', 'DESC'
);
$result = custodia_apply_listing($allMatters, $listParams, $filters, $search, ['matter_number', 'client_name', 'practice_area']);

$partners = [];
$clientsForPicker = [];
$practiceAreasForPicker = [];
if ($canCreate) {
    $pStmt = $pdo->prepare("SELECT id, full_name FROM users WHERE role = 'PARTNER' AND is_active = 1 ORDER BY full_name");
    $pStmt->execute();
    $partners = $pStmt->fetchAll();

    $clientsForPicker = custodia_list_clients($pdo, $user);
    usort($clientsForPicker, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

    $practiceAreasForPicker = custodia_list_practice_groups($pdo);
}

$pageTitle = 'Matters';
$activeNav = 'matters';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <div class="page-title">Matters</div>
  <?php if ($canCreate): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createMatterModal">+ New Matter</button>
  <?php endif; ?>
</div>

<?php if (empty($allMatters)): ?>
  <div class="card"><div class="text-center text-muted py-5">No matters visible to your account yet.</div></div>
<?php else: ?>
  <form method="get" action="matters.php" class="filter-bar">
    <div class="filter-col filter-col-search">
      <input class="form-control form-control-sm" name="q" placeholder="Search matter #, client, practice area…" value="<?= e($search) ?>">
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (['ACTIVE' => 'Active', 'ON_HOLD' => 'On Hold', 'CLOSED' => 'Closed', 'ARCHIVED' => 'Archived'] as $val => $label): ?>
          <option value="<?= e($val) ?>" <?= $filters['status'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="confidentiality" onchange="this.form.submit()">
        <option value="">All confidentiality</option>
        <?php foreach (['STANDARD', 'RESTRICTED', 'PRIVILEGED'] as $val): ?>
          <option value="<?= e($val) ?>" <?= $filters['confidentiality'] === $val ? 'selected' : '' ?>><?= e($val) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="practiceArea" onchange="this.form.submit()">
        <option value="">All practice areas</option>
        <?php foreach ($practiceAreas as $pa): ?>
          <option value="<?= e($pa) ?>" <?= $filters['practice_area'] === $pa ? 'selected' : '' ?>><?= e($pa) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col" style="flex: 0 0 auto;"><button class="btn btn-sm btn-primary" type="submit">Filter</button></div>
  </form>

  <div class="card">
    <?php if (empty($result['rows'])): ?>
      <div class="text-center text-muted py-5">No matters match these filters.</div>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th><?= custodia_sort_link('Matter', 'matter_number', $listParams) ?></th>
            <th><?= custodia_sort_link('Client', 'client_name', $listParams) ?></th>
            <th><?= custodia_sort_link('Practice Area', 'practice_area', $listParams) ?></th>
            <th><?= custodia_sort_link('Status', 'status', $listParams) ?></th>
            <th><?= custodia_sort_link('Opened', 'open_date', $listParams) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($result['rows'] as $m): ?>
            <tr class="table-clickable-row" onclick="window.location='matter.php?id=<?= e($m['id']) ?>'">
              <td class="fw-semibold"><?= custodia_matter_number_chip($m['matter_number'], $m['confidentiality']) ?></td>
              <td><?= e($m['client_name']) ?></td>
              <td><?= e($m['practice_area']) ?></td>
              <td><?= custodia_status_badge($m['status']) ?> <?= custodia_confidentiality_badge($m['confidentiality']) ?></td>
              <td class="text-muted"><?= custodia_format_date($m['open_date']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?= custodia_pagination_bar($result) ?>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="modal fade" id="createMatterModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">New Matter</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="createMatterForm" data-action-url="actions/create_matter.php">
        <div class="modal-body">
          <div class="form-error alert alert-danger d-none"></div>
          <div class="mb-3">
            <label class="form-label">Matter Number</label>
            <input class="form-control" name="matterNumber" required placeholder="M-2026-0001">
          </div>
          <div class="mb-3">
            <label class="form-label">Client</label>
            <?php if (empty($clientsForPicker)): ?>
              <div class="form-text text-danger mb-2">No clients yet — <a href="clients.php">add one</a> first.</div>
            <?php endif; ?>
            <select class="form-select" name="clientId" required>
              <option value="">— Select a client —</option>
              <?php foreach ($clientsForPicker as $c): ?>
                <option value="<?= e($c['id']) ?>"><?= e($c['name']) ?><?= $c['is_active'] ? '' : ' (Deactivated)' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Practice Area</label>
            <?php if (empty($practiceAreasForPicker)): ?>
              <div class="form-text text-danger mb-2">No practice areas yet — an admin can add one from Admin → Practice Areas.</div>
            <?php endif; ?>
            <select class="form-select" name="practiceArea" required>
              <option value="">— Select a practice area —</option>
              <?php foreach ($practiceAreasForPicker as $pa): ?>
                <option value="<?= e($pa['name']) ?>"><?= e($pa['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Incharge</label>
            <select class="form-select" name="managingPartnerId" required>
              <?php foreach ($partners as $p): ?>
                <option value="<?= e($p['id']) ?>" <?= $p['id'] === $user['id'] ? 'selected' : '' ?>><?= e($p['full_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Confidentiality</label>
            <select class="form-select" name="confidentiality">
              <option value="STANDARD">Standard</option>
              <option value="RESTRICTED">Restricted</option>
              <option value="PRIVILEGED">Privileged</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary w-100">Create Matter</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
custodiaWireActionForm(document.getElementById('createMatterForm'), (data) => {
  window.location = 'matter.php?id=' + encodeURIComponent(data.id);
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
