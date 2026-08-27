<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/digital_documents.php';

$user = custodia_require_login();
$pdo = custodia_db();

$query = trim($_GET['q'] ?? '');
$results = $query !== '' ? custodia_search_documents($pdo, $user, $query) : [];

$pageTitle = 'Search';
$activeNav = 'search';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="mb-3">
  <h1 class="h4 mb-1">Document Search</h1>
</div>

<form method="get" action="search.php" class="d-flex gap-2 mb-4" style="max-width: 640px;">
  <input type="text" class="form-control" name="q" value="<?= e($query) ?>" placeholder="Search by title, doc number, or document content…" autofocus>
  <button class="btn btn-primary" type="submit">Search</button>
</form>

<?php if ($query === ''): ?>
  <div class="text-center text-muted py-5">Enter a search term above — a title, a phrase from a document, a description, or a doc number like <code>142</code> or <code>CUS-000142</code>.</div>
<?php elseif (empty($results)): ?>
  <div class="text-center text-muted py-5">No documents matched "<?= e($query) ?>" in the matters you can access.</div>
<?php else: ?>
  <p class="text-muted small">Found <?= count($results) ?> document<?= count($results) === 1 ? '' : 's' ?>.</p>
  <div class="list-group">
    <?php foreach ($results as $r): ?>
      <a class="list-group-item list-group-item-action" href="matter.php?id=<?= e($r['matter_id']) ?>&tab=documents">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <div class="fw-semibold">
              <?= e($r['title']) ?>
              <span class="badge text-bg-light border ms-1"><?= e(custodia_doc_label((int) $r['doc_number'], (int) $r['current_version_no'])) ?></span>
            </div>
            <div class="small text-muted">
              <?= e($r['matter']['matter_number'] ?? '') ?> — <?= e($r['matter']['client_name'] ?? '') ?>
              · <?= e($r['doc_type']) ?>
              <?= custodia_confidentiality_badge($r['confidentiality']) ?>
            </div>
            <?php if (!empty($r['snippet'])): ?>
              <div class="small mt-1 fst-italic"><?= e($r['snippet']) ?></div>
            <?php elseif (!empty($r['description'])): ?>
              <div class="small mt-1 text-muted"><?= e($r['description']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
