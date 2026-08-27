<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/matters.php';
require_once __DIR__ . '/includes/clients.php';
require_once __DIR__ . '/includes/practice_groups.php';
require_once __DIR__ . '/includes/access_requests.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/physical_files.php';
require_once __DIR__ . '/includes/digital_documents.php';
require_once __DIR__ . '/includes/audit_query.php';
require_once __DIR__ . '/includes/listing.php';

$user = custodia_require_login();
$pdo = custodia_db();

$matterId = $_GET['id'] ?? '';
if ($matterId === '') {
    header('Location: matters.php');
    exit;
}

try {
    $matter = custodia_matter_detail($pdo, $user, $matterId, custodia_client_ip());
} catch (CustodiaHttpException $e) {
    http_response_code($e->status);
    $pageTitle = 'Matter';
    $activeNav = 'matters';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="alert alert-danger">' . e($e->getMessage()) . '</div>';

    // Ethical-wall and guest-account denials are absolute — no access request
    // can lift them (see includes/matter_access.php) — so only offer the
    // request-access flow for every other 403 (confidentiality tier / not
    // assigned), both of which an approved AccessRequest does resolve.
    $canRequestAccess = $e->status === 403
        && $user['role'] !== 'GUEST_AUDITOR'
        && strpos($e->getMessage(), 'ethically walled') === false;

    if ($canRequestAccess) {
        require_once __DIR__ . '/includes/access_requests.php';
        $existing = custodia_find_own_latest_access_request($pdo, $user['id'], 'MATTER', $matterId);
        if ($existing && $existing['status'] === 'PENDING') {
?>
          <div class="alert alert-info">Your access request is pending review by the managing partner or Records Manager.</div>
<?php
        } else {
?>
          <div class="card" style="max-width: 480px;">
            <div class="card-body">
              <h6 class="card-title">Request Access</h6>
              <?php if ($existing && $existing['status'] === 'DENIED'): ?>
                <p class="small text-muted">A previous request for this matter was denied. You may submit a new one.</p>
              <?php endif; ?>
              <form id="requestAccessForm">
                <div class="form-error alert alert-danger d-none"></div>
                <div class="mb-3">
                  <label class="form-label">Reason</label>
                  <textarea class="form-control" id="requestAccessReason" required></textarea>
                </div>
                <button type="submit" class="btn btn-primary w-100">Submit Request</button>
              </form>
            </div>
          </div>
          <script>
          document.getElementById('requestAccessForm').addEventListener('submit', async (evt) => {
            evt.preventDefault();
            const errorBox = document.querySelector('#requestAccessForm .form-error');
            try {
              await custodiaPost('actions/create_access_request.php', {
                entityType: 'MATTER',
                entityId: <?= json_encode($matterId) ?>,
                requestType: 'VIEW_CONFIDENTIAL',
                reason: document.getElementById('requestAccessReason').value,
              });
              custodiaFlash('Access request submitted.');
              window.location.reload();
            } catch (err) {
              errorBox.textContent = err.message;
              errorBox.classList.remove('d-none');
            }
          });
          </script>
<?php
        }
    }

    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$tab = $_GET['tab'] ?? 'physical';
$tabs = ['overview' => 'Overview', 'physical' => 'Physical Files', 'documents' => 'Digital Documents', 'team' => 'Team & Access', 'audit' => 'Audit Trail'];
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}

$locations = custodia_list_physical_locations($pdo);
$usersStmt = $pdo->query('SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name');
$allUsers = $usersStmt->fetchAll();

$clientsForPicker = custodia_list_clients($pdo, $user);
usort($clientsForPicker, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

$practiceAreasForPicker = custodia_list_practice_groups($pdo);

$pageTitle = $matter['matter_number'];
$activeNav = 'matters';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-breadcrumb"><a href="matters.php">Matters</a> / <?= e($matter['matter_number']) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
  <div>
    <div class="d-flex align-items-center gap-2">
      <span class="page-title mb-0"><?= e($matter['client_name']) ?></span>
      <?= custodia_confidentiality_badge($matter['confidentiality']) ?>
      <?= custodia_status_badge($matter['status']) ?>
    </div>
    <div class="text-muted small mt-1">
      Client: <a href="client.php?id=<?= e($matter['client_id']) ?>"><?= e($matter['client_name']) ?></a> · Practice Area: <?= e($matter['practice_area']) ?> · Opened <?= custodia_format_date($matter['open_date']) ?>
    </div>
  </div>
  <?php if ($matter['managing_partner']): ?>
    <div class="d-flex align-items-center gap-2">
      <div class="text-end">
        <div class="small text-muted">Managing Partner</div>
        <div class="fw-semibold"><?= e($matter['managing_partner']['full_name']) ?></div>
      </div>
      <div class="avatar-circle" style="background: var(--cus-sidebar-bg);"><?= e(custodia_initials($matter['managing_partner']['full_name'])) ?></div>
    </div>
  <?php endif; ?>
</div>
<ul class="nav nav-tabs mb-3">
  <?php foreach ($tabs as $key => $label): ?>
    <li class="nav-item">
      <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="matter.php?id=<?= e($matterId) ?>&tab=<?= e($key) ?>"><?= e($label) ?></a>
    </li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
  <div class="row g-3">
    <div class="col-md-6">
      <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Matter Details</span>
          <div class="btn-group btn-group-sm">
            <?php if (custodia_user_has_permission($pdo, $user, 'edit_matters')): ?>
              <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editMatterModal">Edit</button>
            <?php endif; ?>
            <?php if (custodia_user_has_permission($pdo, $user, 'deactivate_matters')): ?>
              <?php if ($matter['status'] !== 'CLOSED'): ?>
                <button class="btn btn-outline-danger" onclick="deactivateMatter()">Deactivate</button>
              <?php else: ?>
                <button class="btn btn-outline-success" onclick="reactivateMatter()">Reactivate</button>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="card-body">
          <dl class="row mb-0 small">
            <dt class="col-5 text-muted">Matter Number</dt><dd class="col-7"><?= e($matter['matter_number']) ?></dd>
            <dt class="col-5 text-muted">Client</dt><dd class="col-7"><a href="client.php?id=<?= e($matter['client_id']) ?>"><?= e($matter['client_name']) ?></a></dd>
            <dt class="col-5 text-muted">Practice Area</dt><dd class="col-7"><?= e($matter['practice_area']) ?></dd>
            <dt class="col-5 text-muted">Status</dt><dd class="col-7"><?= custodia_status_badge($matter['status']) ?></dd>
            <dt class="col-5 text-muted">Confidentiality</dt><dd class="col-7"><?= custodia_confidentiality_badge($matter['confidentiality']) ?></dd>
            <dt class="col-5 text-muted">Opened</dt><dd class="col-7"><?= custodia_format_date($matter['open_date']) ?></dd>
            <?php if ($matter['close_date']): ?>
              <dt class="col-5 text-muted">Closed</dt><dd class="col-7"><?= custodia_format_date($matter['close_date']) ?></dd>
            <?php endif; ?>
          </dl>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card">
        <div class="card-header bg-white fw-semibold">Ethical Walls</div>
        <div class="card-body">
          <?php if (empty($matter['ethical_walls_detail'])): ?>
            <div class="text-muted small">No ethical walls on this matter.</div>
          <?php else: ?>
            <?php foreach ($matter['ethical_walls_detail'] as $w): ?>
              <div class="border-bottom py-2">
                <div class="fw-semibold small"><?= e($w['full_name']) ?></div>
                <div class="text-muted small"><?= e($w['reason']) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if (custodia_user_has_permission($pdo, $user, 'edit_matters')): ?>
  <div class="modal fade" id="editMatterModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Edit Matter Details</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="editMatterForm" data-action-url="actions/update_matter.php">
      <input type="hidden" name="matterId" value="<?= e($matterId) ?>">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">Matter Number</label><input class="form-control" name="matterNumber" value="<?= e($matter['matter_number']) ?>" required></div>
        <div class="mb-3"><label class="form-label">Client</label>
          <select class="form-select" name="clientId" required>
            <?php foreach ($clientsForPicker as $c): ?>
              <option value="<?= e($c['id']) ?>" <?= $c['id'] === $matter['client_id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['is_active'] ? '' : ' (Deactivated)' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Practice Area</label>
          <select class="form-select" name="practiceArea" required>
            <?php $currentPaListed = false; ?>
            <?php foreach ($practiceAreasForPicker as $pa): ?>
              <?php if ($pa['name'] === $matter['practice_area']) $currentPaListed = true; ?>
              <option value="<?= e($pa['name']) ?>" <?= $pa['name'] === $matter['practice_area'] ? 'selected' : '' ?>><?= e($pa['name']) ?></option>
            <?php endforeach; ?>
            <?php if (!$currentPaListed): ?>
              <option value="<?= e($matter['practice_area']) ?>" selected><?= e($matter['practice_area']) ?> (not in catalog)</option>
            <?php endif; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Managing Partner</label>
          <select class="form-select" name="managingPartnerId" required>
            <?php foreach ($allUsers as $u): ?>
              <option value="<?= e($u['id']) ?>" <?= $u['id'] === $matter['managing_partner_id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?> (<?= e(custodia_role_label($pdo, $u['role'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="row">
          <div class="col-6 mb-3"><label class="form-label">Status</label>
            <select class="form-select" name="status">
              <?php foreach (['ACTIVE' => 'Active', 'ON_HOLD' => 'On Hold', 'CLOSED' => 'Closed', 'ARCHIVED' => 'Archived'] as $val => $label): ?>
                <option value="<?= e($val) ?>" <?= $matter['status'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 mb-3"><label class="form-label">Confidentiality</label>
            <select class="form-select" name="confidentiality">
              <?php foreach (['STANDARD', 'RESTRICTED', 'PRIVILEGED'] as $val): ?>
                <option value="<?= e($val) ?>" <?= $matter['confidentiality'] === $val ? 'selected' : '' ?>><?= e($val) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mb-3"><label class="form-label">Close Date</label><input type="date" class="form-control" name="closeDate" value="<?= e($matter['close_date'] ? substr($matter['close_date'], 0, 10) : '') ?>"></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Save Changes</button></div>
    </form>
  </div></div></div>
  <script>custodiaWireActionForm(document.getElementById('editMatterForm'), () => window.location.reload());</script>
  <?php endif; ?>

  <?php if (custodia_user_has_permission($pdo, $user, 'deactivate_matters')): ?>
  <script>
  async function deactivateMatter() {
    try {
      await custodiaPost('actions/deactivate_matter.php', { matterId: <?= json_encode($matterId) ?> });
      custodiaFlash('Matter deactivated.');
      window.location.reload();
    } catch (err) { custodiaFlash(err.message, 'danger'); }
  }

  async function reactivateMatter() {
    try {
      await custodiaPost('actions/reactivate_matter.php', { matterId: <?= json_encode($matterId) ?> });
      custodiaFlash('Matter reactivated.');
      window.location.reload();
    } catch (err) { custodiaFlash(err.message, 'danger'); }
  }
  </script>
  <?php endif; ?>

<?php elseif ($tab === 'physical'):
  $allFiles = custodia_list_files_for_matter($pdo, $user, $matterId);
  $canRegister = custodia_user_has_permission($pdo, $user, 'register_physical_files');
  $canOverrideCustody = custodia_user_has_permission($pdo, $user, 'override_custody');
  $canClose = custodia_user_has_permission($pdo, $user, 'close_physical_files');

  $fileFilters = ['status' => $_GET['status'] ?? '', 'lifecycle_status' => $_GET['lifecycleStatus'] ?? ''];
  $fileSearch = trim($_GET['q'] ?? '');
  $fileListParams = custodia_listing_params(['barcode', 'jacket_label', 'status', 'lifecycle_status', 'created_at'], 'created_at', 'ASC');
  $fileResult = custodia_apply_listing($allFiles, $fileListParams, $fileFilters, $fileSearch, ['barcode', 'jacket_label']);
  ?>
  <div class="card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
      <span class="fw-semibold">Physical Files &amp; Boxes</span>
      <?php if ($canRegister): ?>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#registerFileModal">+ Register New File</button>
      <?php endif; ?>
    </div>
    <?php if (empty($allFiles)): ?>
      <div class="text-center text-muted py-5">No physical files registered for this matter yet.</div>
    <?php else: ?>
      <div class="card-body pb-0">
        <form method="get" action="matter.php" class="filter-bar">
          <input type="hidden" name="id" value="<?= e($matterId) ?>">
          <input type="hidden" name="tab" value="physical">
          <div class="filter-col filter-col-search">
            <input class="form-control form-control-sm" name="q" placeholder="Search physical file number, jacket/box…" value="<?= e($fileSearch) ?>">
          </div>
          <div class="filter-col">
            <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
              <option value="">All custody statuses</option>
              <?php foreach (['IN_REGISTRY', 'CHECKED_OUT', 'IN_TRANSIT', 'OFFSITE_ARCHIVE', 'PENDING_DESTRUCTION', 'DESTROYED'] as $val): ?>
                <option value="<?= e($val) ?>" <?= $fileFilters['status'] === $val ? 'selected' : '' ?>><?= e(CUSTODIA_STATUS_LABEL_OVERRIDES[$val] ?? ucwords(strtolower(str_replace('_', ' ', $val)))) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="filter-col">
            <select class="form-select form-select-sm" name="lifecycleStatus" onchange="this.form.submit()">
              <option value="">All statuses</option>
              <option value="OPEN" <?= $fileFilters['lifecycle_status'] === 'OPEN' ? 'selected' : '' ?>>Open</option>
              <option value="CLOSED" <?= $fileFilters['lifecycle_status'] === 'CLOSED' ? 'selected' : '' ?>>Closed</option>
            </select>
          </div>
          <div class="filter-col" style="flex: 0 0 auto;"><button class="btn btn-sm btn-primary" type="submit">Filter</button></div>
        </form>
      </div>
    <?php endif; ?>
    <?php if (!empty($allFiles) && empty($fileResult['rows'])): ?>
      <div class="text-center text-muted py-5">No physical files match these filters.</div>
    <?php elseif (!empty($fileResult['rows'])): ?>
      <table class="table table-hover mb-0 align-middle">
        <thead>
          <tr>
            <th><?= custodia_sort_link('Physical File Number', 'barcode', $fileListParams) ?></th>
            <th><?= custodia_sort_link('Jacket / Box', 'jacket_label', $fileListParams) ?></th>
            <th><?= custodia_sort_link('Custody', 'status', $fileListParams) ?></th>
            <th><?= custodia_sort_link('Status', 'lifecycle_status', $fileListParams) ?></th>
            <th>Location / Custodian</th>
            <th>Last Movement</th>
            <th>Linked Digital File</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fileResult['rows'] as $f): ?>
            <tr>
              <td class="barcode-display small"><?= e($f['barcode']) ?></td>
              <td><?= e($f['jacket_label']) ?></td>
              <td><?= custodia_status_badge($f['status']) ?></td>
              <td><?= custodia_status_badge($f['lifecycle_status']) ?></td>
              <td class="text-muted small">
                <?php if ($f['status'] === 'CHECKED_OUT'): ?>
                  <?= e($f['custodian_name'] ?? '—') ?>
                <?php else: ?>
                  <?= e(trim(($f['building'] ?? '') . ' ' . ($f['room'] ?? ''))) ?: '—' ?>
                <?php endif; ?>
              </td>
              <td class="small <?= str_contains(custodia_last_movement_label($f), 'overdue') ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= e(custodia_last_movement_label($f)) ?></td>
              <td class="small text-muted">
                <?php if ((int) $f['linked_doc_count'] === 0): ?>
                  —
                <?php elseif ((int) $f['linked_doc_count'] === 1): ?>
                  <?= e(custodia_doc_label((int) $f['linked_doc_number']) . ' — ' . $f['linked_doc_title']) ?>
                <?php else: ?>
                  <?= (int) $f['linked_doc_count'] ?> linked
                <?php endif; ?>
              </td>
              <td class="text-end">
                <div class="btn-group btn-group-sm">
                  <?php if ($f['status'] === 'IN_REGISTRY'): ?>
                    <button class="btn btn-outline-primary" onclick="openCheckoutModal('<?= e($f['id']) ?>', '<?= e($f['barcode']) ?>')">Issue</button>
                  <?php elseif ($f['status'] === 'CHECKED_OUT' && $f['current_custodian_id'] === $user['id']): ?>
                    <button class="btn btn-outline-success" onclick="openCheckinModal('<?= e($f['id']) ?>', '<?= e($f['barcode']) ?>')">Return</button>
                  <?php endif; ?>
                  <?php if ($f['status'] === 'CHECKED_OUT' && $f['current_custodian_id'] !== $user['id']): ?>
                    <button class="btn btn-outline-secondary" onclick="openTransferModal('<?= e($f['id']) ?>', '<?= e($f['barcode']) ?>')">Request Transfer</button>
                  <?php endif; ?>
                  <?php if ($canOverrideCustody && $f['status'] === 'CHECKED_OUT'): ?>
                    <button class="btn btn-outline-danger" onclick="openCheckinModal('<?= e($f['id']) ?>', '<?= e($f['barcode']) ?>', true)">Override</button>
                  <?php endif; ?>
                  <?php if ($canClose && $f['lifecycle_status'] === 'OPEN'): ?>
                    <button class="btn btn-outline-danger" onclick="closePhysicalFile('<?= e($f['id']) ?>')">Close</button>
                  <?php elseif ($canClose): ?>
                    <button class="btn btn-outline-success" onclick="reopenPhysicalFile('<?= e($f['id']) ?>')">Reopen</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php if (!empty($fileResult['rows'])): ?><?= custodia_pagination_bar($fileResult) ?><?php endif; ?>

  <?php if ($canClose): ?>
  <script>
  async function closePhysicalFile(fileId) {
    try {
      await custodiaPost('actions/close_physical_file.php', { fileId });
      custodiaFlash('Physical file closed.');
      window.location.reload();
    } catch (err) { custodiaFlash(err.message, 'danger'); }
  }

  async function reopenPhysicalFile(fileId) {
    try {
      await custodiaPost('actions/reopen_physical_file.php', { fileId });
      custodiaFlash('Physical file reopened.');
      window.location.reload();
    } catch (err) { custodiaFlash(err.message, 'danger'); }
  }
  </script>
  <?php endif; ?>

  <?php require __DIR__ . '/includes/custody_modals.php'; ?>

  <?php if ($canRegister): ?>
  <div class="modal fade" id="registerFileModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Register Physical File</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
      <form id="registerFileForm" data-action-url="actions/register_file.php">
        <input type="hidden" name="matterId" value="<?= e($matterId) ?>">
        <div class="modal-body">
          <div class="form-error alert alert-danger d-none"></div>
          <div class="mb-3"><label class="form-label">Jacket Label</label><input class="form-control" name="jacketLabel" required></div>
          <div class="mb-3"><label class="form-label">Physical File Number (leave blank to auto-generate)</label><input class="form-control" name="barcode"></div>
          <div class="mb-3"><label class="form-label">Location</label>
            <select class="form-select" name="locationId">
              <option value="">— None —</option>
              <?php foreach ($locations as $loc): ?>
                <option value="<?= e($loc['id']) ?>"><?= e($loc['building']) ?> / <?= e($loc['room']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Register</button></div>
      </form>
    </div></div>
  </div>
  <script>
  custodiaWireActionForm(document.getElementById('registerFileForm'), () => window.location.reload());
  </script>
  <?php endif; ?>

<?php elseif ($tab === 'documents'):
  $allDocs = custodia_list_documents_for_matter($pdo, $user, $matterId);
  $docTypes = array_values(array_unique(array_column($allDocs, 'doc_type')));
  sort($docTypes);

  $docFilters = ['doc_type' => $_GET['docType'] ?? '', 'confidentiality' => $_GET['confidentiality'] ?? ''];
  $docSearch = trim($_GET['q'] ?? '');
  $docListParams = custodia_listing_params(['doc_number', 'title', 'doc_type', 'confidentiality', 'current_version_no', 'created_at'], 'created_at', 'DESC');
  $docResult = custodia_apply_listing($allDocs, $docListParams, $docFilters, $docSearch, ['title', 'description', 'author_name']);
  $matterPhysicalFiles = custodia_list_files_for_matter($pdo, $user, $matterId);
  $canManageProtection = custodia_user_has_permission($pdo, $user, 'override_document_protection');
  $canGrantDocAccess = custodia_can_decide_matter_access($pdo, $user, $matterId);
  ?>
  <div class="d-flex justify-content-end mb-2">
    <?php if ($user['role'] !== 'GUEST_AUDITOR'): ?>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createDocModal">+ New Document</button>
    <?php endif; ?>
  </div>
  <?php if (!empty($allDocs)): ?>
  <form method="get" action="matter.php" class="filter-bar">
    <input type="hidden" name="id" value="<?= e($matterId) ?>">
    <input type="hidden" name="tab" value="documents">
    <div class="filter-col filter-col-search">
      <input class="form-control form-control-sm" name="q" placeholder="Search title, description, author…" value="<?= e($docSearch) ?>">
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="docType" onchange="this.form.submit()">
        <option value="">All types</option>
        <?php foreach ($docTypes as $dt): ?>
          <option value="<?= e($dt) ?>" <?= $docFilters['doc_type'] === $dt ? 'selected' : '' ?>><?= e($dt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="confidentiality" onchange="this.form.submit()">
        <option value="">All confidentiality</option>
        <?php foreach (['STANDARD', 'RESTRICTED', 'PRIVILEGED'] as $val): ?>
          <option value="<?= e($val) ?>" <?= $docFilters['confidentiality'] === $val ? 'selected' : '' ?>><?= e($val) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col" style="flex: 0 0 auto;"><button class="btn btn-sm btn-primary" type="submit">Filter</button></div>
  </form>
  <?php endif; ?>
  <div class="card">
    <?php if (empty($allDocs)): ?>
      <div class="text-center text-muted py-5">No digital documents for this matter yet.</div>
    <?php elseif (empty($docResult['rows'])): ?>
      <div class="text-center text-muted py-5">No documents match these filters.</div>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th><?= custodia_sort_link('Doc #', 'doc_number', $docListParams) ?></th>
            <th><?= custodia_sort_link('Title', 'title', $docListParams) ?></th>
            <th>Linked Physical File</th>
            <th>Author</th>
            <th><?= custodia_sort_link('Type', 'doc_type', $docListParams) ?></th>
            <th><?= custodia_sort_link('Confidentiality', 'confidentiality', $docListParams) ?></th>
            <th><?= custodia_sort_link('Version', 'current_version_no', $docListParams) ?></th>
            <th>Search Index</th>
            <th>Lock</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($docResult['rows'] as $d): ?>
            <tr>
              <td class="small text-muted mono"><?= e(custodia_doc_label((int) $d['doc_number'], (int) $d['current_version_no'])) ?></td>
              <td class="fw-semibold"><?= e($d['title']) ?></td>
              <td class="small text-muted"><?= $d['linked_file_barcode'] ? e($d['linked_file_barcode'] . ' — ' . $d['linked_file_jacket_label']) : '—' ?></td>
              <td class="small"><?= e($d['author_name'] ?? '—') ?></td>
              <td><?= e($d['doc_type']) ?></td>
              <?php
                $isRestrictedShareRow = !($canManageProtection || in_array($user['role'], custodia_firm_wide_roles(), true)) && custodia_document_actor_is_restricted($pdo, $user, $d);
              ?>
              <td><?= custodia_confidentiality_badge($d['confidentiality']) ?><?php if ($d['is_protected']): ?> <?= custodia_protected_badge() ?><?php endif; ?><?php if ($isRestrictedShareRow): ?> <?= custodia_shared_view_only_badge() ?><?php endif; ?></td>
              <td>v<?= (int) $d['current_version_no'] ?></td>
              <td class="small text-muted"><?= e(custodia_extraction_status_label($d['latest_version']['extraction_status'] ?? 'PENDING')) ?></td>
              <td class="small text-muted"><?= $d['active_lock'] ? ('🔒 ' . e($d['active_lock']['full_name'])) : '—' ?></td>
              <td class="text-end">
                <?php
                  $duration = custodia_format_duration($d['latest_version']['duration_seconds'] ?? null);
                  $isProtectedDoc = (bool) $d['is_protected'];
                  $canBypassProtection = $canManageProtection || in_array($user['role'], custodia_firm_wide_roles(), true);
                  $hasViewGrant = $isProtectedDoc && custodia_user_has_active_document_view_grant($pdo, $user['id'], $d['id']);
                  $canViewInline = !$isProtectedDoc || $canBypassProtection || $hasViewGrant;
                  $isRestrictedShare = $isRestrictedShareRow;
                  $canDownloadDoc = $canBypassProtection || (!$isProtectedDoc && !$isRestrictedShare);
                  $isRestrictedView = $isProtectedDoc || $isRestrictedShare;
                ?>
                <?php if ($duration): ?><span class="small text-muted mono me-2"><?= e($duration) ?></span><?php endif; ?>
                <div class="btn-group btn-group-sm">
                  <?php if ((int) $d['current_version_no'] >= 1): ?>
                    <?php if (custodia_is_previewable_mime($d['latest_version']['mime_type'] ?? null)): ?>
                      <?php if ($canViewInline): ?>
                        <button class="btn btn-outline-secondary" onclick="openPreviewModal('<?= e($d['id']) ?>', '<?= e($d['latest_version']['mime_type']) ?>', '<?= e(addslashes($d['title'])) ?>', '<?= e($duration ?? '') ?>', <?= $isRestrictedView ? 'true' : 'false' ?>)">Preview</button>
                      <?php else: ?>
                        <button class="btn btn-outline-warning" onclick="openRequestDocAccessModal('<?= e($d['id']) ?>', '<?= e(addslashes($d['title'])) ?>')">Request Access</button>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($canDownloadDoc): ?>
                      <a class="btn btn-outline-secondary" href="actions/download_document.php?documentId=<?= e($d['id']) ?>">Download</a>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if (!$isRestrictedShare): ?>
                  <button class="btn btn-outline-primary" onclick="openUploadModal('<?= e($d['id']) ?>', '<?= e(addslashes($d['title'])) ?>')">Upload Version</button>
                  <?php endif; ?>
                  <?php if (!$d['active_lock']): ?>
                    <button class="btn btn-outline-secondary" onclick="lockDocument('<?= e($d['id']) ?>')">Lock</button>
                  <?php elseif ($d['active_lock']['user_id'] === $user['id'] || custodia_user_has_permission($pdo, $user, 'override_document_locks')): ?>
                    <button class="btn btn-outline-secondary" onclick="unlockDocument('<?= e($d['id']) ?>')">Release Lock</button>
                  <?php endif; ?>
                  <?php if ((int) $d['current_version_no'] >= 2): ?>
                    <a class="btn btn-outline-secondary" href="document_compare.php?documentId=<?= e($d['id']) ?>">Compare</a>
                  <?php endif; ?>
                  <?php if (!$isRestrictedShare): ?>
                  <button class="btn btn-outline-secondary" onclick="openShareModal('<?= e($d['id']) ?>')">Share Link</button>
                  <button class="btn btn-outline-secondary" onclick='openEditProfileModal(<?= json_encode([
                      "id" => $d["id"], "label" => custodia_doc_label((int) $d["doc_number"]), "title" => $d["title"],
                      "docType" => $d["doc_type"], "description" => $d["description"], "confidentiality" => $d["confidentiality"],
                      "authorId" => $d["author_id"], "linkedPhysicalFileId" => $d["linked_physical_file_id"],
                  ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit Profile</button>
                  <?php endif; ?>
                  <?php if ($canManageProtection): ?>
                    <?php if ($isProtectedDoc): ?>
                      <button class="btn btn-outline-danger" onclick="protectDocument('<?= e($d['id']) ?>', false)">Unprotect</button>
                    <?php else: ?>
                      <button class="btn btn-outline-secondary" onclick="protectDocument('<?= e($d['id']) ?>', true)">Protect</button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($isProtectedDoc && $canGrantDocAccess): ?>
                    <button class="btn btn-outline-secondary" onclick="openGrantDocAccessModal('<?= e($d['id']) ?>', '<?= e(addslashes($d['title'])) ?>')">Grant Access</button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php if (!empty($docResult['rows'])): ?><?= custodia_pagination_bar($docResult) ?><?php endif; ?>

  <?php require __DIR__ . '/includes/document_modals.php'; ?>

<?php elseif ($tab === 'team'):
  $canManageTeam = in_array($user['role'], ['SYSTEM_ADMIN', 'RECORDS_MANAGER'], true) || $matter['managing_partner_id'] === $user['id'];
  $rolesForPicker = custodia_list_roles($pdo);
  $canManageGroupGrants = custodia_user_has_permission($pdo, $user, 'manage_practice_groups');
  $canGrantIndividualAccess = custodia_can_decide_matter_access($pdo, $user, $matterId);
  $groupGrants = custodia_list_matter_group_grants($pdo, $matterId);
  $individualGrants = custodia_list_matter_individual_grants($pdo, $matterId);
  $groupIdsWithGrant = array_column($groupGrants, 'practice_group_id');
  $groupsAvailableToGrant = array_values(array_filter(custodia_list_practice_groups($pdo), fn ($g) => !in_array($g['id'], $groupIdsWithGrant, true)));
  ?>
  <div class="row g-3">
    <div class="col-md-7">
      <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Team</span>
          <?php if ($canManageTeam): ?>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addTeamModal">+ Add Member</button>
          <?php endif; ?>
        </div>
        <div class="card-body p-0">
          <table class="table mb-0 align-middle">
            <tbody>
              <?php foreach ($matter['team_detail'] as $t): ?>
                <tr>
                  <td class="fw-semibold small"><?= e($t['full_name']) ?></td>
                  <td class="text-muted small"><?= e($t['role_on_matter']) ?></td>
                  <td class="text-muted small"><?= e(custodia_role_label($pdo, $t['role'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-md-5">
      <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Ethical Walls</span>
          <?php if (in_array($user['role'], ['SYSTEM_ADMIN', 'RECORDS_MANAGER'], true)): ?>
            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#addWallModal">+ Add Wall</button>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (empty($matter['ethical_walls_detail'])): ?>
            <div class="text-muted small">None.</div>
          <?php else: ?>
            <?php foreach ($matter['ethical_walls_detail'] as $w): ?>
              <div class="border-bottom py-2 small"><strong><?= e($w['full_name']) ?></strong><br><?= e($w['reason']) ?></div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-md-6">
      <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Practice Groups with Access</span>
          <?php if ($canManageGroupGrants && !empty($groupsAvailableToGrant)): ?>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#grantGroupAccessModal">+ Grant Access</button>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (empty($groupGrants)): ?>
            <div class="text-muted small">No practice group has been granted access to this matter.</div>
          <?php else: ?>
            <?php foreach ($groupGrants as $g): ?>
              <div class="border-bottom py-2 small d-flex justify-content-between align-items-start">
                <div>
                  <strong><?= e($g['group_name']) ?></strong><br>
                  <span class="text-muted">Granted by <?= e($g['granted_by_name']) ?> · <?= custodia_format_date($g['created_at']) ?></span>
                </div>
                <?php if ($canManageGroupGrants): ?>
                  <button class="btn btn-sm btn-outline-danger" onclick="revokeGroupAccess('<?= e($g['id']) ?>', '<?= e(addslashes($g['group_name'])) ?>')">Revoke</button>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Individually Granted Access</span>
          <?php if ($canGrantIndividualAccess): ?>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#grantUserAccessModal">+ Grant Access</button>
          <?php endif; ?>
        </div>
        <div class="card-body">
          <?php if (empty($individualGrants)): ?>
            <div class="text-muted small">No individual has been granted direct access to this matter.</div>
          <?php else: ?>
            <?php foreach ($individualGrants as $g): ?>
              <div class="border-bottom py-2 small d-flex justify-content-between align-items-start">
                <div>
                  <strong><?= e($g['grantee_name']) ?></strong><br>
                  <span class="text-muted">Approved by <?= e($g['approver_name']) ?> · <?= custodia_format_date($g['decided_at']) ?></span><br>
                  <span class="fst-italic">"<?= e($g['reason']) ?>"</span>
                </div>
                <?php if ($canGrantIndividualAccess): ?>
                  <button class="btn btn-sm btn-outline-danger" onclick="revokeUserAccess('<?= e($g['id']) ?>', '<?= e(addslashes($g['grantee_name'])) ?>')">Revoke</button>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($canManageGroupGrants && !empty($groupsAvailableToGrant)): ?>
  <div class="modal fade" id="grantGroupAccessModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Grant a Practice Group Access</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="grantGroupAccessForm" data-action-url="actions/grant_group_matter_access.php">
      <input type="hidden" name="matterId" value="<?= e($matterId) ?>">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">Practice Group</label>
          <select class="form-select" name="practiceGroupId" required>
            <?php foreach ($groupsAvailableToGrant as $g): ?><option value="<?= e($g['id']) ?>"><?= e($g['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Reason <span class="text-muted small">(optional)</span></label><textarea class="form-control" name="reason"></textarea></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Grant Access</button></div>
    </form>
  </div></div></div>
  <script>custodiaWireActionForm(document.getElementById('grantGroupAccessForm'), () => window.location.reload());</script>
  <?php endif; ?>

  <?php if ($canGrantIndividualAccess): ?>
  <div class="modal fade" id="grantUserAccessModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Grant a User Access</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="grantUserAccessForm" data-action-url="actions/grant_matter_access.php">
      <input type="hidden" name="matterId" value="<?= e($matterId) ?>">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">User</label>
          <select class="form-select" name="userId" required>
            <?php foreach ($allUsers as $u): ?><option value="<?= e($u['id']) ?>"><?= e($u['full_name']) ?> (<?= e(custodia_role_label($pdo, $u['role'])) ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Reason</label><textarea class="form-control" name="reason" required></textarea></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Grant Access</button></div>
    </form>
  </div></div></div>
  <script>
  custodiaWireActionForm(document.getElementById('grantUserAccessForm'), () => window.location.reload());
  </script>
  <?php endif; ?>

  <?php if ($canManageGroupGrants || $canGrantIndividualAccess): ?>
  <script>
  async function revokeGroupAccess(grantId, groupName) {
    if (!confirm(`Revoke ${groupName}'s access to this matter?`)) return;
    try {
      await custodiaPost('actions/revoke_group_matter_access.php', { grantId });
      custodiaFlash('Access revoked.');
      window.location.reload();
    } catch (err) { custodiaFlash(err.message, 'danger'); }
  }
  async function revokeUserAccess(requestId, fullName) {
    if (!confirm(`Revoke ${fullName}'s access to this matter?`)) return;
    try {
      await custodiaPost('actions/revoke_matter_access.php', { requestId });
      custodiaFlash('Access revoked.');
      window.location.reload();
    } catch (err) { custodiaFlash(err.message, 'danger'); }
  }
  </script>
  <?php endif; ?>

  <?php if ($canManageTeam): ?>
  <div class="modal fade" id="addTeamModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Add Team Member</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="addTeamForm" data-action-url="actions/add_team_member.php">
      <input type="hidden" name="matterId" value="<?= e($matterId) ?>">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">User</label>
          <select class="form-select" name="userId" required>
            <?php foreach ($allUsers as $u): ?><option value="<?= e($u['id']) ?>"><?= e($u['full_name']) ?> (<?= e(custodia_role_label($pdo, $u['role'])) ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Role on Matter</label>
          <select class="form-select" name="roleOnMatter" required>
            <?php foreach ($rolesForPicker as $r): ?>
              <option value="<?= e($r['label']) ?>"><?= e($r['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Add</button></div>
    </form>
  </div></div></div>
  <script>custodiaWireActionForm(document.getElementById('addTeamForm'), () => window.location.reload());</script>
  <?php endif; ?>

  <?php if (in_array($user['role'], ['SYSTEM_ADMIN', 'RECORDS_MANAGER'], true)): ?>
  <div class="modal fade" id="addWallModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Add Ethical Wall</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="addWallForm" data-action-url="actions/create_ethical_wall.php">
      <input type="hidden" name="matterId" value="<?= e($matterId) ?>">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">User to Wall Off</label>
          <select class="form-select" name="userId" required>
            <?php foreach ($allUsers as $u): ?><option value="<?= e($u['id']) ?>"><?= e($u['full_name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Reason</label><textarea class="form-control" name="reason" required></textarea></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-danger w-100">Add Wall</button></div>
    </form>
  </div></div></div>
  <script>custodiaWireActionForm(document.getElementById('addWallForm'), () => window.location.reload());</script>
  <?php endif; ?>

<?php elseif ($tab === 'audit'):
  $auditListParams = custodia_listing_params([], 'created_at', 'DESC');
  $auditResult = custodia_audit_list($pdo, $user, ['matterId' => $matterId], $auditListParams['page'], $auditListParams['pageSize']);
  $auditResult['totalPages'] = max(1, (int) ceil($auditResult['total'] / $auditResult['pageSize']));
  ?>
  <div class="card">
    <?php if (empty($auditResult['entries'])): ?>
      <div class="text-center text-muted py-5">No audit entries for this matter yet.</div>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle small">
        <thead class="table-light"><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Reason</th></tr></thead>
        <tbody>
          <?php foreach ($auditResult['entries'] as $a): ?>
            <tr>
              <td class="text-muted"><?= custodia_format_datetime($a['created_at']) ?></td>
              <td><?= e($a['actor_name']) ?></td>
              <td><?= custodia_action_badge($a['action_type']) ?></td>
              <td class="text-muted"><?= e($a['entity_type']) ?></td>
              <td class="text-muted"><?= e($a['reason'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php if (!empty($auditResult['entries'])): ?><?= custodia_pagination_bar($auditResult) ?><?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
