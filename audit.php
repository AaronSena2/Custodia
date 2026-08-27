<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/audit_query.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/listing.php';

$user = custodia_require_login();
$pdo = custodia_db();

if ($user['role'] === 'GUEST_AUDITOR') {
    http_response_code(403);
    $pageTitle = 'Audit Log';
    $activeNav = 'audit';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="alert alert-danger">Guests may not view the audit log.</div>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$query = [
    'actorId' => trim($_GET['actorId'] ?? '') ?: null,
    'entityType' => trim($_GET['entityType'] ?? '') ?: null,
    'actionType' => trim($_GET['actionType'] ?? '') ?: null,
    'q' => trim($_GET['q'] ?? '') ?: null,
    'from' => trim($_GET['from'] ?? '') ?: null,
    'to' => trim($_GET['to'] ?? '') ?: null,
];

$listParams = custodia_listing_params([], 'created_at', 'DESC');
$result = custodia_audit_list($pdo, $user, $query, $listParams['page'], $listParams['pageSize']);
$result['totalPages'] = max(1, (int) ceil($result['total'] / $result['pageSize']));

$exportQuery = http_build_query(array_filter($query));
$canExport = custodia_user_has_permission($pdo, $user, 'export_audit_log');
$canVerify = custodia_user_has_permission($pdo, $user, 'verify_audit_chain');

$auditUsersStmt = $pdo->query('SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name');
$auditUsers = $auditUsersStmt->fetchAll();
$auditActionTypes = array_keys(CUSTODIA_ACTION_BADGE_COLORS);
sort($auditActionTypes);

$pageTitle = 'Audit Log';
$activeNav = 'audit';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Audit Log</div>
    <div class="page-subtitle">Immutable, hash-chained record of every access and movement</div>
  </div>
  <div class="d-flex gap-2">
    <?php if ($canVerify): ?>
      <button class="btn btn-outline-dark btn-sm" id="verifyBtn" onclick="verifyChain()">Verify Chain Integrity</button>
    <?php endif; ?>
    <?php if ($canExport): ?>
      <a class="btn btn-primary btn-sm" href="actions/audit_export.php?<?= e($exportQuery) ?>">⬆ Export Report</a>
    <?php endif; ?>
  </div>
</div>

<div id="verifyResult" class="alert d-none mb-3"></div>

<form method="get" action="audit.php" class="filter-bar">
  <div class="filter-col">
    <select class="form-select form-select-sm" name="actorId" onchange="this.form.submit()">
      <option value="">All users</option>
      <?php foreach ($auditUsers as $u): ?>
        <option value="<?= e($u['id']) ?>" <?= $query['actorId'] === $u['id'] ? 'selected' : '' ?>><?= e($u['full_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-col">
    <select class="form-select form-select-sm" name="entityType" onchange="this.form.submit()">
      <option value="">All entity types</option>
      <?php foreach (['MATTER', 'PHYSICAL_FILE', 'DIGITAL_DOCUMENT', 'USER', 'AUDIT_LOG'] as $et): ?>
        <option value="<?= e($et) ?>" <?= $query['entityType'] === $et ? 'selected' : '' ?>><?= e(ucwords(strtolower(str_replace('_', ' ', $et)))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-col">
    <select class="form-select form-select-sm" name="actionType" onchange="this.form.submit()">
      <option value="">All action types</option>
      <?php foreach ($auditActionTypes as $at): ?>
        <option value="<?= e($at) ?>" <?= $query['actionType'] === $at ? 'selected' : '' ?>><?= e($at) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="filter-col">
    <input class="form-control form-control-sm" type="date" name="from" value="<?= e($query['from'] ?? '') ?>" title="From">
  </div>
  <div class="filter-col">
    <input class="form-control form-control-sm" type="date" name="to" value="<?= e($query['to'] ?? '') ?>" title="To">
  </div>
  <div class="filter-col filter-col-search">
    <input class="form-control form-control-sm" name="q" placeholder="Search reason, entity, IP…" value="<?= e($query['q'] ?? '') ?>">
  </div>
  <div class="filter-col" style="flex: 0 0 auto;"><button class="btn btn-sm btn-primary" type="submit">Filter</button></div>
</form>

<div class="card">
  <?php if (empty($result['entries'])): ?>
    <div class="text-center text-muted py-5">No audit entries match these filters.</div>
  <?php else: ?>
    <table class="table table-hover mb-0 align-middle small">
      <thead><tr><th>Timestamp</th><th>Actor</th><th>Action</th><th>Entity</th><th>Reason</th><th>IP Address</th></tr></thead>
      <tbody>
        <?php foreach ($result['entries'] as $a): ?>
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

<?= custodia_pagination_bar($result) ?>

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

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
