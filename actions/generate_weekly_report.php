<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/reports.php';

custodia_run_action(function (PDO $pdo, array $user) {
    if (!in_array($user['role'], ['SYSTEM_ADMIN', 'RECORDS_MANAGER'], true)) {
        throw custodia_forbidden('Only a System Administrator or Records Manager may generate a report.');
    }
    $id = custodia_run_weekly_report_job($pdo, $user, 'MANUAL', custodia_client_ip());
    return ['id' => $id];
});
