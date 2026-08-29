<?php
/**
 * Automated weekly operations report — a firm-wide digest for System
 * Admin/Records Manager, the same audience that already sees everything on
 * the dashboard. Deliberately built from direct, unscoped SQL rather than
 * routing through any per-viewer-scoped list function (custodia_list_
 * pending_for_approver() etc. answer "what's actionable BY this specific
 * user", which is the wrong question for a firm-wide count) — every number
 * here is a plain firm-wide COUNT/SELECT, matching the same non-scoped style
 * custodia_analytics_group_comparison() already uses in includes/analytics.php.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/physical_files.php';

const CUSTODIA_REPORT_PERIOD_DAYS = 7;

/** @return array{periodStart:string,periodEnd:string,newMattersCount:int,newMatters:array,newClientsCount:int,newClients:array,overdueFilesCount:int,pendingMovementsCount:int,pendingAccessRequestsCount:int,pendingDestructionReviewCount:int,documentsUploadedCount:int,auditEventsCount:int,groupsWithNoMembersCount:int,totalGroupsCount:int} */
function custodia_generate_weekly_report(PDO $pdo, array $firmWideActor, DateTimeImmutable $periodStart, DateTimeImmutable $periodEnd): array
{
    $startStr = $periodStart->format('Y-m-d H:i:s');
    $endStr = $periodEnd->format('Y-m-d H:i:s');

    $newMattersStmt = $pdo->prepare(
        'SELECT m.matter_number, c.name AS client_name, m.practice_area, m.open_date
         FROM matters m JOIN clients c ON c.id = m.client_id
         WHERE m.open_date BETWEEN :start AND :end
         ORDER BY m.open_date DESC LIMIT 10'
    );
    $newMattersStmt->execute(['start' => $startStr, 'end' => $endStr]);
    $newMatters = $newMattersStmt->fetchAll();

    $newMattersCountStmt = $pdo->prepare('SELECT COUNT(*) FROM matters WHERE open_date BETWEEN :start AND :end');
    $newMattersCountStmt->execute(['start' => $startStr, 'end' => $endStr]);

    $newClientsStmt = $pdo->prepare(
        'SELECT name, created_at FROM clients WHERE created_at BETWEEN :start AND :end ORDER BY created_at DESC LIMIT 10'
    );
    $newClientsStmt->execute(['start' => $startStr, 'end' => $endStr]);
    $newClients = $newClientsStmt->fetchAll();

    $newClientsCountStmt = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE created_at BETWEEN :start AND :end');
    $newClientsCountStmt->execute(['start' => $startStr, 'end' => $endStr]);

    // Overdue files: a live snapshot at generation time, not period-boxed —
    // "overdue" is a current-state fact, not something that happened during
    // the week. Reuses custodia_list_overdue_files() rather than a hand-
    // rolled query, so "overdue" means exactly what the rest of the app
    // already means by it (most recent completed CHECK_OUT per file, not
    // just any past-due movement row) — $firmWideActor guarantees the
    // firm-wide, unscoped result.
    $overdueFilesCount = count(custodia_list_overdue_files($pdo, $firmWideActor));

    $pendingMovementsCount = (int) $pdo->query("SELECT COUNT(*) FROM custody_movements WHERE status = 'PENDING_APPROVAL'")->fetchColumn();

    $pendingAccessRequestsCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM access_requests WHERE status = 'PENDING' AND request_type != 'DESTRUCTION_REVIEW'"
    )->fetchColumn();
    $pendingDestructionReviewCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM access_requests WHERE status = 'PENDING' AND request_type = 'DESTRUCTION_REVIEW'"
    )->fetchColumn();

    $documentsUploadedStmt = $pdo->prepare('SELECT COUNT(*) FROM document_versions WHERE uploaded_at BETWEEN :start AND :end');
    $documentsUploadedStmt->execute(['start' => $startStr, 'end' => $endStr]);

    $auditEventsStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE created_at BETWEEN :start AND :end');
    $auditEventsStmt->execute(['start' => $startStr, 'end' => $endStr]);

    $groupsStmt = $pdo->query(
        'SELECT pg.id, COUNT(pgm.id) AS member_count
         FROM practice_groups pg LEFT JOIN practice_group_members pgm ON pgm.practice_group_id = pg.id
         GROUP BY pg.id'
    );
    $groups = $groupsStmt->fetchAll();
    $groupsWithNoMembersCount = count(array_filter($groups, fn ($g) => (int) $g['member_count'] === 0));

    return [
        'periodStart' => $periodStart->format(DateTimeImmutable::ATOM),
        'periodEnd' => $periodEnd->format(DateTimeImmutable::ATOM),
        'newMattersCount' => (int) $newMattersCountStmt->fetchColumn(),
        'newMatters' => $newMatters,
        'newClientsCount' => (int) $newClientsCountStmt->fetchColumn(),
        'newClients' => $newClients,
        'overdueFilesCount' => $overdueFilesCount,
        'pendingMovementsCount' => $pendingMovementsCount,
        'pendingAccessRequestsCount' => $pendingAccessRequestsCount,
        'pendingDestructionReviewCount' => $pendingDestructionReviewCount,
        'documentsUploadedCount' => (int) $documentsUploadedStmt->fetchColumn(),
        'auditEventsCount' => (int) $auditEventsStmt->fetchColumn(),
        'groupsWithNoMembersCount' => $groupsWithNoMembersCount,
        'totalGroupsCount' => count($groups),
    ];
}

/** Earliest-created active SYSTEM_ADMIN — the deterministic actor for scheduled (non-interactive) audit entries, same convention bulk_grant_all_groups_all_matters.php already established. */
function custodia_reports_default_actor(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT * FROM users WHERE role = 'SYSTEM_ADMIN' AND is_active = 1 ORDER BY created_at ASC LIMIT 1");
    $actor = $stmt->fetch();
    if (!$actor) {
        throw custodia_not_found('No active System Administrator account exists to attribute this report to.');
    }
    return $actor;
}

/**
 * Shared by jobs/weekly_report.php (scheduled, $actor = null) and
 * actions/generate_weekly_report.php (manual, $actor = the clicking admin).
 * Generates the report, saves it, writes the audit entry, and notifies
 * every active SYSTEM_ADMIN/RECORDS_MANAGER — the same three-step shape
 * every other state-changing flow in this app already follows (see
 * custodia_grant_group_matter_access() for the closest analog: insert +
 * audit + notify, all in one transaction).
 */
function custodia_run_weekly_report_job(PDO $pdo, ?array $actor, string $via, string $ipAddress = '127.0.0.1'): string
{
    $actor = $actor ?? custodia_reports_default_actor($pdo);
    $periodEnd = new DateTimeImmutable('now');
    $periodStart = $periodEnd->sub(new DateInterval('P' . CUSTODIA_REPORT_PERIOD_DAYS . 'D'));

    $content = custodia_generate_weekly_report($pdo, $actor, $periodStart, $periodEnd);
    $contentJson = json_encode($content, JSON_UNESCAPED_SLASHES);

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare(
            'INSERT INTO system_reports (id, report_type, period_start, period_end, content_json, generated_via, generated_by_id)
             VALUES (:id, "WEEKLY_OPERATIONS", :start, :end, :content, :via, :actor)'
        )->execute([
            'id' => $id,
            'start' => $periodStart->format('Y-m-d H:i:s'),
            'end' => $periodEnd->format('Y-m-d H:i:s'),
            'content' => $contentJson,
            'via' => $via,
            'actor' => $actor['id'],
        ]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'REPORT_GENERATED', 'entityType' => 'SYSTEM_REPORT', 'entityId' => $id,
            'ipAddress' => $ipAddress, 'metadata' => ['reportType' => 'WEEKLY_OPERATIONS', 'via' => $via],
        ]);

        $recipients = $pdo->query("SELECT id FROM users WHERE role IN ('SYSTEM_ADMIN', 'RECORDS_MANAGER') AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
        $summary = "{$content['newMattersCount']} new matters, {$content['overdueFilesCount']} overdue files, "
            . ($content['pendingMovementsCount'] + $content['pendingAccessRequestsCount']) . ' pending approvals.';
        custodia_notify_users(
            $pdo, $recipients, 'WEEKLY_REPORT_READY',
            'Weekly Operations Report is ready', $summary, 'SYSTEM_REPORT', $id
        );

        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** @return array[] most recent reports, newest first. */
function custodia_list_reports(PDO $pdo, int $limit = 50): array
{
    $stmt = $pdo->prepare(
        'SELECT sr.*, u.full_name AS generated_by_name
         FROM system_reports sr JOIN users u ON u.id = sr.generated_by_id
         ORDER BY sr.created_at DESC LIMIT :lim'
    );
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function custodia_get_report(PDO $pdo, string $id): array
{
    $stmt = $pdo->prepare(
        'SELECT sr.*, u.full_name AS generated_by_name
         FROM system_reports sr JOIN users u ON u.id = sr.generated_by_id
         WHERE sr.id = :id'
    );
    $stmt->execute(['id' => $id]);
    $report = $stmt->fetch();
    if (!$report) {
        throw custodia_not_found('Report not found.');
    }
    return $report;
}
