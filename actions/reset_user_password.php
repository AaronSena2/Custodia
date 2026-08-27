<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/users.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $userId = custodia_required_post('userId');
    $newPassword = custodia_required_post('newPassword');
    return custodia_reset_user_password($pdo, $user, $userId, $newPassword, custodia_client_ip());
});
