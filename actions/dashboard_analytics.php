<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/analytics.php';

custodia_run_action(function (PDO $pdo, array $user) {
    return custodia_dashboard_analytics_cached($pdo, $user);
});
