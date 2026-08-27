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

const CUSTODIA_ANALYTICS_DAYS = 30;

/** @return string[] matter ids the user can see, or [] (callers must never query with an empty IN()). */
function custodia_analytics_visible_matter_ids(PDO $pdo, array $user): array
{
    return array_column(custodia_list_matters_for_user($pdo, $user), 'id');
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
    $matterIds = custodia_analytics_visible_matter_ids($pdo, $user);
    if (empty($matterIds)) {
        return custodia_analytics_fill_daily_series([], $days);
    }

    $placeholders = [];
    $params = [];
    foreach ($matterIds as $i => $mid) {
        $key = "m{$i}";
        $placeholders[] = ":{$key}";
        $params[$key] = $mid;
    }
    $days = (int) $days;

    $sql = "SELECT DATE(dv.uploaded_at) AS day, COUNT(*) AS count
            FROM document_versions dv
            JOIN digital_documents dd ON dd.id = dv.document_id
            WHERE dd.matter_id IN (" . implode(',', $placeholders) . ")
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
    $matterIds = custodia_analytics_visible_matter_ids($pdo, $user);
    if (empty($matterIds)) {
        return [];
    }

    $placeholders = [];
    $params = [];
    foreach ($matterIds as $i => $mid) {
        $key = "m{$i}";
        $placeholders[] = ":{$key}";
        $params[$key] = $mid;
    }

    $sql = "SELECT dv.mime_type
            FROM document_versions dv
            JOIN digital_documents dd ON dd.id = dv.document_id AND dv.version_number = dd.current_version_no
            WHERE dd.matter_id IN (" . implode(',', $placeholders) . ')';
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
    ];
}
