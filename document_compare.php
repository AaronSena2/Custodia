<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/digital_documents.php';

$user = custodia_require_login();
$pdo = custodia_db();

$documentId = $_GET['documentId'] ?? '';
if ($documentId === '') {
    header('Location: documents.php');
    exit;
}

try {
    $doc = custodia_find_document($pdo, $user, $documentId, custodia_client_ip());
} catch (CustodiaHttpException $e) {
    http_response_code($e->status);
    $pageTitle = 'Compare Versions';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="alert alert-danger">' . e($e->getMessage()) . '</div>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$versionNumbers = array_map(fn ($v) => (int) $v['version_number'], $doc['versions']);
if (count($versionNumbers) < 2) {
    $pageTitle = 'Compare Versions';
    require __DIR__ . '/includes/layout_header.php';
    echo '<div class="alert alert-warning">This document only has one version — nothing to compare yet.</div>';
    echo '<a class="btn btn-secondary btn-sm" href="matter.php?id=' . e($doc['matter_id']) . '&tab=documents">Back to Documents</a>';
    require __DIR__ . '/includes/layout_footer.php';
    exit;
}

$latest = max($versionNumbers);
$secondLatest = max(array_filter($versionNumbers, fn ($v) => $v !== $latest));
$versionA = isset($_GET['a']) ? (int) $_GET['a'] : $secondLatest;
$versionB = isset($_GET['b']) ? (int) $_GET['b'] : $latest;

$result = custodia_compare_document_versions($pdo, $user, $documentId, $versionA, $versionB);

$pageTitle = 'Compare Versions — ' . $doc['title'];
$activeNav = 'documents';
require __DIR__ . '/includes/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div>
    <h1 class="h4 mb-1"><?= e($doc['title']) ?></h1>
    <div class="text-muted small"><?= e(custodia_doc_label((int) $doc['doc_number'])) ?> · Version Comparison</div>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="matter.php?id=<?= e($doc['matter_id']) ?>&tab=documents">Back to Documents</a>
</div>

<form method="get" action="document_compare.php" class="row g-2 align-items-end mb-4" style="max-width: 520px;">
  <input type="hidden" name="documentId" value="<?= e($documentId) ?>">
  <div class="col-auto">
    <label class="form-label small mb-0">From version</label>
    <select class="form-select form-select-sm" name="a">
      <?php foreach ($versionNumbers as $vn): ?>
        <option value="<?= $vn ?>" <?= $vn === $versionA ? 'selected' : '' ?>>v<?= $vn ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto pb-2">→</div>
  <div class="col-auto">
    <label class="form-label small mb-0">To version</label>
    <select class="form-select form-select-sm" name="b">
      <?php foreach ($versionNumbers as $vn): ?>
        <option value="<?= $vn ?>" <?= $vn === $versionB ? 'selected' : '' ?>>v<?= $vn ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto">
    <button class="btn btn-primary btn-sm" type="submit">Compare</button>
  </div>
</form>

<?php if (!$result['comparable']): ?>
  <div class="alert alert-warning"><?= e($result['reason']) ?></div>
<?php else: ?>
  <?php if ($result['diff']['truncated']): ?>
    <div class="alert alert-secondary small">This document is long — comparison was limited to the first <?= CUSTODIA_DIFF_MAX_PARAGRAPHS ?> paragraphs of each version.</div>
  <?php endif; ?>
  <div class="d-flex gap-3 small mb-2">
    <span><span class="diff-del-swatch"></span> Removed in v<?= $versionB ?></span>
    <span><span class="diff-ins-swatch"></span> Added in v<?= $versionB ?></span>
  </div>
  <div class="card">
    <div class="card-body diff-view">
      <?php foreach ($result['diff']['ops'] as $op): ?>
        <?php if ($op['type'] === 'equal'): ?>
          <p class="diff-equal"><?= e($op['text']) ?></p>
        <?php elseif ($op['type'] === 'delete'): ?>
          <p class="diff-del"><?= e($op['text']) ?></p>
        <?php elseif ($op['type'] === 'insert'): ?>
          <p class="diff-ins"><?= e($op['text']) ?></p>
        <?php elseif ($op['type'] === 'replace'): ?>
          <p class="diff-equal">
            <?php foreach ($op['words'] as $w): ?>
              <?php if ($w['type'] === 'equal'): ?>
                <?= e($w['value']) ?>&nbsp;
              <?php elseif ($w['type'] === 'delete'): ?>
                <span class="diff-del"><?= e($w['value']) ?></span>&nbsp;
              <?php else: ?>
                <span class="diff-ins"><?= e($w['value']) ?></span>&nbsp;
              <?php endif; ?>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php if (empty($result['diff']['ops'])): ?>
        <p class="text-muted">These two versions are identical.</p>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_footer.php'; ?>
