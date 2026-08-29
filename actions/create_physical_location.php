<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/physical_files.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $building = custodia_required_post('building');
    $room = custodia_required_post('room');
    $shelf = custodia_required_post('shelf');
    $bin = trim((string) ($_POST['bin'] ?? '')) ?: null;
    $locationType = custodia_required_post('locationType');
    return custodia_create_physical_location($pdo, $user, $building, $room, $shelf, $bin, $locationType, custodia_client_ip());
});
