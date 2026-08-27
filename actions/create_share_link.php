<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/digital_documents.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $documentId = custodia_required_post('documentId');
    $permission = $_POST['permission'] ?? 'VIEW';
    $recipientEmail = trim((string) ($_POST['recipientEmail'] ?? '')) ?: null;
    $expiresInHours = max(1, (int) ($_POST['expiresInHours'] ?? 72));
    return custodia_create_share_link($pdo, $user, $documentId, $permission, $recipientEmail, $expiresInHours, custodia_client_ip());
});
