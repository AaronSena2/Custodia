<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/digital_documents.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $documentId = custodia_required_post('documentId');
    return custodia_release_document_checkout($pdo, $user, $documentId, custodia_client_ip());
});
