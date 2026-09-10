<?php
/**
 * Creates the encryption key that protects the stored Microsoft client
 * secret, so an administrator can finish email setup from Admin → Email
 * Settings without needing shell access to set a system environment
 * variable on the server.
 *
 * Writes to a file OUTSIDE the web folder (see custodia_secret_key_file()),
 * and refuses to overwrite an existing key — doing so would silently make
 * every secret stored under the old key undecryptable.
 *
 * The key itself is never returned to the browser: the response carries
 * only the path it was written to. There is nothing the admin needs to
 * copy, and nothing for a proxy or browser history to capture.
 */
require __DIR__ . '/../includes/action_bootstrap.php';
require __DIR__ . '/../includes/email_settings_admin.php';

custodia_run_action(function (PDO $pdo, array $user) {
    custodia_assert_manage_email_settings($pdo, $user);

    if (custodia_crypto_backend() === null) {
        throw custodia_bad_request('This PHP install has neither the sodium nor the OpenSSL extension enabled, so secrets cannot be encrypted. Enable one in php.ini and restart PHP.');
    }
    if (custodia_secret_key() !== null) {
        throw custodia_bad_request('An encryption key is already configured.');
    }

    // An optional explicit folder or file path, for an install where neither
    // default location can be written to.
    $explicit = trim((string) ($_POST['keyPath'] ?? ''));
    if ($explicit !== '' && (is_dir($explicit) || str_ends_with(rtrim($explicit, '\\/'), DIRECTORY_SEPARATOR))) {
        $explicit = rtrim($explicit, '\\/') . DIRECTORY_SEPARATOR . 'custodia_secret.key';
    }

    $result = custodia_write_secret_key_file($explicit !== '' ? $explicit : null);

    // Remember where it landed, so later requests and the scheduled queue job
    // look in the same place. The path is not a secret.
    custodia_setting_set($pdo, CUSTODIA_SECRET_KEY_PATH_SETTING, $result['path'], false, $user['id']);

    $pdo->beginTransaction();
    try {
        // The key is never recorded — only the fact that one was created,
        // and where. That is what an auditor needs to know.
        custodia_audit_record($pdo, [
            'actorId' => $user['id'],
            'actionType' => 'EMAIL_SECRET_KEY_CREATED',
            'entityType' => 'APP_SETTING',
            'entityId' => 'email',
            'ipAddress' => custodia_client_ip(),
            'metadata' => ['path' => $result['path'], 'backend' => custodia_crypto_backend()],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $result;
});
