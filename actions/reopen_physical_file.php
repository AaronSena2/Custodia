<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/physical_files.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $fileId = custodia_required_post('fileId');
    return custodia_reopen_physical_file($pdo, $user, $fileId, custodia_client_ip());
});
