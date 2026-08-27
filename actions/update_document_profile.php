<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/digital_documents.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $documentId = custodia_required_post('documentId');
    $fields = [
        'title' => trim((string) ($_POST['title'] ?? '')) ?: null,
        'description' => trim((string) ($_POST['description'] ?? '')) ?: null,
        'docType' => trim((string) ($_POST['docType'] ?? '')) ?: null,
        'confidentiality' => trim((string) ($_POST['confidentiality'] ?? '')) ?: null,
        'authorId' => trim((string) ($_POST['authorId'] ?? '')) ?: null,
        'linkedPhysicalFileId' => trim((string) ($_POST['linkedPhysicalFileId'] ?? '')),
    ];
    return custodia_update_document_profile($pdo, $user, $documentId, $fields, custodia_client_ip());
});
