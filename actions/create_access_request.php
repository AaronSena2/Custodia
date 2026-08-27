<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/access_requests.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $entityType = custodia_required_post('entityType');
    $entityId = custodia_required_post('entityId');
    $requestType = custodia_required_post('requestType');
    $reason = custodia_required_post('reason');
    return custodia_create_access_request($pdo, $user, $entityType, $entityId, $requestType, $reason, custodia_client_ip());
});
