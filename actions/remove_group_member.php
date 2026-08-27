<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/practice_groups.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $membershipId = custodia_required_post('membershipId');
    return custodia_remove_group_member($pdo, $user, $membershipId, custodia_client_ip());
});
