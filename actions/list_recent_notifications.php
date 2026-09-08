<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/notifications.php';

custodia_run_action(function (PDO $pdo, array $user) {
    return custodia_sidebar_notifications_payload($pdo, $user['id']);
});
