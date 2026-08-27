<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/roles.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $roleKey = custodia_required_post('roleKey');
    $label = custodia_required_post('label');
    return custodia_create_role($pdo, $user, $roleKey, $label, custodia_client_ip());
});
