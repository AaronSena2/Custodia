<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/users.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $userId = custodia_required_post('userId');
    return custodia_update_user($pdo, $user, $userId, [
        'employeeId' => custodia_required_post('employeeId'),
        'fullName' => custodia_required_post('fullName'),
        'email' => custodia_required_post('email'),
        'role' => custodia_required_post('role'),
        'barNumber' => $_POST['barNumber'] ?? '',
    ], custodia_client_ip());
});
