<?php
/** Retention policy configuration — PHP port of RetentionPoliciesService. */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/permissions.php';

function custodia_list_retention_policies(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM retention_policies ORDER BY practice_area ASC')->fetchAll();
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
