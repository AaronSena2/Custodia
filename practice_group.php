<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/practice_groups.php';

$user = custodia_require_login();
custodia_require_role($user, ['SYSTEM_ADMIN', 'RECORDS_MANAGER']);
$pdo = custodia_db();

if (!custodia_user_has_permission($pdo, $user, 'manage_practice_groups')) {
    http_response_code(403);
    $pageTitle = 'Practice Group';
    $activeNav = 'admin';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="alert alert-danger">You don\'t have the "Manage Practice Groups" permission.</div>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$groupId = $_GET['id'] ?? '';
if ($groupId === '') {
    header('Location: admin.php?tab=practicegroups');
    exit;
}

try {
    $group = custodia_get_practice_group($pdo, $groupId);
} catch (CustodiaHttpException $e) {
    http_response_code($e->status);
    $pageTitle = 'Practice Group';
    $activeNav = 'admin';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="alert alert-danger">' . e($e->getMessage()) . '</div>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$members = custodia_list_group_members($pdo, $groupId);
$memberIds = array_column($members, 'user_id');
$usersStmt = $pdo->query('SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name');
$allUsers = $usersStmt->fetchAll();
$availableUsers = array_values(array_filter($allUsers, fn ($u) => !in_array($u['id'], $memberIds, true)));

$pageTitle = $group['name'];
$activeNav = 'admin';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-breadcrumb"><a href="admin.php?tab=practicegroups">Practice Groups</a> / <?= e($group['name']) ?></div>
<div class="page-header">
  <div>
    <div class="page-title"><?= e($group['name']) ?></div>
    <div class="page-subtitle">Practice group membership — who counts as part of this group for matter-access grants and Partner reach.</div>
  </div>
</div>

<div class="card">
  <div class="card-header bg-white fw-semibold">Members (<?= count($members) ?>)</div>
  <div class="card-body">
    <?php if (empty($members)): ?>
      <div class="text-center text-muted py-3">No members yet.</div>
    <?php else: ?>
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light"><tr><th>Name</th><th>Role</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($members as $m): ?>
            <tr class="<?= $m['is_active'] ? '' : 'text-muted' ?>">
              <td class="fw-semibold"><?= e($m['full_name']) ?><?= $m['is_active'] ? '' : ' (deactivated)' ?></td>
              <td><?= e(custodia_role_label($pdo, $m['role'])) ?></td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-danger" onclick="removeMember('<?= e($m['id']) ?>', '<?= e(addslashes($m['full_name'])) ?>')">Remove</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-3" style="max-width: 480px;">
  <div class="card-body">
    <h6 class="card-title">Add Member</h6>
    <?php if (empty($availableUsers)): ?>
      <div class="text-muted small">Every active user is already a member of this group.</div>
    <?php else: ?>
      <form id="addMemberForm" data-action-url="actions/add_group_member.php">
        <input type="hidden" name="practiceGroupId" value="<?= e($groupId) ?>">
        <div class="form-error alert alert-danger d-none"></div>
        <div class="mb-3">
          <select class="form-select" name="userId" required>
            <?php foreach ($availableUsers as $u): ?>
              <option value="<?= e($u['id']) ?>"><?= e($u['full_name']) ?> (<?= e(custodia_role_label($pdo, $u['role'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary w-100">Add to Group</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<script>
const addMemberForm = document.getElementById('addMemberForm');
if (addMemberForm) {
  custodiaWireActionForm(addMemberForm, () => window.location.reload());
}

async function removeMember(membershipId, fullName) {
  if (!confirm(`Remove ${fullName} from this group?`)) return;
  try {
    await custodiaPost('actions/remove_group_member.php', { membershipId });
    custodiaFlash('Member removed.');
    window.location.reload();
  } catch (err) { custodiaFlash(err.message, 'danger'); }
}
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
