<?php
/**
 * Creates or updates the firm-wide default retention policy — security
 * review 2026-09-03, finding 2.1. See
 * includes/retention_policies.php's custodia_set_default_retention_policy().
 */
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/retention_policies.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $retentionYears = (int) custodia_required_post('retentionYears');
    $action = custodia_required_post('action');
    return custodia_set_default_retention_policy($pdo, $user, $retentionYears, $action, custodia_client_ip());
});
