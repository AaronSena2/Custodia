<?php
/**
 * Outbound email via the Microsoft Graph API.
 *
 * Transport choice: Graph rather than SMTP, per the firm's decision
 * (2026-09-09). It needs no third-party library at all — an OAuth2
 * client-credentials token plus an HTTPS POST with a JSON body, both of
 * which cURL and json_encode already do — so the app keeps the
 * dependency-free property its README promises (no Composer, no npm, no
 * build step). It also avoids Microsoft 365's ongoing retirement of basic
 * SMTP AUTH.
 *
 * Auth is app-only (client credentials), so there is no signed-in user and
 * no refresh token: the app registration holds the Mail.Send APPLICATION
 * permission with admin consent, and sends as one fixed mailbox.
 *
 * ⚠ Mail.Send as an application permission is TENANT-WIDE by default — it
 * would let this app send as any mailbox in the firm, including a partner's.
 * The Azure app registration MUST be restricted to the single sending
 * mailbox with an Exchange ApplicationAccessPolicy. See README's "Email
 * notifications" section for the exact New-ApplicationAccessPolicy command.
 *
 * Nothing here ever sends inline from a request. custodia_mail_enqueue()
 * writes a row inside the caller's existing transaction; jobs/send_email_queue.php
 * does the talking to Microsoft. See that file for why.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/app_settings.php';

// Setting keys. Kept together so the admin screen, the mailer and the queue
// job can never disagree about a name.
const CUSTODIA_MAIL_SETTING_KEYS = [
    'enabled'        => 'mail_enabled',
    'tenantId'       => 'mail_graph_tenant_id',
    'clientId'       => 'mail_graph_client_id',
    'clientSecret'   => 'mail_graph_client_secret',   // secret
    'senderAddress'  => 'mail_sender_address',
    'senderName'     => 'mail_sender_name',
    'secretExpires'  => 'mail_secret_expires_on',
    'baseUrl'        => 'mail_base_url',
    'redirectTo'     => 'mail_redirect_to',
    'maxPerHour'     => 'mail_max_per_user_per_hour',
    'tokenCache'     => 'mail_graph_token_cache',     // secret
    'tokenExpiresAt' => 'mail_graph_token_expires_at',
];

/** Warn this many days ahead of the client secret's expiry date. */
const CUSTODIA_MAIL_SECRET_WARN_DAYS = 30;

/** Default cap on emails to one person per hour; the rest roll into a digest. */
const CUSTODIA_MAIL_DEFAULT_MAX_PER_HOUR = 20;

/**
 * Endpoint bases, overridable by env var purely so the test suite can point
 * them at a local stub of Microsoft's two endpoints and exercise the real
 * token/send code paths end to end. Never set these in production.
 */
function custodia_graph_login_base(): string
{
    return rtrim(custodia_env('CUSTODIA_GRAPH_LOGIN_BASE', 'https://login.microsoftonline.com'), '/');
}

function custodia_graph_api_base(): string
{
    return rtrim(custodia_env('CUSTODIA_GRAPH_API_BASE', 'https://graph.microsoft.com/v1.0'), '/');
}

/** @return array{enabled:bool,tenantId:?string,clientId:?string,senderAddress:?string,senderName:string,baseUrl:?string,redirectTo:?string,maxPerHour:int,secretExpiresOn:?string,secretIsSet:bool,secretMeta:array} */
function custodia_mail_settings(PDO $pdo): array
{
    if (!custodia_email_tables_present($pdo)) {
        return ['enabled' => false, 'tenantId' => null, 'clientId' => null, 'senderAddress' => null,
                'senderName' => 'Custodia Registry', 'baseUrl' => null, 'redirectTo' => null,
                'maxPerHour' => CUSTODIA_MAIL_DEFAULT_MAX_PER_HOUR, 'secretExpiresOn' => null,
                'secretIsSet' => false, 'secretMeta' => ['isSet' => false, 'updatedAt' => null]];
    }
    $k = CUSTODIA_MAIL_SETTING_KEYS;
    $secretMeta = custodia_setting_meta($pdo, $k['clientSecret']);
    $maxPerHour = (int) (custodia_setting_get($pdo, $k['maxPerHour']) ?? CUSTODIA_MAIL_DEFAULT_MAX_PER_HOUR);

    return [
        'enabled' => custodia_setting_get($pdo, $k['enabled']) === '1',
        'tenantId' => custodia_setting_get($pdo, $k['tenantId']),
        'clientId' => custodia_setting_get($pdo, $k['clientId']),
        'senderAddress' => custodia_setting_get($pdo, $k['senderAddress']),
        'senderName' => custodia_setting_get($pdo, $k['senderName']) ?: 'Custodia Registry',
        'baseUrl' => custodia_setting_get($pdo, $k['baseUrl']),
        'redirectTo' => custodia_setting_get($pdo, $k['redirectTo']),
        'maxPerHour' => $maxPerHour > 0 ? $maxPerHour : CUSTODIA_MAIL_DEFAULT_MAX_PER_HOUR,
        'secretExpiresOn' => custodia_setting_get($pdo, $k['secretExpires']),
        'secretIsSet' => $secretMeta['isSet'],
        'secretMeta' => $secretMeta,
    ];
}

/** True when every field needed to actually send is present — separate from the on/off toggle. */
function custodia_mail_is_configured(array $settings): bool
{
    return !empty($settings['tenantId']) && !empty($settings['clientId'])
        && !empty($settings['senderAddress']) && $settings['secretIsSet'];
}

/** Days until the stored client-secret expiry date; null if no date recorded, negative if already expired. */
function custodia_mail_secret_days_remaining(array $settings): ?int
{
    if (empty($settings['secretExpiresOn'])) {
        return null;
    }
    try {
        $expiry = new DateTimeImmutable($settings['secretExpiresOn']);
    } catch (Throwable $e) {
        return null;
    }
    $today = new DateTimeImmutable('today');
    $diff = $today->diff($expiry);
    return (int) ($diff->invert ? -$diff->days : $diff->days);
}

// ─── Token ──────────────────────────────────────────────────────────

/**
 * An app-only access token, cached until shortly before it expires.
 *
 * Microsoft issues these for about an hour. Fetching one per email would be
 * both slow and a good way to get throttled, so the token is cached in
 * app_settings — encrypted, because a bearer token is a credential in its
 * own right — with a 5-minute safety margin on the expiry.
 *
 * @return array{token:?string,error:?string}
 */
function custodia_graph_access_token(PDO $pdo, array $settings, bool $forceRefresh = false): array
{
    $k = CUSTODIA_MAIL_SETTING_KEYS;

    if (!$forceRefresh) {
        $cached = custodia_setting_get($pdo, $k['tokenCache']);
        $expiresAt = custodia_setting_get($pdo, $k['tokenExpiresAt']);
        if ($cached && $expiresAt && (int) $expiresAt > time() + 300) {
            return ['token' => $cached, 'error' => null];
        }
    }

    $secret = custodia_setting_get($pdo, $k['clientSecret']);
    if ($secret === null || $secret === '') {
        return ['token' => null, 'error' => 'No client secret is stored, or it could not be decrypted (has CUSTODIA_SECRET_KEY changed?).'];
    }

    $url = custodia_graph_login_base() . '/' . rawurlencode((string) $settings['tenantId']) . '/oauth2/v2.0/token';
    $body = http_build_query([
        'client_id' => $settings['clientId'],
        'client_secret' => $secret,
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
    ]);

    $response = custodia_graph_http_post($url, $body, ['Content-Type: application/x-www-form-urlencoded']);
    if ($response['error'] !== null) {
        return ['token' => null, 'error' => 'Token request failed: ' . $response['error']];
    }

    $json = json_decode($response['body'], true);
    if ($response['status'] !== 200 || !is_array($json) || empty($json['access_token'])) {
        // Microsoft's error body names the actual problem (expired secret,
        // wrong tenant, consent not granted) — surface it, since a generic
        // "token failed" would send an admin hunting blind.
        $detail = is_array($json) ? ($json['error_description'] ?? $json['error'] ?? $response['body']) : $response['body'];
        return ['token' => null, 'error' => 'Token request rejected (HTTP ' . $response['status'] . '): ' . custodia_mail_truncate((string) $detail, 500)];
    }

    $expiresIn = (int) ($json['expires_in'] ?? 3600);
    custodia_setting_set($pdo, $k['tokenCache'], $json['access_token'], true, null);
    custodia_setting_set($pdo, $k['tokenExpiresAt'], (string) (time() + $expiresIn), false, null);

    return ['token' => $json['access_token'], 'error' => null];
}

// ─── Send ───────────────────────────────────────────────────────────

/**
 * Sends one message through Graph. Called only by jobs/send_email_queue.php
 * and by the admin screen's "Send test email" button — never from a request
 * that holds a database transaction open.
 *
 * saveToSentItems is false by design: email_outbox is already the durable
 * record of what went out, and a Sent Items folder accumulating thousands
 * of automated notices helps nobody.
 *
 * @return array{ok:bool,error:?string,retryable:bool}
 */
function custodia_graph_send_mail(PDO $pdo, array $settings, string $toEmail, string $subject, string $bodyText, ?string $bodyHtml = null): array
{
    $tokenResult = custodia_graph_access_token($pdo, $settings);
    if ($tokenResult['token'] === null) {
        return ['ok' => false, 'error' => $tokenResult['error'], 'retryable' => true];
    }

    $payload = [
        'message' => [
            'subject' => $subject,
            'body' => $bodyHtml !== null
                ? ['contentType' => 'HTML', 'content' => $bodyHtml]
                : ['contentType' => 'Text', 'content' => $bodyText],
            'toRecipients' => [['emailAddress' => ['address' => $toEmail]]],
        ],
        'saveToSentItems' => false,
    ];

    $url = custodia_graph_api_base() . '/users/' . rawurlencode((string) $settings['senderAddress']) . '/sendMail';
    $response = custodia_graph_http_post($url, json_encode($payload), [
        'Authorization: Bearer ' . $tokenResult['token'],
        'Content-Type: application/json',
    ]);

    if ($response['error'] !== null) {
        return ['ok' => false, 'error' => $response['error'], 'retryable' => true];
    }

    // Graph answers 202 Accepted on success, with an empty body.
    if ($response['status'] === 202) {
        return ['ok' => true, 'error' => null, 'retryable' => false];
    }

    // A 401 usually means the cached token went stale early — retry once
    // with a forced refresh before treating it as a real failure.
    if ($response['status'] === 401) {
        $retryToken = custodia_graph_access_token($pdo, $settings, true);
        if ($retryToken['token'] !== null) {
            $retry = custodia_graph_http_post($url, json_encode($payload), [
                'Authorization: Bearer ' . $retryToken['token'],
                'Content-Type: application/json',
            ]);
            if ($retry['error'] === null && $retry['status'] === 202) {
                return ['ok' => true, 'error' => null, 'retryable' => false];
            }
        }
    }

    $json = json_decode($response['body'], true);
    $detail = is_array($json) ? ($json['error']['message'] ?? $response['body']) : $response['body'];
    // 4xx other than 429 is a request problem (bad address, no access policy,
    // permission not consented) — retrying it just burns quota.
    $retryable = $response['status'] === 429 || $response['status'] >= 500 || $response['status'] === 401;
    return [
        'ok' => false,
        'error' => 'Graph rejected the send (HTTP ' . $response['status'] . '): ' . custodia_mail_truncate((string) $detail, 500),
        'retryable' => $retryable,
    ];
}

/** @return array{status:int,body:string,error:?string} */
function custodia_graph_http_post(string $url, string $body, array $headers): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $responseBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        return ['status' => 0, 'body' => '', 'error' => $curlError !== '' ? $curlError : 'Request failed'];
    }
    return ['status' => $status, 'body' => (string) $responseBody, 'error' => null];
}

function custodia_mail_truncate(string $text, int $max): string
{
    return strlen($text) > $max ? substr($text, 0, $max - 1) . '…' : $text;
}

// ─── Deliverability ─────────────────────────────────────────────────

/**
 * Address domains that must never be mailed.
 *
 * This is not theoretical tidiness. The 2026-09-05 imports assigned
 * effectively every one of the ~4,273 matters to a placeholder managing
 * partner at unassigned-partner-v2@placeholder.local, plus an older
 * placeholder from the original bulk import. Any rule that emails "the
 * matter's incharge" would otherwise fire at those addresses on nearly
 * every matter in the system, producing a flood of hard bounces that would
 * damage the sending mailbox's reputation.
 *
 * This is a guard, not a fix — the placeholder partners still want cleaning
 * up. Recipient resolution falls through to Records Managers when the
 * incharge address is undeliverable; see custodia_custody_approver_ids().
 */
// Only names that genuinely cannot receive external mail: the RFC 2606
// reserved names, plus .local (mDNS) and .internal (private use). Real
// delegated gTLDs are deliberately NOT listed — an earlier draft included
// .demo, which is a real gTLD, and would have silently refused to mail a
// legitimate address.
const CUSTODIA_UNDELIVERABLE_DOMAIN_SUFFIXES = ['.local', '.localhost', '.invalid', '.test', '.example', '.internal'];

function custodia_mail_is_deliverable(?string $email): bool
{
    if ($email === null || trim($email) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    if ($domain === '' || str_contains($domain, 'placeholder')) {
        return false;
    }
    foreach (CUSTODIA_UNDELIVERABLE_DOMAIN_SUFFIXES as $suffix) {
        if (str_ends_with($domain, $suffix)) {
            return false;
        }
    }
    return true;
}
