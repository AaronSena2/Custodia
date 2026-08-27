<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/custody.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $movementId = custodia_required_post('movementId');
    $reason = custodia_required_post('reason');
    return custodia_reject_movement($pdo, $user, $movementId, $reason, custodia_client_ip());
});
