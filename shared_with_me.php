<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/access_requests.php';

$user = custodia_require_login();
$pdo = custodia_db();

$shared = custodia_list_shared_with_me($pdo, $user);

$pageTitle = 'Shared With Me';
$activeNav = 'shared_with_me';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="page-header">
  <div>
    <div class="page-title">Shared With Me</div>
    <div class="page-subtitle">Matters and documents someone has individually granted you access to — not your normal team assignments or department-wide access.</div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header bg-white fw-semibold">Matters</div>
      <div class="card-body">
        <?php if (empty($shared['matters'])): ?>
          <div class="text-center text-muted py-4">No matters have been individually shared with you.</div>
        <?php else: ?>
          <?php foreach ($shared['matters'] as $m): ?>
            <div class="border-bottom py-2">
              <a href="matter.php?id=<?= e($m['matter_id']) ?>" class="fw-semibold text-decoration-none"><?= e($m['matter_number']) ?></a>
              <span class="text-muted"> — <?= e($m['client_name']) ?></span>
              <div class="small text-muted">Granted by <?= e($m['approver_name']) ?> · <?= custodia_format_date($m['decided_at']) ?><?= $m['expires_at'] ? ' · expires ' . custodia_format_date($m['expires_at']) : '' ?></div>
              <?php if ($m['reason']): ?><div class="small fst-italic">"<?= e($m['reason']) ?>"</div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header bg-white fw-semibold">Documents</div>
      <div class="card-body">
        <?php if (empty($shared['documents'])): ?>
          <div class="text-center text-muted py-4">No documents have been individually shared with you.</div>
        <?php else: ?>
          <?php foreach ($shared['documents'] as $d): ?>
            <div class="border-bottom py-2">
              <a href="matter.php?id=<?= e($d['matter_id']) ?>&tab=documents" class="fw-semibold text-decoration-none"><?= e($d['title']) ?></a>
              <span class="text-muted mono small"> CUS-<?= str_pad((string) $d['doc_number'], 6, '0', STR_PAD_LEFT) ?></span>
              <div class="small text-muted">Matter <?= e($d['matter_number']) ?> · Granted by <?= e($d['approver_name']) ?> · <?= custodia_format_date($d['decided_at']) ?><?= $d['expires_at'] ? ' · expires ' . custodia_format_date($d['expires_at']) : '' ?></div>
              <?php if ($d['reason']): ?><div class="small fst-italic">"<?= e($d['reason']) ?>"</div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
