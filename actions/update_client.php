<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/clients.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $clientId = custodia_required_post('clientId');
    return custodia_update_client($pdo, $user, $clientId, [
        'name' => custodia_required_post('name'),
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'address' => $_POST['address'] ?? '',
    ], custodia_client_ip());
});
