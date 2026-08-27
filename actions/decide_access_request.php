<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/access_requests.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $requestId = custodia_required_post('requestId');
    $approve = ($_POST['approve'] ?? '0') === '1';
    $reason = trim((string) ($_POST['reason'] ?? '')) ?: null;
    $expiresInHoursRaw = trim((string) ($_POST['expiresInHours'] ?? ''));
    $expiresInHours = $expiresInHoursRaw !== '' ? max(1, (int) $expiresInHoursRaw) : null;
    return custodia_decide_access_request($pdo, $user, $requestId, $approve, $reason, custodia_client_ip(), $expiresInHours);
});
