<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/permissions.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $grants = $_POST['grants'] ?? [];
    if (!is_array($grants)) {
        throw custodia_bad_request('Malformed request.');
    }
    return custodia_update_role_permissions($pdo, $user, $grants, custodia_client_ip());
});
