<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/notifications.php';

custodia_run_action(function (PDO $pdo, array $user) {
    custodia_mark_all_notifications_read($pdo, $user['id']);
    return ['ok' => true];
});
