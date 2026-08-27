<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/clients.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $clientId = custodia_required_post('clientId');
    return custodia_reactivate_client($pdo, $user, $clientId, custodia_client_ip());
});
