<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/physical_files.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $matterId = custodia_required_post('matterId');
    $jacketLabel = custodia_required_post('jacketLabel');
    $locationId = trim((string) ($_POST['locationId'] ?? '')) ?: null;
    $barcode = trim((string) ($_POST['barcode'] ?? '')) ?: null;
    return custodia_register_physical_file($pdo, $user, $matterId, $jacketLabel, $locationId, $barcode, custodia_client_ip());
});
