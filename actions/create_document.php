<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/digital_documents.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $matterId = custodia_required_post('matterId');
    $title = custodia_required_post('title');
    $docType = custodia_required_post('docType');
    $confidentiality = $_POST['confidentiality'] ?? 'STANDARD';
    $linkedPhysicalFileId = trim((string) ($_POST['linkedPhysicalFileId'] ?? '')) ?: null;
    $description = trim((string) ($_POST['description'] ?? '')) ?: null;
    return custodia_create_document($pdo, $user, $matterId, $title, $docType, $confidentiality, $linkedPhysicalFileId, $description, custodia_client_ip());
});
