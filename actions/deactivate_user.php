<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/users.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $userId = custodia_required_post('userId');
    return custodia_deactivate_user($pdo, $user, $userId, custodia_client_ip());
});
