<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/matters.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $matterId = custodia_required_post('matterId');
    return custodia_update_matter($pdo, $user, $matterId, [
        'matterNumber' => custodia_required_post('matterNumber'),
        'clientId' => custodia_required_post('clientId'),
        'practiceArea' => custodia_required_post('practiceArea'),
        'managingPartnerId' => custodia_required_post('managingPartnerId'),
        'status' => custodia_required_post('status'),
        'confidentiality' => custodia_required_post('confidentiality'),
        'closeDate' => $_POST['closeDate'] ?? '',
    ], custodia_client_ip());
});
