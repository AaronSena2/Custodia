<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/practice_groups.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $grantId = custodia_required_post('grantId');
    return custodia_revoke_group_matter_access($pdo, $user, $grantId, custodia_client_ip());
});
