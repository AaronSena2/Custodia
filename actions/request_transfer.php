<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/custody.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $fileId = custodia_required_post('fileId');
    $reason = custodia_required_post('reason');
    return custodia_request_transfer($pdo, $user, $fileId, $reason, custodia_client_ip());
});
