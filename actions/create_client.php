<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/clients.php';

custodia_run_action(function (PDO $pdo, array $user) {
    // "New Client" modal's optional "Also add a matter" section — the
    // checkbox's own name/value only reaches $_POST at all when checked, so
    // its presence is the flag for whether a matter was actually requested.
    $matterFields = null;
    if (($_POST['addMatter'] ?? '') === '1') {
        $matterFields = [
            'matterNumber' => custodia_required_post('matterNumber'),
            'practiceArea' => custodia_required_post('practiceArea'),
            'managingPartnerId' => custodia_required_post('managingPartnerId'),
            'confidentiality' => $_POST['confidentiality'] ?? 'STANDARD',
        ];
    }

    return custodia_create_client_with_matter($pdo, $user, [
        'name' => custodia_required_post('name'),
        'email' => $_POST['email'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'address' => $_POST['address'] ?? '',
    ], $matterFields, custodia_client_ip());
});
