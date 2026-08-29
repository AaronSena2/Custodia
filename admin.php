<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/retention_policies.php';
require_once __DIR__ . '/includes/users.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/practice_groups.php';
require_once __DIR__ . '/includes/physical_files.php';
require_once __DIR__ . '/includes/audit_query.php';
require_once __DIR__ . '/includes/listing.php';

$user = custodia_require_login();
custodia_require_role($user, ['SYSTEM_ADMIN', 'RECORDS_MANAGER']);
$pdo = custodia_db();

// manage_users gates both the User Accounts tab and this Permissions tab —
// see includes/permissions.php's docblock for why those two travel together.
$canManageUsers = custodia_user_has_permission($pdo, $user, 'manage_users');
$canManagePracticeGroups = custodia_user_has_permission($pdo, $user, 'manage_practice_groups');
$canManageLocations = custodia_user_has_permission($pdo, $user, 'manage_physical_locations');
$canViewAudit = custodia_user_has_permission($pdo, $user, 'view_audit_log');
$tab = $_GET['tab'] ?? 'retention';
if (($tab === 'users' || $tab === 'permissions') && !$canManageUsers) {
    $tab = 'retention'; // the tab links themselves are hidden without the permission; this guards a hand-typed URL too
}
if ($tab === 'practicegroups' && !$canManagePracticeGroups) {
    $tab = 'retention';
}
if ($tab === 'locations' && !$canManageLocations) {
    $tab = 'retention';
}
if ($tab === 'audit' && !$canViewAudit) {
    $tab = 'retention';
}

$policies = custodia_list_retention_policies($pdo);
$allRoles = ($tab === 'users' || $tab === 'permissions') ? custodia_list_roles($pdo) : [];
$permissionMatrix = $tab === 'permissions' ? custodia_list_role_permissions($pdo) : [];
$practiceGroups = $tab === 'practicegroups' ? custodia_list_practice_groups($pdo) : [];

$allPhysicalLocations = [];
$locationResult = ['rows' => [], 'total' => 0, 'page' => 1, 'pageSize' => 25, 'totalPages' => 1];
$locationFilters = ['location_type' => ''];
$locationSearch = '';
$locationListParams = [];
if ($tab === 'locations') {
    $allPhysicalLocations = custodia_list_physical_locations($pdo);
    $locationFilters = ['location_type' => $_GET['type'] ?? ''];
    $locationSearch = trim($_GET['q'] ?? '');
    $locationListParams = custodia_listing_params(['building', 'room', 'shelf', 'bin', 'location_type', 'file_count'], 'building', 'ASC');
    $locationResult = custodia_apply_listing($allPhysicalLocations, $locationListParams, $locationFilters, $locationSearch, ['building', 'room', 'shelf', 'bin']);
}

$auditQuery = ['actorId' => null, 'entityType' => null, 'actionType' => null, 'q' => null, 'from' => null, 'to' => null];
$auditResult = ['entries' => [], 'total' => 0, 'page' => 1, 'pageSize' => 25, 'totalPages' => 1];
$auditExportQuery = '';
$canExportAudit = false;
$canVerifyAudit = false;
$auditUsers = [];
$auditActionTypes = [];
if ($tab === 'audit') {
    $auditQuery = [
        'actorId' => trim($_GET['actorId'] ?? '') ?: null,
        'entityType' => trim($_GET['entityType'] ?? '') ?: null,
        'actionType' => trim($_GET['actionType'] ?? '') ?: null,
        'q' => trim($_GET['q'] ?? '') ?: null,
        'from' => trim($_GET['from'] ?? '') ?: null,
        'to' => trim($_GET['to'] ?? '') ?: null,
    ];
    $auditListParams = custodia_listing_params([], 'created_at', 'DESC');
    $auditResult = custodia_audit_list($pdo, $user, $auditQuery, $auditListParams['page'], $auditListParams['pageSize']);
    $auditResult['totalPages'] = max(1, (int) ceil($auditResult['total'] / $auditResult['pageSize']));
    $auditExportQuery = http_build_query(array_filter($auditQuery));
    $canExportAudit = custodia_user_has_permission($pdo, $user, 'export_audit_log');
    $canVerifyAudit = custodia_user_has_permission($pdo, $user, 'verify_audit_chain');
    $auditUsers = $pdo->query('SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll();
    $auditActionTypes = array_keys(CUSTODIA_ACTION_BADGE_COLORS);
    sort($auditActionTypes);
}

// Also used by the New Retention Policy modal (retention tab).
$practiceGroupsForPicker = custodia_list_practice_groups($pdo);

$allUsersList = [];
$userResult = ['rows' => [], 'total' => 0, 'page' => 1, 'pageSize' => 25, 'totalPages' => 1];
$userFilters = ['role' => '', 'is_active' => ''];
$userSearch = '';
$userListParams = [];
if ($tab === 'users') {
    $allUsersList = custodia_list_users($pdo, $user);
    $userFilters = ['role' => $_GET['role'] ?? '', 'is_active' => $_GET['status'] ?? ''];
    $userSearch = trim($_GET['q'] ?? '');
    $userListParams = custodia_listing_params(['full_name', 'email', 'employee_id', 'role', 'is_active'], 'is_active', 'DESC');
    $userResult = custodia_apply_listing($allUsersList, $userListParams, $userFilters, $userSearch, ['full_name', 'email', 'employee_id']);
}

$pageTitle = 'Admin';
$activeNav = 'admin';
require __DIR__ . '/includes/layout_header.php';
?>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'retention' ? 'active' : '' ?>" href="admin.php?tab=retention">Retention Policies</a></li>
  <?php if ($canManageUsers): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === 'users' ? 'active' : '' ?>" href="admin.php?tab=users">User Accounts</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'permissions' ? 'active' : '' ?>" href="admin.php?tab=permissions">Permissions</a></li>
  <?php endif; ?>
  <?php if ($canManagePracticeGroups): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === 'practicegroups' ? 'active' : '' ?>" href="admin.php?tab=practicegroups">Practice Groups</a></li>
  <?php endif; ?>
  <?php if ($canManageLocations): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === 'locations' ? 'active' : '' ?>" href="admin.php?tab=locations">Locations</a></li>
  <?php endif; ?>
  <?php if ($canViewAudit): ?>
    <li class="nav-item"><a class="nav-link <?= $tab === 'audit' ? 'active' : '' ?>" href="admin.php?tab=audit">Audit Log</a></li>
  <?php endif; ?>
</ul>

<?php if ($tab === 'users'): ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="page-title" style="font-size: 1.4rem; margin-bottom: 0;">User Accounts</h1>
    <div class="btn-group btn-group-sm">
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">+ New User</button>
      <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#bulkImportUsersModal">Bulk Import</button>
    </div>
  </div>

  <?php if (!empty($allUsersList)): ?>
  <form method="get" action="admin.php" class="filter-bar">
    <input type="hidden" name="tab" value="users">
    <div class="filter-col filter-col-search">
      <input class="form-control form-control-sm" name="q" placeholder="Search name, email, employee ID…" value="<?= e($userSearch) ?>">
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="role" onchange="this.form.submit()">
        <option value="">All roles</option>
        <?php foreach ($allRoles as $r): ?>
          <option value="<?= e($r['role_key']) ?>" <?= $userFilters['role'] === $r['role_key'] ? 'selected' : '' ?>><?= e($r['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <option value="1" <?= $userFilters['is_active'] === '1' ? 'selected' : '' ?>>Active</option>
        <option value="0" <?= $userFilters['is_active'] === '0' ? 'selected' : '' ?>>Deactivated</option>
      </select>
    </div>
    <div class="filter-col" style="flex: 0 0 auto;"><button class="btn btn-sm btn-primary" type="submit">Filter</button></div>
  </form>
  <?php endif; ?>

  <div class="card">
    <?php if (empty($allUsersList)): ?>
      <div class="text-center text-muted py-5">No user accounts yet.</div>
    <?php elseif (empty($userResult['rows'])): ?>
      <div class="text-center text-muted py-5">No users match these filters.</div>
    <?php else: ?>
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th><?= custodia_sort_link('Name', 'full_name', $userListParams) ?></th>
          <th><?= custodia_sort_link('Email', 'email', $userListParams) ?></th>
          <th><?= custodia_sort_link('Employee ID', 'employee_id', $userListParams) ?></th>
          <th><?= custodia_sort_link('Role', 'role', $userListParams) ?></th>
          <th>Practice Groups</th>
          <th><?= custodia_sort_link('Status', 'is_active', $userListParams) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($userResult['rows'] as $u): ?>
          <tr class="<?= $u['is_active'] ? '' : 'text-muted' ?>">
            <td class="fw-semibold"><?= e($u['full_name']) ?></td>
            <td class="small"><?= e($u['email']) ?></td>
            <td class="small mono"><?= e($u['employee_id']) ?></td>
            <td><?= e(custodia_role_label($pdo, $u['role'])) ?></td>
            <td class="small text-muted"><?= e(implode(', ', custodia_list_user_group_names($pdo, $u['id'])) ?: '—') ?></td>
            <td><?= $u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Deactivated</span>' ?></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" onclick='openEditUserModal(<?= json_encode([
                    "id" => $u["id"], "employeeId" => $u["employee_id"], "fullName" => $u["full_name"],
                    "email" => $u["email"], "role" => $u["role"], "barNumber" => $u["bar_number"],
                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
                <button class="btn btn-outline-secondary" onclick="openResetPasswordModal('<?= e($u['id']) ?>', '<?= e(addslashes($u['full_name'])) ?>')">Reset Password</button>
                <?php if ($u['id'] === $user['id']): ?>
                  <button class="btn btn-outline-secondary" disabled title="You cannot deactivate your own account">Deactivate</button>
                <?php elseif ($u['is_active']): ?>
                  <button class="btn btn-outline-danger" onclick="deactivateUser('<?= e($u['id']) ?>')">Deactivate</button>
                <?php else: ?>
                  <button class="btn btn-outline-success" onclick="reactivateUser('<?= e($u['id']) ?>')">Reactivate</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php if (!empty($allUsersList)): ?><?= custodia_pagination_bar($userResult) ?><?php endif; ?>

  <div class="modal fade" id="createUserModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">New User</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="createUserForm" data-action-url="actions/create_user.php">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">Full Name</label><input class="form-control" name="fullName" required></div>
        <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" required></div>
        <div class="mb-3"><label class="form-label">Employee ID</label><input class="form-control" name="employeeId" required></div>
        <div class="mb-3"><label class="form-label">Role</label>
          <select class="form-select" name="role" required>
            <?php foreach ($allRoles as $r): ?>
              <option value="<?= e($r['role_key']) ?>"><?= e($r['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Bar Number <span class="text-muted small">(optional)</span></label><input class="form-control" name="barNumber">
          <div class="form-text">Practice group membership is managed from Admin → Practice Groups, after the account exists.</div>
        </div>
        <div class="mb-3"><label class="form-label">Temporary Password</label><input type="password" class="form-control" name="password" required minlength="8">
          <div class="form-text">At least 8 characters. Share this with the user directly.</div>
        </div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Create User</button></div>
    </form>
  </div></div></div>

  <div class="modal fade" id="editUserModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Edit User — <span id="editUserName"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="editUserForm" data-action-url="actions/update_user.php">
      <input type="hidden" name="userId" id="editUserId">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">Full Name</label><input class="form-control" name="fullName" id="editUserFullName" required></div>
        <div class="mb-3"><label class="form-label">Email</label><input type="email" class="form-control" name="email" id="editUserEmail" required></div>
        <div class="mb-3"><label class="form-label">Employee ID</label><input class="form-control" name="employeeId" id="editUserEmployeeId" required></div>
        <div class="mb-3"><label class="form-label">Role</label>
          <select class="form-select" name="role" id="editUserRole" required>
            <?php foreach ($allRoles as $r): ?>
              <option value="<?= e($r['role_key']) ?>"><?= e($r['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Bar Number <span class="text-muted small">(optional)</span></label><input class="form-control" name="barNumber" id="editUserBarNumber">
          <div class="form-text">Practice group membership is managed from Admin → Practice Groups.</div>
        </div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Save Changes</button></div>
    </form>
  </div></div></div>

  <div class="modal fade" id="resetPasswordModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Reset Password — <span id="resetPasswordName"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="resetPasswordForm" data-action-url="actions/reset_user_password.php">
      <input type="hidden" name="userId" id="resetPasswordUserId">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">New Password</label><input type="password" class="form-control" name="newPassword" required minlength="8">
          <div class="form-text">At least 8 characters. Share this with the user directly.</div>
        </div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Reset Password</button></div>
    </form>
  </div></div></div>

  <div class="modal fade" id="bulkImportUsersModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Bulk Import Users</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="bulkImportUsersForm">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <p class="small text-muted">
          CSV file, first row a header with these column names: <code>employee id</code>, <code>full name</code>,
          <code>email</code>, <code>role</code> (required — a role's name or key, e.g. "Associate"), <code>bar number</code>,
          <code>practice areas</code> (semicolon or comma-separated; quote the field in the CSV if it contains a comma).
          A temporary password is generated for each new account — copy it from the results below before closing this window,
          the same way you'd share one set manually. —
          <a href="actions/download_user_import_template.php">Download template</a>
        </p>
        <div class="mb-3">
          <label class="form-label">CSV File</label>
          <input type="file" class="form-control" name="file" accept=".csv,text/csv" required>
        </div>
        <div id="bulkImportUsersResult"></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Import</button></div>
    </form>
  </div></div></div>

  <script>
  custodiaWireActionForm(document.getElementById('createUserForm'), () => window.location.reload());
  custodiaWireActionForm(document.getElementById('editUserForm'), () => window.location.reload());
  custodiaWireActionForm(document.getElementById('resetPasswordForm'), () => { custodiaFlash('Password reset.'); });

  function openEditUserModal(u) {
    document.getElementById('editUserId').value = u.id;
    document.getElementById('editUserName').textContent = u.fullName;
    document.getElementById('editUserFullName').value = u.fullName;
    document.getElementById('editUserEmail').value = u.email;
    document.getElementById('editUserEmployeeId').value = u.employeeId;
    document.getElementById('editUserRole').value = u.role;
    document.getElementById('editUserBarNumber').value = u.barNumber || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editUserModal')).show();
  }

  function openResetPasswordModal(userId, fullName) {
    document.getElementById('resetPasswordUserId').value = userId;
    document.getElementById('resetPasswordName').textContent = fullName;
    document.querySelector('#resetPasswordForm [name="newPassword"]').value = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('resetPasswordModal')).show();
  }

  async function deactivateUser(userId) {
    try {
      await custodiaPost('actions/deactivate_user.php', { userId });
      custodiaFlash('User deactivated.');
      window.location.reload();
    } catch (err) { custodiaFlash(err.message, 'danger'); }
  }

  async function reactivateUser(userId) {
    try {
      await custodiaPost('actions/reactivate_user.php', { userId });
      custodiaFlash('User reactivated.');
      window.location.reload();
    } catch (err) { custodiaFlash(err.message, 'danger'); }
  }

  document.getElementById('bulkImportUsersForm').addEventListener('submit', async (evt) => {
    evt.preventDefault();
    const form = evt.target;
    const errorBox = form.querySelector('.form-error');
    const resultBox = document.getElementById('bulkImportUsersResult');
    errorBox.classList.add('d-none');
    resultBox.innerHTML = '';
    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Importing…';
    try {
      const data = await custodiaPostMultipart('actions/bulk_import_users.php', new FormData(form));
      let html = '';
      if (data.created.length > 0) {
        html += `<div class="alert alert-success">Imported ${data.created.length} user${data.created.length === 1 ? '' : 's'}. Copy these temporary passwords now — they won't be shown again.</div>`;
        html += '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Name</th><th>Email</th><th>Employee ID</th><th>Temporary Password</th></tr></thead><tbody>';
        html += data.created.map(c => `<tr><td>${custodiaEscapeHtml(c.fullName)}</td><td>${custodiaEscapeHtml(c.email)}</td><td>${custodiaEscapeHtml(c.employeeId)}</td><td class="mono">${custodiaEscapeHtml(c.password)}</td></tr>`).join('');
        html += '</tbody></table></div>';
      }
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

<?php elseif ($tab === 'permissions'): ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="page-title" style="font-size: 1.4rem; margin-bottom: 0;">Permissions</h1>
    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createRoleModal">+ New Role</button>
  </div>

  <form id="permissionsForm" data-action-url="actions/update_role_permissions.php">
    <div class="form-error alert alert-danger d-none mb-3"></div>
    <div class="card">
      <div class="table-responsive">
        <table class="table mb-0 align-middle">
          <thead class="table-light">
            <tr>
              <th style="min-width: 260px;">Permission</th>
              <?php foreach ($allRoles as $r): ?>
                <th class="text-center small">
                  <?= e($r['label']) ?>
                  <?php if (!$r['is_builtin']): ?>
                    <br><button type="button" class="btn btn-link btn-sm text-danger p-0" style="font-size: 0.75rem;" onclick="deleteRole('<?= e($r['role_key']) ?>', '<?= e(addslashes($r['label'])) ?>')">Delete</button>
                  <?php endif; ?>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($permissionMatrix as $permKey => $cells): ?>
              <tr>
                <td>
                  <div class="fw-semibold small"><?= e(CUSTODIA_PERMISSIONS[$permKey]['label']) ?></div>
                  <div class="text-muted small"><?= e(CUSTODIA_PERMISSIONS[$permKey]['description']) ?></div>
                </td>
                <?php foreach ($cells as $cell): ?>
                  <?php $isLocked = $permKey === 'manage_users' && $cell['role'] === 'SYSTEM_ADMIN'; ?>
                  <td class="text-center">
                    <?php if ($isLocked): ?>
                      <input type="checkbox" class="form-check-input" checked disabled title="System Administrator must always retain this permission.">
                      <input type="hidden" name="grants[]" value="<?= e($cell['role']) ?>:<?= e($permKey) ?>">
                    <?php else: ?>
                      <input type="checkbox" class="form-check-input" name="grants[]" value="<?= e($cell['role']) ?>:<?= e($permKey) ?>" <?= $cell['granted'] ? 'checked' : '' ?>>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <button type="submit" class="btn btn-primary mt-3">Save Changes</button>
  </form>

  <div class="modal fade" id="createRoleModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">New Role</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="createRoleForm" data-action-url="actions/create_role.php">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3">
          <label class="form-label">Label</label>
          <input class="form-control" name="label" required placeholder="Compliance Officer">
          <div class="form-text">Shown throughout the app — dropdowns, user lists, the audit log.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Role Key</label>
          <input class="form-control mono" name="roleKey" required placeholder="COMPLIANCE_OFFICER" pattern="[A-Za-z][A-Za-z0-9_]{1,63}" style="text-transform: uppercase;">
          <div class="form-text">Letters, numbers, and underscores only. Can't be changed after creating the role.</div>
        </div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Create Role</button></div>
    </form>
  </div></div></div>

  <script>
  custodiaWireActionForm(document.getElementById('createRoleForm'), () => window.location.reload());

  // The only hard-delete action in this app (deactivating a user, by
  // contrast, is reversible) — a plain confirm() is a deliberate, minimal
  // exception rather than building a whole modal for a single irreversible
  // click.
  async function deleteRole(roleKey, label) {
    if (!confirm(`Delete the "${label}" role? This can't be undone.`)) return;
    try {
      await custodiaPost('actions/delete_role.php', { roleKey });
      custodiaFlash('Role deleted.');
      window.location.reload();
    } catch (err) {
      custodiaFlash(err.message, 'danger');
    }
  }

  // Not custodiaWireActionForm/custodiaPost here: this form has many
  // same-named grants[] checkboxes, and Object.fromEntries(new
  // FormData(form)) — what custodiaWireActionForm uses — collapses repeated
  // keys down to just the last one, silently dropping every other checked
  // box. Posting the FormData directly preserves all of them.
  document.getElementById('permissionsForm').addEventListener('submit', async (evt) => {
    evt.preventDefault();
    const form = evt.target;
    const errorBox = form.querySelector('.form-error');
    errorBox.classList.add('d-none');
    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    try {
      const formData = new FormData(form);
      formData.set('csrf_token', custodiaCsrfToken());
      const res = await fetch(form.getAttribute('data-action-url'), { method: 'POST', body: formData });
      const json = await res.json().catch(() => ({ error: `Unexpected response (HTTP ${res.status}).` }));
      if (!res.ok || json.error) throw new Error(json.error || `Request failed (HTTP ${res.status}).`);
      custodiaFlash('Permissions updated.');
    } catch (err) {
      errorBox.textContent = err.message;
      errorBox.classList.remove('d-none');
    } finally {
      submitBtn.disabled = false;
    }
  });
  </script>

<?php elseif ($tab === 'practicegroups'): ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="page-title" style="font-size: 1.4rem; margin-bottom: 0;">Practice Groups</h1>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createPracticeGroupModal">+ New Practice Group</button>
  </div>

  <div class="card">
    <?php if (empty($practiceGroups)): ?>
      <div class="text-center text-muted py-5">No practice groups configured yet.</div>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light"><tr><th>Name</th><th>Members</th><th>Added</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($practiceGroups as $pg): ?>
            <tr>
              <td class="fw-semibold"><?= e($pg['name']) ?></td>
              <td class="text-muted"><?= count(custodia_list_group_members($pdo, $pg['id'])) ?></td>
              <td class="text-muted"><?= custodia_format_date($pg['created_at']) ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="practice_group.php?id=<?= e($pg['id']) ?>">Manage Members</a>
                <button class="btn btn-sm btn-outline-secondary" onclick='openEditPracticeGroupModal(<?= json_encode(["id" => $pg["id"], "name" => $pg["name"]], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Rename</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="modal fade" id="createPracticeGroupModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">New Practice Group</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="createPracticeGroupForm" data-action-url="actions/create_practice_group.php">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" required placeholder="Intellectual Property"></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Create Practice Group</button></div>
    </form>
  </div></div></div>

  <div class="modal fade" id="editPracticeGroupModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Rename Practice Group</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="editPracticeGroupForm" data-action-url="actions/update_practice_group.php">
      <input type="hidden" name="practiceGroupId" id="editPracticeGroupId">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" id="editPracticeGroupName" required></div>
        <div class="form-text">Renaming updates every matter and retention policy currently using the old name. Membership is unaffected.</div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Save Changes</button></div>
    </form>
  </div></div></div>

  <script>
  custodiaWireActionForm(document.getElementById('createPracticeGroupForm'), () => window.location.reload());
  custodiaWireActionForm(document.getElementById('editPracticeGroupForm'), () => window.location.reload());

  function openEditPracticeGroupModal(pg) {
    document.getElementById('editPracticeGroupId').value = pg.id;
    document.getElementById('editPracticeGroupName').value = pg.name;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editPracticeGroupModal')).show();
  }
  </script>

<?php elseif ($tab === 'locations'): ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="page-title" style="font-size: 1.4rem; margin-bottom: 0;">Physical Locations</h1>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createLocationModal">+ New Location</button>
  </div>

  <?php if (!empty($allPhysicalLocations)): ?>
  <form method="get" action="admin.php" class="filter-bar">
    <input type="hidden" name="tab" value="locations">
    <div class="filter-col filter-col-search">
      <input class="form-control form-control-sm" name="q" placeholder="Search building, room, shelf, bin…" value="<?= e($locationSearch) ?>">
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="type" onchange="this.form.submit()">
        <option value="">All types</option>
        <?php foreach (CUSTODIA_LOCATION_TYPES as $type): ?>
          <option value="<?= e($type) ?>" <?= $locationFilters['location_type'] === $type ? 'selected' : '' ?>><?= e(ucwords(strtolower(str_replace('_', ' ', $type)))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col" style="flex: 0 0 auto;"><button class="btn btn-sm btn-primary" type="submit">Filter</button></div>
  </form>
  <?php endif; ?>

  <div class="card">
    <?php if (empty($allPhysicalLocations)): ?>
      <div class="text-center text-muted py-5">No physical locations configured yet.</div>
    <?php elseif (empty($locationResult['rows'])): ?>
      <div class="text-center text-muted py-5">No physical locations match these filters.</div>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th><?= custodia_sort_link('Building', 'building', $locationListParams) ?></th>
            <th><?= custodia_sort_link('Room', 'room', $locationListParams) ?></th>
            <th><?= custodia_sort_link('Shelf', 'shelf', $locationListParams) ?></th>
            <th><?= custodia_sort_link('Bin', 'bin', $locationListParams) ?></th>
            <th><?= custodia_sort_link('Type', 'location_type', $locationListParams) ?></th>
            <th><?= custodia_sort_link('Files Here', 'file_count', $locationListParams) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($locationResult['rows'] as $loc): ?>
            <tr>
              <td class="fw-semibold"><?= e($loc['building']) ?></td>
              <td><?= e($loc['room']) ?></td>
              <td class="text-muted"><?= e($loc['shelf'] ?? '') ?: '—' ?></td>
              <td class="text-muted"><?= e($loc['bin'] ?? '') ?: '—' ?></td>
              <td><span class="badge text-bg-light border"><?= e(ucwords(strtolower(str_replace('_', ' ', $loc['location_type'])))) ?></span></td>
              <td class="text-muted"><?= (int) $loc['file_count'] ?></td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-secondary" onclick='openEditLocationModal(<?= json_encode([
                    "id" => $loc["id"], "building" => $loc["building"], "room" => $loc["room"],
                    "shelf" => $loc["shelf"], "bin" => $loc["bin"], "locationType" => $loc["location_type"],
                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php if (!empty($locationResult['rows'])): ?><?= custodia_pagination_bar($locationResult) ?><?php endif; ?>

  <div class="modal fade" id="createLocationModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">New Physical Location</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="createLocationForm" data-action-url="actions/create_physical_location.php">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">Building</label><input class="form-control" name="building" required placeholder="Main Registry"></div>
        <div class="mb-3"><label class="form-label">Room</label><input class="form-control" name="room" required></div>
        <div class="mb-3"><label class="form-label">Shelf</label><input class="form-control" name="shelf" required></div>
        <div class="mb-3"><label class="form-label">Bin <span class="text-muted small">(optional)</span></label><input class="form-control" name="bin"></div>
        <div class="mb-3"><label class="form-label">Type</label>
          <select class="form-select" name="locationType" required>
            <?php foreach (CUSTODIA_LOCATION_TYPES as $type): ?>
              <option value="<?= e($type) ?>"><?= e(ucwords(strtolower(str_replace('_', ' ', $type)))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Create Location</button></div>
    </form>
  </div></div></div>

  <div class="modal fade" id="editLocationModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Edit Physical Location</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="editLocationForm" data-action-url="actions/update_physical_location.php">
      <input type="hidden" name="locationId" id="editLocationId">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">Building</label><input class="form-control" name="building" id="editLocationBuilding" required></div>
        <div class="mb-3"><label class="form-label">Room</label><input class="form-control" name="room" id="editLocationRoom" required></div>
        <div class="mb-3"><label class="form-label">Shelf</label><input class="form-control" name="shelf" id="editLocationShelf" required></div>
        <div class="mb-3"><label class="form-label">Bin <span class="text-muted small">(optional)</span></label><input class="form-control" name="bin" id="editLocationBin"></div>
        <div class="mb-3"><label class="form-label">Type</label>
          <select class="form-select" name="locationType" id="editLocationType" required>
            <?php foreach (CUSTODIA_LOCATION_TYPES as $type): ?>
              <option value="<?= e($type) ?>"><?= e(ucwords(strtolower(str_replace('_', ' ', $type)))) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Save Changes</button></div>
    </form>
  </div></div></div>

  <script>
  custodiaWireActionForm(document.getElementById('createLocationForm'), () => window.location.reload());
  custodiaWireActionForm(document.getElementById('editLocationForm'), () => window.location.reload());

  function openEditLocationModal(loc) {
    document.getElementById('editLocationId').value = loc.id;
    document.getElementById('editLocationBuilding').value = loc.building;
    document.getElementById('editLocationRoom').value = loc.room;
    document.getElementById('editLocationShelf').value = loc.shelf;
    document.getElementById('editLocationBin').value = loc.bin || '';
    document.getElementById('editLocationType').value = loc.locationType;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editLocationModal')).show();
  }
  </script>

<?php elseif ($tab === 'audit'): ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="page-title" style="font-size: 1.4rem; margin-bottom: 0;">Audit Log</h1>
    <div class="d-flex gap-2">
      <?php if ($canVerifyAudit): ?>
        <button class="btn btn-outline-dark btn-sm" id="verifyBtn" onclick="verifyChain()">Verify Chain Integrity</button>
      <?php endif; ?>
      <?php if ($canExportAudit): ?>
        <a class="btn btn-primary btn-sm" href="actions/audit_export.php?<?= e($auditExportQuery) ?>">⬆ Export Report</a>
      <?php endif; ?>
    </div>
  </div>
  <p class="text-muted small mb-3">Immutable, hash-chained record of every access and movement.</p>

  <div id="verifyResult" class="alert d-none mb-3"></div>

  <form method="get" action="admin.php" class="filter-bar">
    <input type="hidden" name="tab" value="audit">
    <div class="filter-col">
      <select class="form-select form-select-sm" name="actorId" onchange="this.form.submit()">
        <option value="">All users</option>
        <?php foreach ($auditUsers as $u): ?>
          <option value="<?= e($u['id']) ?>" <?= $auditQuery['actorId'] === $u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="entityType" onchange="this.form.submit()">
        <option value="">All entity types</option>
        <?php foreach (['MATTER', 'PHYSICAL_FILE', 'DIGITAL_DOCUMENT', 'USER', 'AUDIT_LOG'] as $et): ?>
          <option value="<?= e($et) ?>" <?= $auditQuery['entityType'] === $et ? 'selected' : '' ?>><?= e(ucwords(strtolower(str_replace('_', ' ', $et)))) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col">
      <select class="form-select form-select-sm" name="actionType" onchange="this.form.submit()">
        <option value="">All action types</option>
        <?php foreach ($auditActionTypes as $at): ?>
          <option value="<?= e($at) ?>" <?= $auditQuery['actionType'] === $at ? 'selected' : '' ?>><?= e($at) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-col">
      <input class="form-control form-control-sm" type="date" name="from" value="<?= e($auditQuery['from'] ?? '') ?>" title="From">
    </div>
    <div class="filter-col">
      <input class="form-control form-control-sm" type="date" name="to" value="<?= e($auditQuery['to'] ?? '') ?>" title="To">
    </div>
    <div class="filter-col filter-col-search">
      <input class="form-control form-control-sm" name="q" placeholder="Search reason, entity, IP…" value="<?= e($auditQuery['q'] ?? '') ?>">
    </div>
    <div class="filter-col" style="flex: 0 0 auto;"><button class="btn btn-sm btn-primary" type="submit">Filter</button></div>
  </form>

  <div class="card">
    <?php if (empty($auditResult['entries'])): ?>
      <div class="text-center text-muted py-5">No audit entries match these filters.</div>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle small">
        <thead><tr><th>Timestamp</th><th>Actor</th><th>Action</th><th>Entity</th><th>Reason</th><th>IP Address</th></tr></thead>
        <tbody>
          <?php foreach ($auditResult['entries'] as $a): ?>
            <tr>
              <td class="text-muted mono small"><?= custodia_format_datetime($a['created_at']) ?></td>
              <td><?= e($a['actor_name']) ?> <span class="text-muted">(<?= e(custodia_role_label($pdo, $a['actor_role'])) ?>)</span></td>
              <td><?= custodia_action_badge($a['action_type']) ?></td>
              <td class="text-muted"><?= e($a['entity_type']) ?></td>
              <td class="text-muted"><?= e($a['reason'] ?? '—') ?></td>
              <td class="text-muted mono small"><?= e($a['ip_address']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?= custodia_pagination_bar($auditResult) ?>

  <script>
  async function verifyChain() {
    const btn = document.getElementById('verifyBtn');
    const box = document.getElementById('verifyResult');
    btn.disabled = true;
    btn.textContent = 'Verifying…';
    try {
      const data = await custodiaPost('actions/verify_audit.php', {});
      box.className = 'alert ' + (data.valid ? 'alert-success' : 'alert-danger');
      box.textContent = data.valid
        ? `Chain verified — ${data.checked} entries checked, no tampering detected.`
        : `Chain verification FAILED at entry ${data.brokenAtId} — ${data.checked} entries checked before the break.`;
      box.classList.remove('d-none');
    } catch (err) {
      box.className = 'alert alert-danger';
      box.textContent = err.message;
      box.classList.remove('d-none');
    } finally {
      btn.disabled = false;
      btn.textContent = 'Verify Chain Integrity';
    }
  }
  </script>

<?php else: ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="page-title" style="font-size: 1.4rem; margin-bottom: 0;">Retention Policy Configuration</h1>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createPolicyModal">+ New Policy</button>
  </div>

  <div class="card">
    <?php if (empty($policies)): ?>
      <div class="text-center text-muted py-5">No retention policies configured yet.</div>
    <?php else: ?>
      <table class="table mb-0 align-middle">
        <thead class="table-light"><tr><th>Practice Group</th><th>Retention Years</th><th>Action</th><th>Trigger Event</th></tr></thead>
        <tbody>
          <?php foreach ($policies as $p): ?>
            <tr>
              <td class="fw-semibold"><?= e($p['practice_area']) ?></td>
              <td><?= (int) $p['retention_years'] ?></td>
              <td><span class="badge text-bg-light border"><?= e($p['action']) ?></span></td>
              <td class="text-muted"><?= e($p['trigger_event']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>


  <div class="modal fade" id="createPolicyModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">New Retention Policy</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="createPolicyForm" data-action-url="actions/create_retention_policy.php">
      <div class="modal-body">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3"><label class="form-label">Practice Group</label>
          <?php if (empty($practiceGroupsForPicker)): ?>
            <div class="form-text text-danger mb-2">No practice groups yet — add one from the Practice Groups tab.</div>
          <?php endif; ?>
          <select class="form-select" name="practiceArea" required>
            <option value="">— Select a practice group —</option>
            <?php foreach ($practiceGroupsForPicker as $pg): ?>
              <option value="<?= e($pg['name']) ?>"><?= e($pg['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Retention Years</label><input type="number" min="1" class="form-control" name="retentionYears" required value="7"></div>
        <div class="mb-3"><label class="form-label">Action</label>
          <select class="form-select" name="action">
            <option value="REVIEW">Review</option>
            <option value="ARCHIVE">Archive</option>
            <option value="DESTROY">Destroy</option>
          </select>
        </div>
        <div class="mb-3"><label class="form-label">Trigger Event</label><input class="form-control" name="triggerEvent" required value="MATTER_CLOSE"></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Create Policy</button></div>
    </form>
  </div></div></div>
  <script>
  custodiaWireActionForm(document.getElementById('createPolicyForm'), () => window.location.reload());
  </script>

<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
