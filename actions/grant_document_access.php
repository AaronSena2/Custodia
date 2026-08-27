<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/access_requests.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $userId = custodia_required_post('userId');
    $documentId = custodia_required_post('documentId');
    $reason = custodia_required_post('reason');
    $expiresInHoursRaw = trim((string) ($_POST['expiresInHours'] ?? ''));
    $expiresInHours = $expiresInHoursRaw !== '' ? max(1, (int) $expiresInHoursRaw) : null;
    return custodia_admin_grant_document_access($pdo, $user, $userId, $documentId, $reason, $expiresInHours, custodia_client_ip());
});
