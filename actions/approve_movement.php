<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/custody.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $movementId = custodia_required_post('movementId');
    return custodia_approve_movement($pdo, $user, $movementId, custodia_client_ip());
});
