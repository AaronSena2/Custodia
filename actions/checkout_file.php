<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/custody.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $fileId = custodia_required_post('fileId');
    $reason = custodia_required_post('reason');
    $dueBackAt = custodia_required_post('dueBackAt') . ' 23:59:59';
    return custodia_checkout($pdo, $user, $fileId, $reason, $dueBackAt, custodia_client_ip());
});
