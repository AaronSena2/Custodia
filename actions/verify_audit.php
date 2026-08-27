<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/audit_query.php';

custodia_run_action(function (PDO $pdo, array $user) {
    return custodia_audit_verify_integrity($pdo, $user);
});
