<?php
/**
 * Admin-editable key/value configuration, and the encryption used for the
 * secret ones.
 *
 * Why this exists alongside includes/config.php: config.php reads env vars,
 * which need a shell and a service restart to change. The Microsoft Graph
 * credentials have to be changeable by an administrator from inside the app
 * (Admin → Email Settings) — a client secret expires and gets rotated on
 * Microsoft's schedule, not ours, and "email silently stopped because the
 * secret expired" is a bad failure mode to need a developer for.
 *
 * SECRETS. A client secret in a database is a credential in every backup,
 * every phpMyAdmin session, and every CSV export. So a setting marked
 * is_secret is encrypted at rest with libsodium's authenticated
 * secretbox (XSalsa20-Poly1305, bundled with PHP since 7.2 — no extension
 * to install), under a key held OUTSIDE the database in the
 * CUSTODIA_SECRET_KEY environment variable, alongside the existing DB
 * password. Someone who reads the database alone gets ciphertext.
 *
 * Two rules the rest of the codebase must keep:
 *   1. A secret's plaintext is never rendered into a form field, returned
 *      by a JSON endpoint, or written into audit metadata. The admin UI
 *      shows "configured on <date>", never the value. custodia_setting_get()
 *      is only ever called by the mailer itself.
 *   2. app_settings must never be added to the CSV table exports written
 *      into backups/ by the import scripts — that would put the encrypted
 *      blob and everything else in a web-servable folder.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';

/** Every backend uses a 32-byte key, so the CUSTODIA_SECRET_KEY format is identical whichever one is active. */
const CUSTODIA_SECRET_KEY_BYTES = 32;

/**
 * Which cipher this install can actually use.
 *
 * PHP has bundled libsodium since 7.2, but "bundled" is not "enabled" —
 * on a stock Windows XAMPP install `extension=sodium` is commented out in
 * php.ini, and referencing SODIUM_* constants there is a fatal error, not
 * a graceful failure. That is exactly what happened on the live machine
 * (2026-09-10): the Email Settings tab died on an undefined
 * SODIUM_CRYPTO_SECRETBOX_KEYBYTES.
 *
 * So the cipher is chosen at runtime, and OpenSSL's AES-256-GCM is the
 * fallback: also authenticated (it detects tampering the same way
 * secretbox does), and openssl is enabled by default in every XAMPP build.
 * Returns null when neither is available, so callers can say so plainly
 * instead of crashing.
 *
 * CUSTODIA_CRYPTO_BACKEND forces one backend; it exists so the test suite
 * can exercise both paths on a machine that has both. Do not set it in
 * production.
 */
function custodia_crypto_backend(): ?string
{
    $forced = getenv('CUSTODIA_CRYPTO_BACKEND');
    if ($forced === 'sodium' || $forced === 'openssl') {
        return $forced;
    }
    if (function_exists('sodium_crypto_secretbox')) {
        return 'sodium';
    }
    if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)) {
        return 'openssl';
    }
    return null;
}

/**
 * Where the key file lives when the environment variable isn't used.
 *
 * Default is the PARENT of the application directory, deliberately: this
 * app is served with `php -S -t <app folder>`, which ignores .htaccess
 * entirely, so anything inside the app folder — storage/ included — is
 * web-readable. One level up is outside the document root and therefore
 * cannot be fetched over HTTP.
 */
function custodia_secret_key_file(): string
{
    $override = getenv('CUSTODIA_SECRET_KEY_FILE');
    if ($override !== false && $override !== '') {
        return $override;
    }

    // Where a previously generated key actually landed. The generator tries
    // several locations (see custodia_write_secret_key_file()), so the
    // chosen path has to be remembered or a later request would look in the
    // wrong place. A filesystem path is not itself a secret, so it lives in
    // app_settings like any other setting — read here with a direct query
    // rather than custodia_setting_get(), to keep this function free of any
    // dependency on the decryption it exists to support.
    static $stored = null;
    if ($stored === null) {
        $stored = false;
        try {
            $stmt = custodia_db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :k');
            $stmt->execute(['k' => CUSTODIA_SECRET_KEY_PATH_SETTING]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && $value !== '') {
                $stored = $value;
            }
        } catch (Throwable $e) {
            $stored = false; // no database yet, or the table isn't there — fall through
        }
    }
    if ($stored !== false) {
        return $stored;
    }

    return custodia_default_secret_key_paths()[0];
}

/** Setting key holding the resolved key-file path. */
const CUSTODIA_SECRET_KEY_PATH_SETTING = 'secret_key_path';

/**
 * Candidate locations for the key file, best first. All are OUTSIDE the
 * application directory on purpose: this app is served with
 * `php -S -t <app folder>`, which ignores .htaccess entirely, so anything
 * inside the app folder — storage/ included — is web-readable.
 *
 * @return string[]
 */
function custodia_default_secret_key_paths(): array
{
    $paths = [dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'custodia_secret.key'];

    // The user profile / home directory, as a fallback for a parent folder
    // that genuinely can't be written to.
    $home = getenv('USERPROFILE') ?: getenv('HOME');
    if (is_string($home) && $home !== '') {
        $paths[] = rtrim($home, '\\/') . DIRECTORY_SEPARATOR . 'custodia_secret.key';
    }

    return array_values(array_unique($paths));
}

/**
 * The 32-byte key, from CUSTODIA_SECRET_KEY if set, else from the key file.
 * Returns null when neither exists so callers degrade with a clear message
 * rather than a crash — the app is fully usable without email configured.
 *
 * The environment variable wins when both are present, and remains the
 * better option: a key file is readable by whatever account runs PHP, so on
 * a single box it protects against database-only exposure (a stolen backup,
 * a phpMyAdmin session, the CSV exports in backups/) rather than against
 * full host compromise. That is still the threat this is actually guarding.
 */
function custodia_secret_key(): ?string
{
    foreach (custodia_secret_key_candidates() as $candidate) {
        $key = base64_decode($candidate['value'], true);
        if ($key !== false && strlen($key) === CUSTODIA_SECRET_KEY_BYTES) {
            return $key;
        }
    }
    return null;
}

/** How the key is currently supplied — for the admin screen to report accurately. */
function custodia_secret_key_source(): ?string
{
    foreach (custodia_secret_key_candidates() as $candidate) {
        $key = base64_decode($candidate['value'], true);
        if ($key !== false && strlen($key) === CUSTODIA_SECRET_KEY_BYTES) {
            return $candidate['source'];
        }
    }
    return null;
}

/**
 * Every place a key might come from, in priority order.
 *
 * Both are tried rather than stopping at the first one that merely EXISTS:
 * an environment variable that is set but malformed — stray quotes are very
 * easy to introduce in the Windows environment-variable dialog, and
 * `CUSTODIA_SECRET_KEY=""` sets the value to two quote characters, not to
 * empty — would otherwise mask a perfectly good key file and report "no key
 * configured" while one sits on disk. Falling through means a broken
 * variable degrades to the file instead of taking the feature down.
 *
 * @return array{value:string,source:string}[]
 */
function custodia_secret_key_candidates(): array
{
    $candidates = [];

    $env = getenv('CUSTODIA_SECRET_KEY');
    if ($env !== false && trim($env) !== '') {
        $candidates[] = ['value' => trim($env), 'source' => 'environment'];
    }

    $file = custodia_secret_key_file();
    if (is_readable($file)) {
        $contents = trim((string) @file_get_contents($file));
        if ($contents !== '') {
            $candidates[] = ['value' => $contents, 'source' => 'file'];
        }
    }

    return $candidates;
}

/** Generates a key in the exact form CUSTODIA_SECRET_KEY expects. */
function custodia_generate_secret_key(): string
{
    return base64_encode(random_bytes(CUSTODIA_SECRET_KEY_BYTES));
}

/**
 * Writes a freshly generated key to the key file, so an administrator can
 * complete setup from the UI instead of needing shell access to set a
 * system environment variable on the server.
 *
 * Refuses to overwrite an existing key: doing so would make every secret
 * already stored under the old key permanently undecryptable, silently.
 */
function custodia_write_secret_key_file(?string $explicitPath = null): array
{
    $candidates = $explicitPath !== null && trim($explicitPath) !== ''
        ? [trim($explicitPath)]
        : custodia_default_secret_key_paths();

    foreach ($candidates as $file) {
        if (file_exists($file)) {
            throw custodia_bad_request('A key file already exists at ' . $file . '. It is not overwritten, because every secret stored under the old key would become unreadable.');
        }
    }

    $key = custodia_generate_secret_key();
    $attempts = [];

    foreach ($candidates as $file) {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            $attempts[] = $file . ' (no such folder)';
            continue;
        }

        // Deliberately NOT gated on is_writable(). On Windows that check is
        // unreliable for directories: Explorer sets the read-only ATTRIBUTE
        // on customised folders (Downloads among them) as a display marker,
        // and PHP reports such a folder as unwritable even when the account
        // running PHP can write to it perfectly well. Attempting the write
        // and reporting what actually happened is both simpler and correct.
        $written = @file_put_contents($file, $key . "\n", LOCK_EX);
        if ($written !== false) {
            @chmod($file, 0600);
            return ['path' => $file];
        }

        $error = error_get_last();
        $attempts[] = $file . ' (' . ($error['message'] ?? 'write failed') . ')';
    }

    throw custodia_bad_request(
        'Could not write the key file. Tried: ' . implode('; ', $attempts)
        . '. Enter a writable folder path below, or set the CUSTODIA_SECRET_KEY environment variable instead.'
    );
}

function custodia_encrypt_secret(string $plaintext): string
{
    $key = custodia_secret_key();
    if ($key === null) {
        throw custodia_bad_request(
            'No encryption key is configured, so secrets cannot be stored. Generate one from Admin → Email Settings, or set the CUSTODIA_SECRET_KEY environment variable, and try again.'
        );
    }
    $backend = custodia_crypto_backend();
    if ($backend === 'sodium') {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }
    if ($backend === 'openssl') {
        $iv = random_bytes(12); // 96-bit nonce, the size AES-GCM is defined for
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw custodia_bad_request('Encryption failed. Check that the OpenSSL extension is working on this server.');
        }
        return 'o1:' . base64_encode($iv . $tag . $cipher);
    }
    throw custodia_bad_request(
        'This PHP install has neither the sodium nor the OpenSSL extension enabled, so the client secret cannot be encrypted. Enable one of them in php.ini (sodium is preferred) and restart PHP.'
    );
}

/**
 * Returns null on any failure — a rotated or lost key, a tampered blob, or
 * a cipher this install can no longer perform — rather than throwing, so a
 * bad secret degrades to "email not configured" instead of a crash.
 *
 * The stored value carries a prefix naming the cipher it was written with,
 * so a machine that later gains (or loses) the sodium extension can still
 * read what the other backend wrote. An unprefixed value is treated as
 * sodium, which is what the first version of this file produced.
 */
function custodia_decrypt_secret(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }
    $key = custodia_secret_key();
    if ($key === null) {
        return null;
    }

    $backend = 'sodium';
    if (str_starts_with($stored, 's1:')) {
        $stored = substr($stored, 3);
    } elseif (str_starts_with($stored, 'o1:')) {
        $backend = 'openssl';
        $stored = substr($stored, 3);
    }

    $raw = base64_decode($stored, true);
    if ($raw === false) {
        return null;
    }

    if ($backend === 'sodium') {
        if (!function_exists('sodium_crypto_secretbox_open') || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        return $plain === false ? null : $plain;
    }

    if (!function_exists('openssl_decrypt') || strlen($raw) <= 28) { // 12-byte IV + 16-byte tag
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

/** Decrypted value for a secret setting, plain value otherwise. Null when unset. */
function custodia_setting_get(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT setting_value, is_secret FROM app_settings WHERE setting_key = :k');
    $stmt->execute(['k' => $key]);
    $row = $stmt->fetch();
    if (!$row || $row['setting_value'] === null) {
        return null;
    }
    return $row['is_secret'] ? custodia_decrypt_secret($row['setting_value']) : $row['setting_value'];
}

/** Metadata only — whether a setting has a value and when it was last written. Safe to expose in the UI for a secret. */
function custodia_setting_meta(PDO $pdo, string $key): array
{
    $stmt = $pdo->prepare('SELECT setting_value IS NOT NULL AND setting_value <> "" AS is_set, updated_at, updated_by_id FROM app_settings WHERE setting_key = :k');
    $stmt->execute(['k' => $key]);
    $row = $stmt->fetch();
    return [
        'isSet' => (bool) ($row['is_set'] ?? false),
        'updatedAt' => $row['updated_at'] ?? null,
        'updatedById' => $row['updated_by_id'] ?? null,
    ];
}

function custodia_setting_set(PDO $pdo, string $key, ?string $value, bool $isSecret, ?string $actorId): void
{
    $stored = ($isSecret && $value !== null && $value !== '') ? custodia_encrypt_secret($value) : $value;
    $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, is_secret, updated_by_id)
         VALUES (:k, :v, :s, :by)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret), updated_by_id = VALUES(updated_by_id)'
    )->execute(['k' => $key, 'v' => $stored, 's' => $isSecret ? 1 : 0, 'by' => $actorId]);
}

/**
 * Whether the email tables from upgrade_022 are actually present.
 *
 * custodia_notify_user() calls into the mailer on every notification, and
 * the PHP files may well land on the live machine before the migration is
 * run (that has genuinely happened twice on this install — see the
 * 2026-09-04 production outage over upgrade_020/021). A missing table must
 * degrade to "no email", never break a custody transfer, so this is checked
 * once per request and cached.
 */
function custodia_email_tables_present(PDO $pdo): bool
{
    static $present = null;
    if ($present === null) {
        try {
            $pdo->query('SELECT 1 FROM email_outbox LIMIT 1');
            $pdo->query('SELECT 1 FROM app_settings LIMIT 1');
            $present = true;
        } catch (Throwable $e) {
            $present = false;
        }
    }
    return $present;
}
