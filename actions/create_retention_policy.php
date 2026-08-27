<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/retention_policies.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $practiceArea = custodia_required_post('practiceArea');
    $retentionYears = (int) custodia_required_post('retentionYears');
    $action = custodia_required_post('action');
    $triggerEvent = custodia_required_post('triggerEvent');
    return custodia_create_retention_policy($pdo, $user, $practiceArea, $retentionYears, $action, $triggerEvent, custodia_client_ip());
});
