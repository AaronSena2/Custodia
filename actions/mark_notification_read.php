<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/notifications.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $notificationId = custodia_required_post('notificationId');
    custodia_mark_notification_read($pdo, $user['id'], $notificationId);
    return ['id' => $notificationId];
});
