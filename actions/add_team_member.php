<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/matters.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $matterId = custodia_required_post('matterId');
    $userId = custodia_required_post('userId');
    $roleOnMatter = custodia_required_post('roleOnMatter');
    return custodia_add_team_member($pdo, $user, $matterId, $userId, $roleOnMatter, custodia_client_ip());
});
