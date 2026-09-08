<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/matters.php';
require_once __DIR__ . '/includes/digital_documents.php';
require_once __DIR__ . '/includes/access_requests.php';
require_once __DIR__ . '/includes/physical_files.php';
require_once __DIR__ . '/includes/listing.php';

$user = custodia_require_login();
$pdo = custodia_db();

$matters = custodia_list_matters_for_user($pdo, $user);

// Default to whichever matter the user most recently viewed (matter.php
// remembers this in the session on every authorized load) rather than an
// arbitrary first matter — the normal workflow is "open a matter, then come
// here to add a document for it," so this default should already be right
// most of the time. Falls back to the first matter if there's no remembered
// one, or if it's since gone out of this user's reach (matter access
// revoked, etc. — custodia_list_matters_for_user() already only returns
// what they can currently see).
$matterIds = array_column($matters, 'id');
$lastViewedMatterId = $_SESSION['custodia_last_matter_id'] ?? null;
$matterId = $_GET['matterId']
    ?? (in_array($lastViewedMatterId, $matterIds, true) ? $lastViewedMatterId : null)
    ?? ($matters[0]['id'] ?? '');

// Keep the remembered matter in sync when the user switches it right here
// too (via the Matter dropdown below), not just when it comes from matter.php.
if ($matterId !== '') {
    $_SESSION['custodia_last_matter_id'] = $matterId;
}

$allDocs = [];
$matterPhysicalFiles = [];
$docsError = null;
if ($matterId !== '') {
    try {
        $allDocs = custodia_list_documents_for_matter($pdo, $user, $matterId);
        $matterPhysicalFiles = custodia_list_files_for_matter($pdo, $user, $matterId);
    } catch (CustodiaHttpException $e) {
        $docsError = $e->getMessage();
    }
}

$docTypes = array_values(array_unique(array_column($allDocs, 'doc_type')));
sort($docTypes);

$docFilters = ['doc_type' => $_GET['docType'] ?? '', 'confidentiality' => $_GET['confidentiality'] ?? ''];
$docSearch = trim($_GET['q'] ?? '');
$docListParams = custodia_listing_params(['doc_number', 'title', 'doc_type', 'confidentiality', 'current_version_no', 'created_at'], 'created_at', 'DESC');
$docResult = custodia_apply_listing($allDocs, $docListParams, $docFilters, $docSearch, ['title', 'description', 'author_name']);

$usersStmt = $pdo->query('SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name');
$allUsers = $usersStmt->fetchAll();

$canManageProtection = custodia_user_has_permission($pdo, $user, 'override_document_protection');
$canGrantDocAccess = $matterId !== '' && custodia_can_decide_matter_access($pdo, $user, $matterId);

$pageTitle = 'Documents';
$activeNav = 'documents';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <div class="page-title">Digital Documents</div>
  <form method="get" action="documents.php" class="d-flex align-items-center gap-2">
    <label class="form-label mb-0 small text-muted">Matter</label>
    <select class="form-select form-select-sm" name="matterId" onchange="this.form.submit()" style="min-width: 260px;">
      <?php foreach ($matters as $m): ?>
        <option value="<?= e($m['id']) ?>" <?= $m['id'] === $matterId ? 'selected' : '' ?>><?= e($m['matter_number']) ?> — <?= e($m['client_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (empty($matters)): ?>
  <div class="text-center text-muted py-5">No matters visible to your account yet.</div>
<?php elseif ($docsError): ?>
  <div class="alert alert-danger"><?= e($docsError) ?></div>
<?php else: ?>
  <div class="d-flex justify-content-end mb-2">
    <?php if ($user['role'] !== 'GUEST_AUDITOR'): ?>
      <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createDocModal">+ New Document</button>
    <?php endif; ?>
  </div>
  <?php if (!empty($allDocs)): ?>
  <form method="get" action="documents.php" class="filter-bar">
    <input type="hidden" name="matterId" value="<?= e($matterId) ?>">
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
              <td class="small"><?= custodia_extraction_status_badge($d['latest_version']['extraction_status'] ?? 'PENDING') ?></td>
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
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
