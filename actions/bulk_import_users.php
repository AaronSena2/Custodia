<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/users.php';
require __DIR__ . '/../includes/csv_import.php';

custodia_run_action(function (PDO $pdo, array $user) {
    if (empty($_FILES['file'])) {
        throw custodia_bad_request('No file was uploaded.');
    }
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw custodia_bad_request('The upload failed (error code ' . $_FILES['file']['error'] . '). Please try again.');
    }
    $rows = custodia_read_csv_rows($_FILES['file']['tmp_name']);
    return custodia_bulk_import_users($pdo, $user, $rows, custodia_client_ip());
});
