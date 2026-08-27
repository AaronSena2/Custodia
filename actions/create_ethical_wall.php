<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/matters.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $matterId = custodia_required_post('matterId');
    $userId = custodia_required_post('userId');
    $reason = custodia_required_post('reason');
    return custodia_create_ethical_wall($pdo, $user, $matterId, $userId, $reason, custodia_client_ip());
});
