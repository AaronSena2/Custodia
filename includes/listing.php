<?php
/**
 * Shared filter/sort/paginate helpers for the app's list pages (Matters,
 * Clients, User Accounts, and the Physical Files/Digital Documents tabs
 * within a matter). Deliberately in-memory (array_filter/usort/array_slice)
 * rather than pushed into SQL — every list this app deals in (matters,
 * clients, users, a single matter's files/documents) tops out in the
 * hundreds of rows, so fetching the already-permission-scoped set once and
 * slicing it in PHP is simpler and just as fast as duplicating each list
 * function's RBAC-aware WHERE-building in a second, filterable/sortable
 * form. The one table that doesn't use this — the firm-wide audit log,
 * which is unbounded and grows forever — keeps its existing SQL-level
 * LIMIT/OFFSET pagination in includes/audit_query.php; this file only adds
 * a page-size selector there, via custodia_pagination_bar() reused on a
 * DB-paginated result shape.
 */

require_once __DIR__ . '/helpers.php';

const CUSTODIA_PAGE_SIZE_OPTIONS = [10, 25, 50, 100];

/** Builds a query string from the current request's $_GET, overridden/removed by $overrides (null/'' removes the key). */
function custodia_query_with(array $overrides): string
{
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
    return http_build_query($params);
}

/**
 * Reads page/pageSize/sort/dir from $_GET, clamped to sane values.
 * $sortable is the whitelist of field keys sortable on this page/table —
 * anything else in $_GET['sort'] falls back to $defaultSort.
 */
function custodia_listing_params(array $sortable, string $defaultSort, string $defaultDir = 'ASC', int $defaultPageSize = 25): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));

    $pageSize = (int) ($_GET['pageSize'] ?? $defaultPageSize);
    if (!in_array($pageSize, CUSTODIA_PAGE_SIZE_OPTIONS, true)) {
        $pageSize = $defaultPageSize;
    }

    $sort = $_GET['sort'] ?? $defaultSort;
    if (!in_array($sort, $sortable, true)) {
        $sort = $defaultSort;
    }

    $dir = strtoupper((string) ($_GET['dir'] ?? $defaultDir)) === 'DESC' ? 'DESC' : 'ASC';

    return ['page' => $page, 'pageSize' => $pageSize, 'sort' => $sort, 'dir' => $dir];
}

/**
 * Applies exact-match $filters (field => value, skipped when '' or null),
 * an optional case-insensitive substring $search across $searchFields, then
 * sorts by $params['sort']/['dir'] and slices to $params['page']/['pageSize'].
 *
 * @return array{rows: array, total: int, page: int, pageSize: int, totalPages: int}
 */
function custodia_apply_listing(array $rows, array $params, array $filters = [], string $search = '', array $searchFields = []): array
{
    foreach ($filters as $field => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $rows = array_values(array_filter($rows, fn ($r) => (string) ($r[$field] ?? '') === (string) $value));
    }

    $search = trim($search);
    if ($search !== '' && $searchFields) {
        $needle = mb_strtolower($search);
        $rows = array_values(array_filter($rows, function ($r) use ($needle, $searchFields) {
            foreach ($searchFields as $f) {
                if (str_contains(mb_strtolower((string) ($r[$f] ?? '')), $needle)) {
                    return true;
                }
            }
            return false;
        }));
    }

    $sort = $params['sort'];
    $dir = $params['dir'];
    usort($rows, function ($a, $b) use ($sort, $dir) {
        $av = $a[$sort] ?? '';
        $bv = $b[$sort] ?? '';
        $cmp = (is_numeric($av) && is_numeric($bv)) ? ($av <=> $bv) : strcasecmp((string) $av, (string) $bv);
        return $dir === 'DESC' ? -$cmp : $cmp;
    });

    $total = count($rows);
    $pageSize = $params['pageSize'];
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page = min($params['page'], $totalPages);
    $offset = ($page - 1) * $pageSize;

    return [
        'rows' => array_slice($rows, $offset, $pageSize),
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'totalPages' => $totalPages,
    ];
}

/** A clickable, sort-indicating <th> label. Click toggles ASC/DESC on repeat clicks; switching fields starts at ASC. */
function custodia_sort_link(string $label, string $field, array $params): string
{
    $isActive = $params['sort'] === $field;
    $nextDir = $isActive && $params['dir'] === 'ASC' ? 'DESC' : 'ASC';
    $arrow = $isActive ? ($params['dir'] === 'ASC' ? ' ▲' : ' ▼') : '';
    $href = e('?' . custodia_query_with(['sort' => $field, 'dir' => $nextDir, 'page' => 1]));
    $cls = $isActive ? 'text-decoration-none text-body fw-semibold' : 'text-decoration-none text-muted';
    return "<a href=\"{$href}\" class=\"{$cls}\">" . e($label) . $arrow . '</a>';
}

/**
 * "Showing X–Y of Z" + a page-size <select> + Prev/page-number/Next links.
 * Works for both custodia_apply_listing()'s result shape and a DB-paginated
 * result normalized to the same {total,page,pageSize,totalPages} keys (see
 * audit.php).
 */
function custodia_pagination_bar(array $result): string
{
    $page = $result['page'];
    $pageSize = $result['pageSize'];
    $total = $result['total'];
    $totalPages = $result['totalPages'];
    $rangeStart = $total === 0 ? 0 : (($page - 1) * $pageSize) + 1;
    $rangeEnd = min($total, $page * $pageSize);

    ob_start();
    ?>
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2 small text-muted">
      <div>Showing <?= $rangeStart ?>–<?= $rangeEnd ?> of <?= number_format($total) ?></div>
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <div class="d-flex align-items-center gap-1">
          <label class="mb-0" for="listingPageSize">Per page</label>
          <select class="form-select form-select-sm" id="listingPageSize" style="width: auto;" onchange="custodiaSetQueryParam('pageSize', this.value)">
            <?php foreach (CUSTODIA_PAGE_SIZE_OPTIONS as $opt): ?>
              <option value="<?= $opt ?>" <?= $pageSize === $opt ? 'selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($totalPages > 1): ?>
          <div class="d-flex gap-1 align-items-center">
            <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= e(custodia_query_with(['page' => max(1, $page - 1)])) ?>">‹ Prev</a>
            <span class="px-1">Page <?= $page ?> of <?= $totalPages ?></span>
            <a class="btn btn-sm btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= e(custodia_query_with(['page' => min($totalPages, $page + 1)])) ?>">Next ›</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}
