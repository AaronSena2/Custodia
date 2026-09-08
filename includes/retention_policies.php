<?php
/** Retention policy configuration — PHP port of RetentionPoliciesService. */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/permissions.php';

/**
 * Sentinel practice_area value for the firm-wide default retention policy
 * — security review 2026-09-03, finding 2.1: only 2 of 14 real practice
 * groups had a specific rule, so the retention/disposition workflow
 * (jobs/overdue_sweep.php) silently never ran for the other 12. A matter
 * whose practice area has no policy of its own now falls back to this
 * one, if configured, instead of being invisible to the workflow forever.
 * retention_policies.practice_area has no FK to the practice_groups
 * catalog (see schema.sql), so this just needs to be a value nobody would
 * type as a real practice group name — not reserved at the schema level.
 */
const CUSTODIA_RETENTION_DEFAULT_PRACTICE_AREA = '__FIRM_DEFAULT__';

function custodia_list_retention_policies(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM retention_policies ORDER BY practice_area ASC')->fetchAll();
}

/** The firm-wide default policy row, or null if none has been configured yet. */
function custodia_retention_default_policy(PDO $pdo): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM retention_policies WHERE practice_area = :pa AND trigger_event = 'MATTER_CLOSE' LIMIT 1");
    $stmt->execute(['pa' => CUSTODIA_RETENTION_DEFAULT_PRACTICE_AREA]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Practice groups (from the Admin → Practice Groups catalog) with no
 * retention policy of their own — i.e. relying entirely on the firm-wide
 * default, or, if none is configured, not covered by the retention
 * workflow at all. Lets the admin screen surface this instead of it only
 * being discoverable by noticing the sweep job never flags certain
 * matters — see finding 2.1's "audit this list whenever a new practice
 * group is added."
 */
function custodia_retention_uncovered_practice_groups(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT pg.name FROM practice_groups pg
         WHERE NOT EXISTS (
           SELECT 1 FROM retention_policies rp
           WHERE rp.practice_area = pg.name AND rp.trigger_event = 'MATTER_CLOSE'
         )
         ORDER BY pg.name ASC"
    );
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function custodia_create_retention_policy(PDO $pdo, array $user, string $practiceArea, int $retentionYears, string $action, string $triggerEvent, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'manage_retention_policies');

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare('INSERT INTO retention_policies (id, practice_area, retention_years, action, trigger_event) VALUES (:id, :pa, :yrs, :action, :trigger)')
            ->execute(['id' => $id, 'pa' => $practiceArea, 'yrs' => $retentionYears, 'action' => $action, 'trigger' => $triggerEvent]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'RETENTION_POLICY_CREATED', 'entityType' => 'RETENTION_POLICY', 'entityId' => $id,
            'ipAddress' => $ipAddress, 'metadata' => ['practiceArea' => $practiceArea, 'retentionYears' => $retentionYears, 'action' => $action, 'triggerEvent' => $triggerEvent],
        ]);
        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Creates or updates the firm-wide default retention policy (upsert on
 * the sentinel practice_area) — security review 2026-09-03, finding 2.1.
 * A dedicated function rather than reusing custodia_create_retention_policy()
 * because the default is a singleton: re-submitting it should correct the
 * existing row, not create a second, competing one.
 */
function custodia_set_default_retention_policy(PDO $pdo, array $user, int $retentionYears, string $action, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'manage_retention_policies');

    if ($retentionYears < 1) {
        throw custodia_bad_request('Retention years must be at least 1.');
    }
    if (!in_array($action, ['REVIEW', 'ARCHIVE', 'DESTROY'], true)) {
        throw custodia_bad_request('Invalid action.');
    }

    $pdo->beginTransaction();
    try {
        $existing = custodia_retention_default_policy($pdo);
        if ($existing) {
            $id = $existing['id'];
            $pdo->prepare('UPDATE retention_policies SET retention_years = :yrs, action = :action WHERE id = :id')
                ->execute(['yrs' => $retentionYears, 'action' => $action, 'id' => $id]);
        } else {
            $id = custodia_uuid();
            $pdo->prepare('INSERT INTO retention_policies (id, practice_area, retention_years, action, trigger_event) VALUES (:id, :pa, :yrs, :action, :trigger)')
                ->execute(['id' => $id, 'pa' => CUSTODIA_RETENTION_DEFAULT_PRACTICE_AREA, 'yrs' => $retentionYears, 'action' => $action, 'trigger' => 'MATTER_CLOSE']);
        }

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'RETENTION_POLICY_DEFAULT_SET', 'entityType' => 'RETENTION_POLICY', 'entityId' => $id,
            'ipAddress' => $ipAddress,
            'metadata' => ['retentionYears' => $retentionYears, 'action' => $action, 'wasUpdate' => $existing !== null],
        ]);
        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
