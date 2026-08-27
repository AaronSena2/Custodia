<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/custody.php';
require_once __DIR__ . '/includes/access_requests.php';

$user = custodia_require_login();
$pdo = custodia_db();

$pendingMovements = custodia_list_pending_for_approver($pdo, $user);
$pendingAccess = custodia_list_pending_access_requests_for_approver($pdo, $user);

$transferCount = count($pendingMovements);
$accessCount = count($pendingAccess);
$totalCount = $transferCount + $accessCount;

$pageTitle = 'Approvals';
$activeNav = 'approvals';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Approvals</div>
    <div class="page-subtitle">Custody transfers and confidential-matter access awaiting your sign-off</div>
  </div>
  <div class="pill-tabs" id="approvalPills">
    <button type="button" class="pill-tab active" data-filter="all">All (<?= $totalCount ?>)</button>
    <button type="button" class="pill-tab" data-filter="transfers">Transfers (<?= $transferCount ?>)</button>
    <button type="button" class="pill-tab" data-filter="access">Access Requests (<?= $accessCount ?>)</button>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6" data-approval-col="transfers">
    <div class="card h-100">
      <div class="card-header bg-white fw-semibold">↗ Custody Transfers</div>
      <div class="card-body">
        <?php if (empty($pendingMovements)): ?>
          <div class="text-center text-muted py-4">Nothing pending on the custody side right now.</div>
        <?php else: ?>
          <?php foreach ($pendingMovements as $m): ?>
            <div class="approval-row">
              <div>
                <div class="approval-title">
                  <?php if ($m['movement_type'] === 'TRANSFER'): ?>
                    <?= e($m['requested_by_name']) ?> wants this file
                  <?php else: ?>
                    <?= e($m['requested_by_name']) ?> · <?= e(custodia_movement_type_label($m['movement_type'])) ?>
                  <?php endif; ?>
                </div>
                <div class="approval-meta mono"><?= e($m['barcode']) ?> · <?= e($m['client_name']) ?></div>
                <div class="approval-quote">"<?= e($m['reason']) ?>"</div>
              </div>
              <div class="approval-actions">
                <button class="btn-icon-circle btn-approve" title="Approve" onclick="doAction('actions/approve_movement.php', {movementId: '<?= e($m['id']) ?>'})">✓</button>
                <button class="btn-icon-circle btn-reject" title="Reject" onclick="openReasonModal('actions/reject_movement.php', {movementId: '<?= e($m['id']) ?>'}, 'Reject Movement')">✕</button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6" data-approval-col="access">
    <div class="card h-100">
      <div class="card-header bg-white fw-semibold">🛡 Confidential Access Requests</div>
      <div class="card-body">
        <?php if (empty($pendingAccess)): ?>
          <div class="text-center text-muted py-4">No pending access requests.</div>
        <?php else: ?>
          <?php foreach ($pendingAccess as $r): ?>
            <div class="approval-row">
              <div>
                <div class="approval-title"><?= e($r['requester_name']) ?> · <?= e(custodia_role_label($pdo, $r['requester_role'])) ?></div>
                <div class="approval-meta">Requesting: <span class="badge text-bg-purple"><?= e($r['request_type']) ?></span> <?= e($r['entity_type']) ?></div>
                <div class="approval-quote">"<?= e($r['reason']) ?>"</div>
              </div>
              <div class="approval-actions">
                <?php if ($r['entity_type'] === 'DIGITAL_DOCUMENT'): ?>
                  <button class="btn-icon-circle btn-approve" title="Approve" onclick="openApproveExpiryModal('<?= e($r['id']) ?>')">✓</button>
                <?php else: ?>
                  <button class="btn-icon-circle btn-approve" title="Approve" onclick="doAction('actions/decide_access_request.php', {requestId: '<?= e($r['id']) ?>', approve: '1'})">✓</button>
                <?php endif; ?>
                <button class="btn-icon-circle btn-reject" title="Deny" onclick="openReasonModal('actions/decide_access_request.php', {requestId: '<?= e($r['id']) ?>', approve: '0'}, 'Deny Access Request')">✕</button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('approvalPills').addEventListener('click', (evt) => {
  const btn = evt.target.closest('.pill-tab');
  if (!btn) return;
  document.querySelectorAll('#approvalPills .pill-tab').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  const filter = btn.dataset.filter;
  document.querySelectorAll('[data-approval-col]').forEach(col => {
    col.style.display = (filter === 'all' || col.dataset.approvalCol === filter) ? '' : 'none';
  });
});
</script>

<div class="modal fade" id="reasonModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="reasonModalTitle">Reason</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="reasonForm">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <div class="mb-3"><label class="form-label">Reason</label><textarea class="form-control" id="reasonText" required></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-danger w-100">Submit</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="approveExpiryModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Approve Document Access</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="approveExpiryForm">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <div class="mb-3"><label class="form-label">Expires after <span class="text-muted small">(hours — optional, blank = no expiry)</span></label>
        <input type="number" class="form-control" id="approveExpiryHours" min="1" placeholder="e.g. 24">
      </div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Approve</button></div>
  </form>
</div></div></div>

<script>
async function doAction(url, payload) {
  try {
    await custodiaPost(url, payload);
    custodiaFlash('Done.');
    window.location.reload();
  } catch (err) {
    custodiaFlash(err.message, 'danger');
  }
}

let pendingReasonAction = null;
function openReasonModal(url, payload, title) {
  pendingReasonAction = { url, payload };
  document.getElementById('reasonModalTitle').textContent = title;
  document.getElementById('reasonText').value = '';
  document.querySelector('#reasonForm .form-error').classList.add('d-none');
  bootstrap.Modal.getOrCreateInstance(document.getElementById('reasonModal')).show();
}
document.getElementById('reasonForm').addEventListener('submit', async (evt) => {
  evt.preventDefault();
  if (!pendingReasonAction) return;
  const errorBox = document.querySelector('#reasonForm .form-error');
  try {
    const payload = { ...pendingReasonAction.payload, reason: document.getElementById('reasonText').value };
    await custodiaPost(pendingReasonAction.url, payload);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('reasonModal')).hide();
    custodiaFlash('Done.');
    window.location.reload();
  } catch (err) {
    errorBox.textContent = err.message;
    errorBox.classList.remove('d-none');
  }
});

let pendingApproveRequestId = null;
function openApproveExpiryModal(requestId) {
  pendingApproveRequestId = requestId;
  document.getElementById('approveExpiryHours').value = '';
  document.querySelector('#approveExpiryForm .form-error').classList.add('d-none');
  bootstrap.Modal.getOrCreateInstance(document.getElementById('approveExpiryModal')).show();
}
document.getElementById('approveExpiryForm').addEventListener('submit', async (evt) => {
  evt.preventDefault();
  if (!pendingApproveRequestId) return;
  const errorBox = document.querySelector('#approveExpiryForm .form-error');
  try {
    const payload = { requestId: pendingApproveRequestId, approve: '1', expiresInHours: document.getElementById('approveExpiryHours').value };
    await custodiaPost('actions/decide_access_request.php', payload);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('approveExpiryModal')).hide();
    custodiaFlash('Done.');
    window.location.reload();
  } catch (err) {
    errorBox.textContent = err.message;
    errorBox.classList.remove('d-none');
  }
});
</script>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
