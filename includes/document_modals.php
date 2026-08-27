<?php
/** Shared Bootstrap modals for the Digital Documents tab (create / upload / lock / share). */
?>
<div class="modal fade" id="createDocModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">New Document</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="createDocForm" data-action-url="actions/create_document.php">
    <input type="hidden" name="matterId" value="<?= e($matterId) ?>">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <div class="mb-3"><label class="form-label">Title</label><input class="form-control" name="title" required></div>
      <div class="mb-3"><label class="form-label">Type</label><input class="form-control" name="docType" required placeholder="Agreement"></div>
      <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2" placeholder="Optional profile note — what this document is, in a sentence."></textarea></div>
      <div class="mb-3"><label class="form-label">Confidentiality</label>
        <select class="form-select" name="confidentiality">
          <option value="STANDARD">Standard</option><option value="RESTRICTED">Restricted</option><option value="PRIVILEGED">Privileged</option>
        </select>
      </div>
      <div class="mb-3"><label class="form-label">Linked Physical File <span class="text-muted small">(optional)</span></label>
        <select class="form-select" name="linkedPhysicalFileId">
          <option value="">— None —</option>
          <?php foreach (($matterPhysicalFiles ?? []) as $pf): ?>
            <option value="<?= e($pf['id']) ?>"><?= e($pf['barcode']) ?> — <?= e($pf['jacket_label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Create</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="editProfileModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Edit Document Profile — <span id="editProfileLabel"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="editProfileForm" data-action-url="actions/update_document_profile.php">
    <input type="hidden" name="documentId" id="editProfileDocId">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <div class="mb-3"><label class="form-label">Title</label><input class="form-control" name="title" id="editProfileTitle" required></div>
      <div class="mb-3"><label class="form-label">Type</label><input class="form-control" name="docType" id="editProfileType" required></div>
      <div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" id="editProfileDescription" rows="2"></textarea></div>
      <div class="mb-3"><label class="form-label">Author</label>
        <select class="form-select" name="authorId" id="editProfileAuthor">
          <?php foreach (($allUsers ?? []) as $u): ?>
            <option value="<?= e($u['id']) ?>"><?= e($u['full_name']) ?> (<?= e(custodia_role_label($pdo, $u['role'])) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label">Confidentiality</label>
        <select class="form-select" name="confidentiality" id="editProfileConfidentiality">
          <option value="STANDARD">Standard</option><option value="RESTRICTED">Restricted</option><option value="PRIVILEGED">Privileged</option>
        </select>
      </div>
      <div class="mb-3"><label class="form-label">Linked Physical File <span class="text-muted small">(optional)</span></label>
        <select class="form-select" name="linkedPhysicalFileId" id="editProfileLinkedPhysicalFile">
          <option value="">— None —</option>
          <?php foreach (($matterPhysicalFiles ?? []) as $pf): ?>
            <option value="<?= e($pf['id']) ?>"><?= e($pf['barcode']) ?> — <?= e($pf['jacket_label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Save Profile</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="uploadModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Upload Version — <span id="uploadDocTitle"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="uploadForm" enctype="multipart/form-data">
    <input type="hidden" name="documentId" id="uploadDocId">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <div class="mb-3"><label class="form-label">File</label><input type="file" class="form-control" name="file" required></div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Upload</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="shareModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Share Document</h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="shareForm" data-action-url="actions/share_document_with_user.php">
    <input type="hidden" name="documentId" id="shareDocId">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <div class="mb-3"><label class="form-label">Share with</label>
        <select class="form-select" name="userId" required>
          <?php foreach (($allUsers ?? []) as $u): ?>
            <option value="<?= e($u['id']) ?>"><?= e($u['full_name']) ?> (<?= e(custodia_role_label($pdo, $u['role'])) ?>)</option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Only accounts on this system — they'll be notified. If the document isn't Protected, this just points them to it (they already have access via the matter); if it is, this also grants their view permission.</div>
      </div>
      <div class="mb-3"><label class="form-label">Access expires in (days) <span class="text-muted small">(optional — only applies to Protected documents; blank = no expiry)</span></label>
        <input type="number" class="form-control" name="expiresInDays" min="1" placeholder="e.g. 7">
      </div>
    </div>
    <div class="modal-footer">
      <button type="submit" class="btn btn-primary w-100">Share</button>
    </div>
  </form>
</div></div></div>

<div class="modal fade" id="previewModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-xl"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="previewTitle"></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body" style="position: relative;">
    <div id="previewBody"></div>
    <div id="previewWatermark" class="d-none" style="position:absolute; inset:0; pointer-events:none; overflow:hidden; display:flex; align-items:center; justify-content:center;">
      <div style="transform: rotate(-30deg); font-size: 1.4rem; font-weight: 600; color: rgba(0,0,0,0.12); white-space: nowrap; user-select:none;"></div>
    </div>
  </div>
  <div class="modal-footer">
    <a class="btn btn-outline-secondary" id="previewDownloadLink" target="_blank">Download</a>
  </div>
</div></div></div>

<div class="modal fade" id="requestDocAccessModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Request Access — <span id="requestDocAccessTitle"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="requestDocAccessForm">
    <input type="hidden" id="requestDocAccessDocId">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <div class="mb-3"><label class="form-label">Reason</label><textarea class="form-control" id="requestDocAccessReason" required></textarea></div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Submit Request</button></div>
  </form>
</div></div></div>

<div class="modal fade" id="grantDocAccessModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">Grant Access — <span id="grantDocAccessTitle"></span></h5><button class="btn-close" data-bs-dismiss="modal"></button></div>
  <form id="grantDocAccessForm" data-action-url="actions/grant_document_access.php">
    <input type="hidden" name="documentId" id="grantDocAccessDocId">
    <div class="modal-body">
      <div class="form-error alert alert-danger d-none"></div>
      <div class="mb-3"><label class="form-label">User</label>
        <select class="form-select" name="userId" required>
          <?php foreach (($allUsers ?? []) as $u): ?>
            <option value="<?= e($u['id']) ?>"><?= e($u['full_name']) ?> (<?= e(custodia_role_label($pdo, $u['role'])) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3"><label class="form-label">Reason</label><textarea class="form-control" name="reason" required></textarea></div>
      <div class="mb-3"><label class="form-label">Expires after <span class="text-muted small">(hours — optional, blank = no expiry)</span></label>
        <input type="number" class="form-control" name="expiresInHours" min="1" placeholder="e.g. 24">
      </div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Grant Access</button></div>
  </form>
</div></div></div>

<script>
const CUSTODIA_CURRENT_USER_NAME = <?= json_encode($user['full_name']) ?>;
let custodiaPrintBlockActive = false;
function custodiaBlockPrint() {
  if (custodiaPrintBlockActive) return;
  custodiaPrintBlockActive = true;
  window.__custodiaOriginalPrint = window.print;
  window.print = () => custodiaFlash('Printing is disabled for protected documents.', 'danger');
}
function custodiaUnblockPrint() {
  if (!custodiaPrintBlockActive) return;
  custodiaPrintBlockActive = false;
  if (window.__custodiaOriginalPrint) window.print = window.__custodiaOriginalPrint;
}

function openUploadModal(docId, title) {
  document.getElementById('uploadDocId').value = docId;
  document.getElementById('uploadDocTitle').textContent = title;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('uploadModal')).show();
}
function openShareModal(docId) {
  document.getElementById('shareDocId').value = docId;
  document.querySelector('#shareForm .form-error').classList.add('d-none');
  bootstrap.Modal.getOrCreateInstance(document.getElementById('shareModal')).show();
}

function openPreviewModal(docId, mimeType, title, duration, isProtected) {
  const downloadUrl = 'actions/download_document.php?documentId=' + encodeURIComponent(docId);
  const viewUrl = downloadUrl + '&view=1';
  document.getElementById('previewTitle').textContent = duration ? title + ' — ' + duration : title;

  const downloadLink = document.getElementById('previewDownloadLink');
  const watermark = document.getElementById('previewWatermark');
  if (isProtected) {
    downloadLink.classList.add('d-none');
    watermark.classList.remove('d-none');
    watermark.firstElementChild.textContent = CUSTODIA_CURRENT_USER_NAME + ' — ' + new Date().toLocaleString();
    custodiaBlockPrint();
  } else {
    downloadLink.classList.remove('d-none');
    downloadLink.href = downloadUrl;
    watermark.classList.add('d-none');
    custodiaUnblockPrint();
  }

  const body = document.getElementById('previewBody');
  body.innerHTML = '';

  if (mimeType === 'application/pdf') {
    body.innerHTML = '<embed src="' + viewUrl + '" type="application/pdf" style="width:100%;height:75vh;border:0;">';
  } else if (mimeType.startsWith('image/')) {
    const img = document.createElement('img');
    img.src = viewUrl;
    img.alt = title;
    img.style.cssText = 'max-width:100%;max-height:75vh;display:block;margin:0 auto;';
    if (isProtected) img.oncontextmenu = () => false;
    body.appendChild(img);
  } else if (mimeType.startsWith('audio/')) {
    body.innerHTML = '<audio controls style="width:100%;" src="' + viewUrl + '"></audio>';
  } else if (mimeType.startsWith('video/')) {
    body.innerHTML = '<video controls style="width:100%;max-height:75vh;" src="' + viewUrl + '"></video>';
  } else {
    body.innerHTML = '<div class="text-center text-muted py-5">Preview isn\'t available for this file type — use Download instead.</div>';
  }

  bootstrap.Modal.getOrCreateInstance(document.getElementById('previewModal')).show();
}

// Stop any playing audio/video and drop the preview source when the modal closes,
// so playback doesn't keep running in the background and the next preview starts clean.
// Also restores window.print() if a protected-document preview had disabled it.
document.getElementById('previewModal').addEventListener('hidden.bs.modal', () => {
  document.getElementById('previewBody').innerHTML = '';
  custodiaUnblockPrint();
});

function openRequestDocAccessModal(docId, title) {
  document.getElementById('requestDocAccessDocId').value = docId;
  document.getElementById('requestDocAccessTitle').textContent = title;
  document.getElementById('requestDocAccessReason').value = '';
  document.querySelector('#requestDocAccessForm .form-error').classList.add('d-none');
  bootstrap.Modal.getOrCreateInstance(document.getElementById('requestDocAccessModal')).show();
}
document.getElementById('requestDocAccessForm').addEventListener('submit', async (evt) => {
  evt.preventDefault();
  const errorBox = document.querySelector('#requestDocAccessForm .form-error');
  try {
    await custodiaPost('actions/create_access_request.php', {
      entityType: 'DIGITAL_DOCUMENT',
      entityId: document.getElementById('requestDocAccessDocId').value,
      requestType: 'VIEW_CONFIDENTIAL',
      reason: document.getElementById('requestDocAccessReason').value,
    });
    bootstrap.Modal.getOrCreateInstance(document.getElementById('requestDocAccessModal')).hide();
    custodiaFlash('Access request submitted.');
    window.location.reload();
  } catch (err) {
    errorBox.textContent = err.message;
    errorBox.classList.remove('d-none');
  }
});

function openGrantDocAccessModal(docId, title) {
  document.getElementById('grantDocAccessDocId').value = docId;
  document.getElementById('grantDocAccessTitle').textContent = title;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('grantDocAccessModal')).show();
}
custodiaWireActionForm(document.getElementById('grantDocAccessForm'), () => window.location.reload());

async function protectDocument(docId, protect) {
  const verb = protect ? 'Protect this document (view permission required, downloads disabled)?' : 'Remove protection from this document?';
  if (!confirm(verb)) return;
  try {
    await custodiaPost('actions/toggle_document_protection.php', { documentId: docId, protected: protect ? '1' : '0' });
    custodiaFlash(protect ? 'Document protected.' : 'Protection removed.');
    window.location.reload();
  } catch (err) { custodiaFlash(err.message, 'danger'); }
}
function openEditProfileModal(doc) {
  document.getElementById('editProfileDocId').value = doc.id;
  document.getElementById('editProfileLabel').textContent = doc.label;
  document.getElementById('editProfileTitle').value = doc.title;
  document.getElementById('editProfileType').value = doc.docType;
  document.getElementById('editProfileDescription').value = doc.description || '';
  document.getElementById('editProfileConfidentiality').value = doc.confidentiality;
  if (doc.authorId) document.getElementById('editProfileAuthor').value = doc.authorId;
  document.getElementById('editProfileLinkedPhysicalFile').value = doc.linkedPhysicalFileId || '';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('editProfileModal')).show();
}
async function lockDocument(docId) {
  try {
    await custodiaPost('actions/lock_document.php', { documentId: docId });
    custodiaFlash('Document locked for editing.');
    window.location.reload();
  } catch (err) { custodiaFlash(err.message, 'danger'); }
}
async function unlockDocument(docId) {
  try {
    await custodiaPost('actions/unlock_document.php', { documentId: docId });
    custodiaFlash('Lock released.');
    window.location.reload();
  } catch (err) { custodiaFlash(err.message, 'danger'); }
}

custodiaWireActionForm(document.getElementById('createDocForm'), () => window.location.reload());
custodiaWireActionForm(document.getElementById('editProfileForm'), () => window.location.reload());

document.getElementById('uploadForm').addEventListener('submit', async (evt) => {
  evt.preventDefault();
  const form = evt.target;
  const errorBox = form.querySelector('.form-error');
  errorBox.classList.add('d-none');
  try {
    await custodiaPostMultipart('actions/upload_document_version.php', new FormData(form));
    custodiaFlash('New version uploaded.');
    window.location.reload();
  } catch (err) {
    errorBox.textContent = err.message;
    errorBox.classList.remove('d-none');
  }
});

custodiaWireActionForm(document.getElementById('shareForm'), () => {
  bootstrap.Modal.getOrCreateInstance(document.getElementById('shareModal')).hide();
  custodiaFlash('Shared.');
  window.location.reload();
});
</script>
