<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/access_requests.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $requestId = custodia_required_post('requestId');
    return custodia_revoke_matter_access($pdo, $user, $requestId, custodia_client_ip());
});
