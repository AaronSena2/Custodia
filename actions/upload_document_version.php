<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/digital_documents.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $documentId = custodia_required_post('documentId');
    if (empty($_FILES['file'])) {
        throw custodia_bad_request('No file was uploaded.');
    }
    // PHP itself rejects an over-limit upload before app code ever sees the
    // file — this is the most likely failure for a video, so it gets its own
    // message pointing at the actual php.ini knobs, instead of a flat
    // "upload failed" that gives no indication of what to change.
    if (in_array($_FILES['file']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw custodia_bad_request(
            'This file is larger than the server currently allows. Ask an administrator to raise '
            . 'upload_max_filesize/post_max_size in php.ini (see README) for larger audio/video uploads.'
        );
    }
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw custodia_bad_request('The upload failed (error code ' . $_FILES['file']['error'] . '). Please try again.');
    }
    return custodia_upload_document_version($pdo, $user, $documentId, $_FILES['file'], custodia_client_ip());
});
