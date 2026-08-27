<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/custody.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $fileId = custodia_required_post('fileId');
    $locationId = custodia_required_post('locationId');
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $isOverride = ($_POST['override'] ?? '0') === '1';

    if ($isOverride) {
        return custodia_override_checkin($pdo, $user, $fileId, $locationId, $reason ?: 'Override check-in.', custodia_client_ip());
    }
    return custodia_checkin($pdo, $user, $fileId, $locationId, $reason ?: null, custodia_client_ip());
});
