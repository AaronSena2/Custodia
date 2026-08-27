<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/access_requests.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $documentId = custodia_required_post('documentId');
    $targetUserId = custodia_required_post('userId');
    $expiresInDaysRaw = trim((string) ($_POST['expiresInDays'] ?? ''));
    $expiresInHours = $expiresInDaysRaw !== '' ? max(1, (int) $expiresInDaysRaw) * 24 : null;
    return custodia_share_document_with_user($pdo, $user, $documentId, $targetUserId, $expiresInHours, custodia_client_ip());
});
