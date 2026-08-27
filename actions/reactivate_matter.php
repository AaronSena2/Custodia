<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/matters.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $matterId = custodia_required_post('matterId');
    return custodia_reactivate_matter($pdo, $user, $matterId, custodia_client_ip());
});
