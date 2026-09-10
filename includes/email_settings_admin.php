<?php
/**
 * Write path behind Admin → Email Settings, plus the test send.
 *
 * Separated from includes/graph_mailer.php (which is the transport, used by
 * the queue job) so the admin-facing validation, permission gate and audit
 * trail live together and the mailer stays a thin, testable layer.
 *
 * The client secret is write-only from this screen's point of view: it is
 * accepted, encrypted and stored, and never read back out. A submit that
 * leaves the secret field blank keeps whatever is already stored, so an
 * admin can change the sender address or the expiry date without having to
 * re-paste the credential (and without the screen needing to know it).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/app_settings.php';
require_once __DIR__ . '/graph_mailer.php';

function custodia_assert_manage_email_settings(PDO $pdo, array $actor): void
{
    custodia_assert_permission($pdo, $actor, 'manage_email_settings');
}

/**
 * @param array{tenantId:string,clientId:string,clientSecret:?string,senderAddress:string,
 *              senderName:?string,secretExpiresOn:?string,baseUrl:?string,redirectTo:?string,
 *              maxPerHour:?int,enabled:bool} $fields
 */
function custodia_save_email_settings(PDO $pdo, array $actor, array $fields, string $ipAddress): array
{
    custodia_assert_manage_email_settings($pdo, $actor);

    $tenantId = trim((string) ($fields['tenantId'] ?? ''));
    $clientId = trim((string) ($fields['clientId'] ?? ''));
    $sender = trim((string) ($fields['senderAddress'] ?? ''));
    $secret = (string) ($fields['clientSecret'] ?? '');
    $enabled = !empty($fields['enabled']);

    if ($tenantId === '' || $clientId === '') {
        throw custodia_bad_request('Directory (tenant) ID and Application (client) ID are both required.');
    }
    if (!filter_var($sender, FILTER_VALIDATE_EMAIL)) {
        throw custodia_bad_request('The sending mailbox must be a valid email address.');
    }

    $existing = custodia_mail_settings($pdo);
    $secretProvided = $secret !== '';

    // Refuse to switch email on while it can't actually send — otherwise the
    // queue fills with rows that will only ever fail, and the admin gets no
    // signal until someone notices missing mail.
    if ($enabled && !$secretProvided && !$existing['secretIsSet']) {
        throw custodia_bad_request('Add the client secret before switching email notifications on.');
    }
    if ($secretProvided && custodia_secret_key() === null) {
        throw custodia_bad_request(
            'The client secret cannot be stored because no encryption key is configured. Set the CUSTODIA_SECRET_KEY environment variable on the server first — see the setup note on this screen.'
        );
    }

    $expiresOn = trim((string) ($fields['secretExpiresOn'] ?? ''));
    if ($expiresOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresOn)) {
        throw custodia_bad_request('The secret expiry date must be in YYYY-MM-DD form.');
    }

    $baseUrl = trim((string) ($fields['baseUrl'] ?? ''));
    if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
        throw custodia_bad_request('The application URL must start with http:// or https://.');
    }

    $redirectTo = trim((string) ($fields['redirectTo'] ?? ''));
    if ($redirectTo !== '' && !filter_var($redirectTo, FILTER_VALIDATE_EMAIL)) {
        throw custodia_bad_request('The test redirect address must be a valid email address, or blank.');
    }

    $maxPerHour = (int) ($fields['maxPerHour'] ?? CUSTODIA_MAIL_DEFAULT_MAX_PER_HOUR);
    if ($maxPerHour < 1 || $maxPerHour > 500) {
        throw custodia_bad_request('The hourly email limit must be between 1 and 500.');
    }

    $k = CUSTODIA_MAIL_SETTING_KEYS;

    $pdo->beginTransaction();
    try {
        custodia_setting_set($pdo, $k['tenantId'], $tenantId, false, $actor['id']);
        custodia_setting_set($pdo, $k['clientId'], $clientId, false, $actor['id']);
        custodia_setting_set($pdo, $k['senderAddress'], $sender, false, $actor['id']);
        custodia_setting_set($pdo, $k['senderName'], trim((string) ($fields['senderName'] ?? '')) ?: 'Custodia Registry', false, $actor['id']);
        custodia_setting_set($pdo, $k['secretExpires'], $expiresOn !== '' ? $expiresOn : null, false, $actor['id']);
        custodia_setting_set($pdo, $k['baseUrl'], $baseUrl !== '' ? $baseUrl : null, false, $actor['id']);
        custodia_setting_set($pdo, $k['redirectTo'], $redirectTo !== '' ? $redirectTo : null, false, $actor['id']);
        custodia_setting_set($pdo, $k['maxPerHour'], (string) $maxPerHour, false, $actor['id']);
        custodia_setting_set($pdo, $k['enabled'], $enabled ? '1' : '0', false, $actor['id']);

        if ($secretProvided) {
            custodia_setting_set($pdo, $k['clientSecret'], $secret, true, $actor['id']);
            // A new secret invalidates any cached access token minted from
            // the old one — otherwise the next few sends would keep using a
            // token the admin thinks they have just rotated away from.
            custodia_setting_set($pdo, $k['tokenCache'], null, true, $actor['id']);
            custodia_setting_set($pdo, $k['tokenExpiresAt'], null, false, $actor['id']);
        }

        // The secret itself never appears here. Recording only WHETHER it
        // changed keeps the audit trail useful without turning the audit log
        // into a place credentials leak from.
        custodia_audit_record($pdo, [
            'actorId' => $actor['id'],
            'actionType' => 'EMAIL_SETTINGS_UPDATED',
            'entityType' => 'APP_SETTING',
            'entityId' => 'email',
            'ipAddress' => $ipAddress,
            'metadata' => [
                'enabled' => $enabled,
                'tenantId' => $tenantId,
                'clientId' => $clientId,
                'senderAddress' => $sender,
                'secretRotated' => $secretProvided,
                'secretExpiresOn' => $expiresOn !== '' ? $expiresOn : null,
                'redirectActive' => $redirectTo !== '',
            ],
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['enabled' => $enabled, 'secretRotated' => $secretProvided];
}

/**
 * Sends one message immediately, straight through Graph, bypassing the
 * outbox. This is the only place in the app that sends inline — deliberately,
 * because the whole point is to tell the admin right now whether the
 * credentials work, and it holds no transaction open while doing so.
 */
function custodia_send_test_email(PDO $pdo, array $actor, string $toEmail, string $ipAddress): array
{
    custodia_assert_manage_email_settings($pdo, $actor);

    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw custodia_bad_request('Enter a valid email address to send the test to.');
    }

    $settings = custodia_mail_settings($pdo);
    if (!custodia_mail_is_configured($settings)) {
        throw custodia_bad_request('Fill in the tenant ID, client ID, client secret and sending mailbox first, and save.');
    }

    $text = "This is a test message from Custodia.\n\n"
        . "If you are reading it, the Microsoft Graph credentials are working and the app can send mail as "
        . $settings['senderAddress'] . ".\n\n"
        . 'Sent by ' . $actor['full_name'] . ' at ' . date('Y-m-d H:i') . ".\n";
    $html = '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;color:#1f2937;line-height:1.5">'
        . '<p>This is a test message from Custodia.</p>'
        . '<p>If you are reading it, the Microsoft Graph credentials are working and the app can send mail as <strong>'
        . e($settings['senderAddress']) . '</strong>.</p>'
        . '<p style="color:#6b7280">Sent by ' . e($actor['full_name']) . ' at ' . e(date('Y-m-d H:i')) . '.</p></div>';

    $result = custodia_graph_send_mail($pdo, $settings, $toEmail, 'Custodia test message', $text, $html);

    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $actor['id'],
            'actionType' => 'EMAIL_TEST_SENT',
            'entityType' => 'APP_SETTING',
            'entityId' => 'email',
            'ipAddress' => $ipAddress,
            'metadata' => ['to' => $toEmail, 'ok' => $result['ok'], 'error' => $result['error']],
            'chained' => false,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
    }

    if (!$result['ok']) {
        // Surfaced verbatim: Microsoft's own message names the actual
        // problem (expired secret, consent not granted, no application
        // access policy for this mailbox), and hiding it behind a generic
        // failure would send the admin hunting blind.
        throw custodia_bad_request('Test send failed. ' . $result['error']);
    }

    return ['sent' => true, 'to' => $toEmail];
}

/** Recent outbox rows for the admin screen's activity panel. */
function custodia_list_recent_outbox(PDO $pdo, int $limit = 15): array
{
    $stmt = $pdo->prepare(
        'SELECT o.*, u.full_name AS recipient_name FROM email_outbox o
         LEFT JOIN users u ON u.id = o.user_id
         ORDER BY o.created_at DESC LIMIT :lim'
    );
    $stmt->bindValue('lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** @return array{pending:int,sent:int,failed:int,skipped:int} */
function custodia_email_outbox_counts(PDO $pdo): array
{
    $rows = $pdo->query('SELECT status, COUNT(*) AS n FROM email_outbox GROUP BY status')->fetchAll();
    $counts = ['PENDING' => 0, 'SENT' => 0, 'FAILED' => 0, 'SKIPPED' => 0];
    foreach ($rows as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
    return ['pending' => $counts['PENDING'], 'sent' => $counts['SENT'], 'failed' => $counts['FAILED'], 'skipped' => $counts['SKIPPED']];
}
