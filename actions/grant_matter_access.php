<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/access_requests.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $userId = custodia_required_post('userId');
    $matterId = custodia_required_post('matterId');
    $reason = custodia_required_post('reason');
    return custodia_admin_grant_matter_access($pdo, $user, $userId, $matterId, $reason, custodia_client_ip());
});
