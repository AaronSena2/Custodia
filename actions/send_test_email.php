<?php
/**
 * Sends a one-off test message through Microsoft Graph so an admin can
 * confirm the credentials work without waiting for a real notification.
 * The only inline send in the app — see custodia_send_test_email().
 */
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/email_settings_admin.php';

custodia_run_action(function (PDO $pdo, array $user) {
    return custodia_send_test_email($pdo, $user, custodia_required_post('toEmail'), custodia_client_ip());
});
