<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/matter_access.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $matterId = custodia_required_post('matterId');
    $reason = custodia_required_post('reason');
    return custodia_bypass_ethical_wall($pdo, $user, $matterId, $reason, custodia_client_ip());
});
