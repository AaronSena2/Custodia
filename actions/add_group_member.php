<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/practice_groups.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $groupId = custodia_required_post('practiceGroupId');
    $userId = custodia_required_post('userId');
    return custodia_add_group_member($pdo, $user, $groupId, $userId, custodia_client_ip());
});
