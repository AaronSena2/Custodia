<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/roles.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $roleKey = custodia_required_post('roleKey');
    return custodia_delete_role($pdo, $user, $roleKey, custodia_client_ip());
});
