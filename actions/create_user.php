<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/users.php';

custodia_run_action(function (PDO $pdo, array $user) {
    return custodia_create_user($pdo, $user, [
        'employeeId' => custodia_required_post('employeeId'),
        'fullName' => custodia_required_post('fullName'),
        'email' => custodia_required_post('email'),
        'role' => custodia_required_post('role'),
        'password' => custodia_required_post('password'),
        'barNumber' => $_POST['barNumber'] ?? '',
    ], custodia_client_ip());
});
