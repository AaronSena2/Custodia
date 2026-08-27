<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/practice_groups.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $groupId = custodia_required_post('practiceGroupId');
    $matterId = custodia_required_post('matterId');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    return custodia_grant_group_matter_access($pdo, $user, $groupId, $matterId, $reason ?: null, custodia_client_ip());
});
