<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/clients.php';
require_once __DIR__ . '/includes/listing.php';

$user = custodia_require_login();
$pdo = custodia_db();

$allClients = custodia_list_clients($pdo, $user);
$canCreate = custodia_user_has_permission($pdo, $user, 'create_clients');

$filters = ['is_active' => $_GET['status'] ?? ''];
$search = trim($_GET['q'] ?? '');

$listParams = custodia_listing_params(['name', 'is_active'], 'is_active', 'DESC');
$result = custodia_apply_listing($allClients, $listParams, $filters, $search, ['name']);

$pageTitle = 'Clients';
$activeNav = 'clients';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <div class="page-title">Clients</div>
  <?php if ($canCreate): ?>
    <div class="btn-group btn-group-sm">
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createClientModal">+ New Client</button>
      <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#bulkImportClientsModal">Bulk Import</button>
    </div>
  <?php endif; ?>
</div>

<?php if (empty($allClients)): ?>
  <div class="card"><div class="text-center text-muted py-5">No clients yet.</div></div>
<?php else: ?>
  <form method="get" action="clients.php" class="filter-bar">
    <div class="filter-col filter-col-search">
      <input class="form-control form-control-sm" name="q" placeholder="Search client name…" value="<?= e($search) ?>">
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <option value="1" <?= $filters['is_active'] === '1' ? 'selected' : '' ?>>Active</option>
        <option value="0" <?= $filters['is_active'] === '0' ? 'selected' : '' ?>>Deactivated</option>
      </select>
    </div>
    <div class="filter-col" style="flex: 0 0 auto;"><button class="btn btn-sm btn-primary" type="submit">Filter</button></div>
  </form>

  <div class="card">
  <?php if (empty($result['rows'])): ?>
    <div class="text-center text-muted py-5">No clients match these filters.</div>
  <?php else: ?>
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th><?= custodia_sort_link('Client', 'name', $listParams) ?></th>
          <th><?= custodia_sort_link('Status', 'is_active', $listParams) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($result['rows'] as $c): ?>
          <tr class="table-clickable-row" onclick="window.location='client.php?id=<?= e($c['id']) ?>'">
            <td class="fw-semibold"><?= e($c['name']) ?></td>
            <td><?= $c['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Deactivated</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
  <?= custodia_pagination_bar($result) ?>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="modal fade" id="createClientModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">New Client</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="createClientForm" data-action-url="actions/create_client.php">
        <div class="modal-body">
          <div class="form-error alert alert-danger d-none"></div>
          <div class="mb-3">
            <label class="form-label">Client Name</label>
            <input class="form-control" name="name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email <span class="text-muted small">(optional)</span></label>
            <input type="email" class="form-control" name="email">
          </div>
          <div class="mb-3">
            <label class="form-label">Contact <span class="text-muted small">(optional)</span></label>
            <input class="form-control" name="phone">
          </div>
          <div class="mb-3">
            <label class="form-label">Address <span class="text-muted small">(optional)</span></label>
            <textarea class="form-control" name="address"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary w-100">Create Client</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
custodiaWireActionForm(document.getElementById('createClientForm'), (data) => {
  window.location = 'client.php?id=' + encodeURIComponent(data.id);
});
</script>

<div class="modal fade" id="bulkImportClientsModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Bulk Import Clients</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form id="bulkImportClientsForm">
        <div class="modal-body">
          <div class="form-error alert alert-danger d-none"></div>
          <p class="small text-muted">
            CSV file, first row a header with these column names: <code>name</code> (required),
            <code>email</code>, <code>phone</code>, <code>address</code>. —
            <a href="actions/download_client_import_template.php">Download template</a>
          </p>
          <div class="mb-3">
            <label class="form-label">CSV File</label>
            <input type="file" class="form-control" name="file" accept=".csv,text/csv" required>
          </div>
          <div id="bulkImportClientsResult"></div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary w-100">Import</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
document.getElementById('bulkImportClientsForm').addEventListener('submit', async (evt) => {
  evt.preventDefault();
  const form = evt.target;
  const errorBox = form.querySelector('.form-error');
  const resultBox = document.getElementById('bulkImportClientsResult');
  errorBox.classList.add('d-none');
  resultBox.innerHTML = '';
  const submitBtn = form.querySelector('button[type="submit"]');
  submitBtn.disabled = true;
  submitBtn.textContent = 'Importing…';
  try {
    const data = await custodiaPostMultipart('actions/bulk_import_clients.php', new FormData(form));
    let html = `<div class="alert alert-success">Imported ${data.created.length} client${data.created.length === 1 ? '' : 's'}.</div>`;
    if (data.skipped.length > 0) {
      html += `<div class="alert alert-warning"><strong>${data.skipped.length} row${data.skipped.length === 1 ? '' : 's'} skipped:</strong><ul class="mb-0">`;
      html += data.skipped.map(s => `<li>Row ${s.row} (${custodiaEscapeHtml(s.name) || 'no name'}): ${custodiaEscapeHtml(s.reason)}</li>`).join('');
      html += '</ul></div>';
    }
    if (data.created.length > 0) {
      html += '<button type="button" class="btn btn-primary w-100" onclick="window.location.reload()">Close &amp; Refresh List</button>';
    }
    resultBox.innerHTML = html;
    form.querySelector('[name="file"]').closest('.mb-3').classList.add('d-none');
    submitBtn.classList.add('d-none');
  } catch (err) {
    errorBox.textContent = err.message;
    errorBox.classList.remove('d-none');
    submitBtn.disabled = false;
    submitBtn.textContent = 'Import';
  }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
