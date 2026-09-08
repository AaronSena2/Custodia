<?php
/**
 * Dashboard analytics — upload activity, file-type mix, audit activity, and
 * most-downloaded documents. Deliberately reuses the same RBAC scoping the
 * rest of the app already has rather than inventing a parallel rule set:
 * document-derived stats are scoped to custodia_list_matters_for_user()'s
 * matter set (same as every other document view), and audit-derived stats
 * reuse custodia_build_audit_where() from includes/audit_query.php (same
 * WHERE clause audit.php itself renders with — Guest/Auditor gets nothing,
 * Associate/Paralegal see only their own actions, Partner sees matters they
 * manage, Admin/Records Manager see everything).
 *
 * LIMIT/INTERVAL values are cast to int and interpolated directly (never
 * user input, always a PHP int literal from this file), matching how
 * includes/audit_query.php already handles LIMIT/OFFSET — MySQL's native
 * prepared-statement protocol (PDO::ATTR_EMULATE_PREPARES is off — see
 * includes/db.php) won't accept those as bound placeholders.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/matters.php';
require_once __DIR__ . '/audit_query.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/matter_access.php';

const CUSTODIA_ANALYTICS_DAYS = 30;

/**
 * @return string[] matter ids the user can see, or [] (callers must never
 *   query with an empty IN()). Memoized per user id for the lifetime of the
 *   request — this file calls it up to 7 times per dashboard load (once per
 *   matter-scoped chart/card), and custodia_list_matters_for_user() itself
 *   is not cheap (a multi-clause OR across team membership, approved access
 *   requests, and practice-group grants) — measured at 150-230ms per call
 *   for a real staff account, so 7 uncached calls alone cost over a second.
 *   Same memoization pattern custodia_role_label() already uses.
 */
function custodia_analytics_visible_matter_ids(PDO $pdo, array $user): array
{
    static $cache = [];
    if (!array_key_exists($user['id'], $cache)) {
        $cache[$user['id']] = array_column(custodia_list_matters_for_user($pdo, $user), 'id');
    }
    return $cache[$user['id']];
}

/**
 * Scopes a query to the matters/documents/files a user can see, without
 * paying for an 8,000+ placeholder IN() list when the answer is
 * "everything". That's not just firm-wide roles (SYSTEM_ADMIN/
 * RECORDS_MANAGER, who always see every matter) — after the practice-group
 * matter grants (see includes/practice_groups.php), most real staff are
 * members of some group and therefore also see every matter despite not
 * having a firm-wide role, so this compares the visible count against the
 * total matter count rather than special-casing role. Skipping the
 * placeholder list in either case was measured at 150-300ms saved per
 * query, across 7 queries, refetched every 45s while the dashboard is open.
 *
 * @return array{0: ?string[], 1: array<string,string>} [placeholders, params].
 *   placeholders is null to mean "no filter needed" — the caller should use
 *   '1=1'. Otherwise it's an IN()-ready list, empty if nothing is visible
 *   at all, in which case the caller should return its empty result
 *   without querying (matching every call site's existing behavior).
 */
function custodia_analytics_matter_scope(PDO $pdo, array $user): array
{
    if (in_array($user['role'], custodia_firm_wide_roles(), true)) {
        return [null, []];
    }
    $matterIds = custodia_analytics_visible_matter_ids($pdo, $user);

    static $totalMatters = null;
    if ($totalMatters === null) {
        $totalMatters = (int) $pdo->query('SELECT COUNT(*) FROM matters')->fetchColumn();
    }
    if (!empty($matterIds) && count($matterIds) >= $totalMatters) {
        return [null, []]; // sees every matter anyway (e.g. via a practice-group grant) — same fast path as a firm-wide role
    }

    return custodia_analytics_id_placeholders($matterIds);
}

/** Fills in zero-count days so the line chart has no gaps, oldest first. */
function custodia_analytics_fill_daily_series(array $rows, int $days): array
{
    $byDay = array_column($rows, 'count', 'day');
    $series = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = (new DateTimeImmutable("-{$i} days"))->format('Y-m-d');
        $series[] = ['day' => $day, 'count' => (int) ($byDay[$day] ?? 0)];
    }
    return $series;
}

/** @return array{day: string, count: int}[] document versions uploaded per day, oldest first. */
function custodia_analytics_upload_activity(PDO $pdo, array $user, int $days = CUSTODIA_ANALYTICS_DAYS): array
{
    [$placeholders, $params] = custodia_analytics_matter_scope($pdo, $user);
    if ($placeholders !== null && empty($placeholders)) {
        return custodia_analytics_fill_daily_series([], $days);
    }
    $days = (int) $days;
    $matterFilter = $placeholders === null ? '1=1' : ('dd.matter_id IN (' . implode(',', $placeholders) . ')');

    $sql = "SELECT DATE(dv.uploaded_at) AS day, COUNT(*) AS count
            FROM document_versions dv
            JOIN digital_documents dd ON dd.id = dv.document_id
            WHERE {$matterFilter}
              AND dv.uploaded_at >= (NOW() - INTERVAL {$days} DAY)
            GROUP BY DATE(dv.uploaded_at)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return custodia_analytics_fill_daily_series($stmt->fetchAll(), $days);
}

const CUSTODIA_FILE_TYPE_CATEGORIES = [
    'PDF' => ['application/pdf'],
    'Image' => ['image/'],
    'Audio' => ['audio/'],
    'Video' => ['video/'],
    'Word/Text' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml', 'text/'],
];

function custodia_analytics_categorize_mime(?string $mimeType): string
{
    if (!$mimeType) {
        return 'Unknown';
    }
    foreach (CUSTODIA_FILE_TYPE_CATEGORIES as $category => $prefixes) {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return $category;
            }
        }
    }
    return 'Other';
}

/** @return array{category: string, count: int}[] current-version file types across visible matters, largest first. */
function custodia_analytics_file_type_breakdown(PDO $pdo, array $user): array
{
    [$placeholders, $params] = custodia_analytics_matter_scope($pdo, $user);
    if ($placeholders !== null && empty($placeholders)) {
        return [];
    }
    $matterFilter = $placeholders === null ? '1=1' : ('dd.matter_id IN (' . implode(',', $placeholders) . ')');

    $sql = "SELECT dv.mime_type
            FROM document_versions dv
            JOIN digital_documents dd ON dd.id = dv.document_id AND dv.version_number = dd.current_version_no
            WHERE {$matterFilter}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $mimeType) {
        $category = custodia_analytics_categorize_mime($mimeType);
        $counts[$category] = ($counts[$category] ?? 0) + 1;
    }
    arsort($counts);

    $result = [];
    foreach ($counts as $category => $count) {
        $result[] = ['category' => $category, 'count' => $count];
    }
    return $result;
}

/** @return array{day: string, count: int}[] audit entries per day, RBAC-scoped like audit.php, oldest first. */
function custodia_analytics_audit_activity(PDO $pdo, array $user, int $days = CUSTODIA_ANALYTICS_DAYS): array
{
    try {
        [$where, $params] = custodia_build_audit_where($pdo, $user, []);
    } catch (CustodiaHttpException $e) {
        return custodia_analytics_fill_daily_series([], $days); // Guest/Auditor: no audit visibility at all
    }
    $days = (int) $days;

    $sql = "SELECT DATE(created_at) AS day, COUNT(*) AS count
            FROM audit_log
            WHERE ({$where}) AND created_at >= (NOW() - INTERVAL {$days} DAY)
            GROUP BY DATE(created_at)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return custodia_analytics_fill_daily_series($stmt->fetchAll(), $days);
}

/** @return array{actionType: string, count: int}[] top action types, RBAC-scoped, largest first. */
function custodia_analytics_action_type_breakdown(PDO $pdo, array $user, int $limit = 6): array
{
    try {
        [$where, $params] = custodia_build_audit_where($pdo, $user, []);
    } catch (CustodiaHttpException $e) {
        return [];
    }
    $limit = (int) $limit;

    $sql = "SELECT action_type, COUNT(*) AS count
            FROM audit_log
            WHERE ({$where})
            GROUP BY action_type
            ORDER BY count DESC
            LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(fn ($r) => ['actionType' => $r['action_type'], 'count' => (int) $r['count']], $stmt->fetchAll());
}

/** @return array{role: string, label: string, count: int}[] active users grouped by role, largest first — powers the dashboard's "at a glance" user breakdown card. */
function custodia_analytics_active_users_by_role(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT role, COUNT(*) AS count FROM users WHERE is_active = 1 GROUP BY role ORDER BY count DESC"
    );
    return array_map(fn ($r) => [
        'role' => $r['role'],
        'label' => custodia_role_label($pdo, $r['role']),
        'count' => (int) $r['count'],
    ], $stmt->fetchAll());
}

/** @return array{day: string, count: int}[] matters opened per day, RBAC-scoped like the rest of this file, oldest first. */
function custodia_analytics_matters_opened_series(PDO $pdo, array $user, int $days = CUSTODIA_ANALYTICS_DAYS): array
{
    [$placeholders, $params] = custodia_analytics_matter_scope($pdo, $user);
    if ($placeholders !== null && empty($placeholders)) {
        return custodia_analytics_fill_daily_series([], $days);
    }
    $days = (int) $days;
    $matterFilter = $placeholders === null ? '1=1' : ('id IN (' . implode(',', $placeholders) . ')');

    $sql = "SELECT DATE(open_date) AS day, COUNT(*) AS count
            FROM matters
            WHERE {$matterFilter}
              AND open_date >= (NOW() - INTERVAL {$days} DAY)
            GROUP BY DATE(open_date)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return custodia_analytics_fill_daily_series($stmt->fetchAll(), $days);
}

/** @return array{label: string, role: string, count: int}[] most active users by audit event count, RBAC-scoped like the rest of this file — an Associate/Paralegal only ever sees themselves, largest first. */
function custodia_analytics_top_users(PDO $pdo, array $user, int $limit = 8): array
{
    try {
        [$where, $params] = custodia_build_audit_where($pdo, $user, []);
    } catch (CustodiaHttpException $e) {
        return [];
    }
    $limit = (int) $limit;

    $sql = "SELECT al.actor_id, u.full_name, u.role, COUNT(*) AS count
            FROM audit_log al JOIN users u ON u.id = al.actor_id
            WHERE ({$where})
            GROUP BY al.actor_id, u.full_name, u.role
            ORDER BY count DESC
            LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(fn ($r) => ['label' => $r['full_name'], 'role' => $r['role'], 'count' => (int) $r['count']], $stmt->fetchAll());
}

/** Practice groups compared by team size vs. their current active-matter workload (matched by practice_area name, same denormalized-text relationship used throughout — see includes/practice_groups.php). Not RBAC-scoped: group membership and matter counts aren't confidential, same as the old "Active Matters by Practice Area" section this replaces. */
function custodia_analytics_group_comparison(PDO $pdo): array
{
    $sql = "SELECT pg.name,
                   COUNT(DISTINCT pgm.id) AS member_count,
                   COUNT(DISTINCT CASE WHEN m.status = 'ACTIVE' THEN m.id END) AS active_matter_count
            FROM practice_groups pg
            LEFT JOIN practice_group_members pgm ON pgm.practice_group_id = pg.id
            LEFT JOIN matters m ON m.practice_area = pg.name
            GROUP BY pg.id, pg.name
            ORDER BY active_matter_count DESC, member_count DESC";
    $stmt = $pdo->query($sql);

    return array_map(fn ($r) => [
        'name' => $r['name'],
        'memberCount' => (int) $r['member_count'],
        'activeMatterCount' => (int) $r['active_matter_count'],
    ], $stmt->fetchAll());
}

/**
 * "Most requested" trio for the dashboard — counts genuine self-service
 * access_requests (excludes ADMIN_GRANT/SHARED rows, which are admin-
 * initiated grants stored in the same table per includes/access_requests.php,
 * not organic demand), RBAC-scoped to visible matters, largest first.
 */
function custodia_analytics_most_requested_matters(PDO $pdo, array $user, int $limit = 6): array
{
    [$placeholders, $params] = custodia_analytics_matter_scope($pdo, $user);
    if ($placeholders !== null && empty($placeholders)) {
        return [];
    }
    $limit = (int) $limit;
    $matterFilter = $placeholders === null ? '1=1' : ('m.id IN (' . implode(',', $placeholders) . ')');

    $sql = "SELECT m.matter_number, c.name AS client_name, COUNT(*) AS count
            FROM access_requests ar
            JOIN matters m ON m.id = ar.entity_id AND ar.entity_type = 'MATTER'
            JOIN clients c ON c.id = m.client_id
            WHERE ar.request_type NOT IN ('ADMIN_GRANT', 'SHARED') AND {$matterFilter}
            GROUP BY m.id, m.matter_number, c.name
            ORDER BY count DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(fn ($r) => ['label' => "{$r['matter_number']} ({$r['client_name']})", 'count' => (int) $r['count']], $stmt->fetchAll());
}

function custodia_analytics_most_requested_files(PDO $pdo, array $user, int $limit = 6): array
{
    [$placeholders, $params] = custodia_analytics_matter_scope($pdo, $user);
    if ($placeholders !== null && empty($placeholders)) {
        return [];
    }
    $limit = (int) $limit;
    $matterFilter = $placeholders === null ? '1=1' : ('pf.matter_id IN (' . implode(',', $placeholders) . ')');

    $sql = "SELECT pf.barcode, pf.jacket_label, COUNT(*) AS count
            FROM access_requests ar
            JOIN physical_files pf ON pf.id = ar.entity_id AND ar.entity_type = 'PHYSICAL_FILE'
            WHERE ar.request_type NOT IN ('ADMIN_GRANT', 'SHARED') AND {$matterFilter}
            GROUP BY pf.id, pf.barcode, pf.jacket_label
            ORDER BY count DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(fn ($r) => ['label' => "{$r['barcode']} — {$r['jacket_label']}", 'count' => (int) $r['count']], $stmt->fetchAll());
}

/** Rolls MATTER/PHYSICAL_FILE/DIGITAL_DOCUMENT requests up to the owning client via a single COALESCE join, rather than three UNIONed subqueries each needing their own copy of the matterIds placeholder list. */
function custodia_analytics_most_requested_clients(PDO $pdo, array $user, int $limit = 6): array
{
    [$placeholders, $params] = custodia_analytics_matter_scope($pdo, $user);
    if ($placeholders !== null && empty($placeholders)) {
        return [];
    }
    $limit = (int) $limit;
    $matterFilter = $placeholders === null ? '1=1' : ('m.id IN (' . implode(',', $placeholders) . ')');

    $sql = "SELECT c.name, COUNT(*) AS count
            FROM access_requests ar
            LEFT JOIN matters m1 ON ar.entity_type = 'MATTER' AND m1.id = ar.entity_id
            LEFT JOIN physical_files pf ON ar.entity_type = 'PHYSICAL_FILE' AND pf.id = ar.entity_id
            LEFT JOIN digital_documents dd ON ar.entity_type = 'DIGITAL_DOCUMENT' AND dd.id = ar.entity_id
            JOIN matters m ON m.id = COALESCE(m1.id, pf.matter_id, dd.matter_id)
            JOIN clients c ON c.id = m.client_id
            WHERE ar.request_type NOT IN ('ADMIN_GRANT', 'SHARED') AND {$matterFilter}
            GROUP BY c.id, c.name
            ORDER BY count DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(fn ($r) => ['label' => $r['name'], 'count' => (int) $r['count']], $stmt->fetchAll());
}

/** @return array{label: string, doc: string, matter: string, createdAt: string}[] most recently created digital documents, RBAC-scoped, newest first. */
function custodia_analytics_latest_documents(PDO $pdo, array $user, int $limit = 6): array
{
    [$placeholders, $params] = custodia_analytics_matter_scope($pdo, $user);
    if ($placeholders !== null && empty($placeholders)) {
        return [];
    }
    $limit = (int) $limit;
    $matterFilter = $placeholders === null ? '1=1' : ('dd.matter_id IN (' . implode(',', $placeholders) . ')');

    $sql = "SELECT dd.doc_number, dd.title, dd.created_at, m.matter_number, c.name AS client_name, u.full_name AS created_by_name
            FROM digital_documents dd
            JOIN matters m ON m.id = dd.matter_id
            JOIN clients c ON c.id = m.client_id
            LEFT JOIN users u ON u.id = dd.created_by_id
            WHERE {$matterFilter}
            ORDER BY dd.created_at DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/** @return array{0: string[], 1: array<string,string>} numbered `:m{n}` placeholders + matching params for an IN() list — shared by every visible-matters query in this file. */
function custodia_analytics_id_placeholders(array $ids): array
{
    $placeholders = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $key = "m{$i}";
        $placeholders[] = ":{$key}";
        $params[$key] = $id;
    }
    return [$placeholders, $params];
}

/** @return array{label: string, count: int}[] most-downloaded documents, RBAC-scoped via the audit trail, largest first. */
function custodia_analytics_most_accessed_documents(PDO $pdo, array $user, int $limit = 8): array
{
    try {
        [$where, $params] = custodia_build_audit_where($pdo, $user, []);
    } catch (CustodiaHttpException $e) {
        return [];
    }
    $limit = (int) $limit;

    $sql = "SELECT entity_id, COUNT(*) AS count
            FROM audit_log
            WHERE ({$where}) AND action_type = 'DOWNLOAD' AND entity_type = 'DIGITAL_DOCUMENT'
            GROUP BY entity_id
            ORDER BY count DESC
            LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (empty($rows)) {
        return [];
    }

    $docStmt = $pdo->prepare('SELECT doc_number, title FROM digital_documents WHERE id = :id');
    $result = [];
    foreach ($rows as $row) {
        $docStmt->execute(['id' => $row['entity_id']]);
        $doc = $docStmt->fetch();
        if (!$doc) {
            continue; // document since deleted — skip rather than show a broken label
        }
        $result[] = ['label' => custodia_doc_label((int) $doc['doc_number']) . ' — ' . $doc['title'], 'count' => (int) $row['count']];
    }
    return $result;
}

/** Everything the dashboard's charts need, in one call — used for both the initial page render and the live-refresh endpoint. */
function custodia_dashboard_analytics(PDO $pdo, array $user): array
{
    return [
        'uploadActivity' => custodia_analytics_upload_activity($pdo, $user),
        'fileTypeBreakdown' => custodia_analytics_file_type_breakdown($pdo, $user),
        'auditActivity' => custodia_analytics_audit_activity($pdo, $user),
        'actionTypeBreakdown' => custodia_analytics_action_type_breakdown($pdo, $user),
        'mostAccessedDocuments' => custodia_analytics_most_accessed_documents($pdo, $user),
        'topUsers' => custodia_analytics_top_users($pdo, $user),
        'groupComparison' => custodia_analytics_group_comparison($pdo),
        'activeUsersByRole' => custodia_analytics_active_users_by_role($pdo),
        'mattersOpenedSeries' => custodia_analytics_matters_opened_series($pdo, $user),
    ];
}

/**
 * Security review 2026-09-03, finding 4.1: the dashboard's 45s live-refresh
 * timer (dashboard.php) and every fresh page load both call
 * custodia_dashboard_analytics(), which alone runs 9 queries — with the
 * page routinely left open across a shift, a review session measured 10+
 * of those round trips in a single sitting, each one recomputing charts
 * that hadn't actually changed. This wraps the same function with a short
 * server-side cache so repeat calls inside one TTL window are free.
 *
 * Keyed per user, not globally, because the result set is RBAC-scoped
 * (custodia_analytics_matter_scope() etc.) — two viewers can legitimately
 * get different numbers from the same tick. A plain file cache is used
 * instead of APCu/Redis so this works unmodified on a bare XAMPP install;
 * it lives under storage/.cache, which .gitignore already excludes the
 * same way it excludes the rest of storage/.
 */
function custodia_dashboard_analytics_cache_path(string $userId): string
{
    $dir = rtrim(custodia_config()['cache_path'], '/\\') . '/dashboard_analytics';
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    return $dir . '/' . hash('sha256', $userId) . '.json';
}

function custodia_dashboard_analytics_cached(PDO $pdo, array $user, int $ttlSeconds = 45): array
{
    $path = custodia_dashboard_analytics_cache_path($user['id']);

    if (is_file($path) && (time() - (int) @filemtime($path)) < $ttlSeconds) {
        $cached = json_decode((string) @file_get_contents($path), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $data = custodia_dashboard_analytics($pdo, $user);

    // Best-effort: a read-only storage mount or a filesystem hiccup should
    // never break the dashboard — it just falls back to computing fresh on
    // every call, same as before this cache existed.
    @file_put_contents($path, json_encode($data), LOCK_EX);

    return $data;
}
