<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/digital_documents.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $documentId = custodia_required_post('documentId');
    $protected = ($_POST['protected'] ?? '0') === '1';
    return custodia_set_document_protected($pdo, $user, $documentId, $protected, custodia_client_ip());
});
