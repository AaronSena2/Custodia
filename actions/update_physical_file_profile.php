<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/physical_files.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $fileId = custodia_required_post('fileId');
    $jacketLabel = custodia_required_post('jacketLabel');
    $barcode = custodia_required_post('barcode');
    $locationId = trim((string) ($_POST['locationId'] ?? '')) ?: null;
    return custodia_update_physical_file_profile($pdo, $user, $fileId, $jacketLabel, $barcode, $locationId, custodia_client_ip());
});
