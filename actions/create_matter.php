<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/matters.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $dto = [
        'matterNumber' => custodia_required_post('matterNumber'),
        'clientId' => custodia_required_post('clientId'),
        'practiceArea' => custodia_required_post('practiceArea'),
        'managingPartnerId' => custodia_required_post('managingPartnerId'),
        'confidentiality' => $_POST['confidentiality'] ?? 'STANDARD',
    ];
    return custodia_create_matter($pdo, $user, $dto, custodia_client_ip());
});
