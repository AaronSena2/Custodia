<?php
/**
 * Saves Admin → Email Settings. See
 * includes/email_settings_admin.php's custodia_save_email_settings() —
 * in particular, a blank clientSecret means "keep the stored one", so the
 * secret is never round-tripped through the browser.
 */
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/email_settings_admin.php';

custodia_run_action(function (PDO $pdo, array $user) {
    return custodia_save_email_settings($pdo, $user, [
        'tenantId' => $_POST['tenantId'] ?? '',
        'clientId' => $_POST['clientId'] ?? '',
        'clientSecret' => $_POST['clientSecret'] ?? '',
        'senderAddress' => $_POST['senderAddress'] ?? '',
        'senderName' => $_POST['senderName'] ?? '',
        'secretExpiresOn' => $_POST['secretExpiresOn'] ?? '',
        'baseUrl' => $_POST['baseUrl'] ?? '',
        'redirectTo' => $_POST['redirectTo'] ?? '',
        'maxPerHour' => $_POST['maxPerHour'] ?? null,
        'enabled' => !empty($_POST['enabled']),
    ], custodia_client_ip());
});
