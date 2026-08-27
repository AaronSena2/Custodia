<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/practice_groups.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $groupId = custodia_required_post('practiceGroupId');
    return custodia_update_practice_group($pdo, $user, $groupId, custodia_required_post('name'), custodia_client_ip());
});
