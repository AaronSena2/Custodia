<?php
/**
 * Demo local-auth (session + password_hash/password_verify), mirroring the
 * Node version's local JWT auth — same swap-in point for real SSO/OIDC
 * later: replace custodia_login()'s password check with a token-verification
 * call against the firm's IdP (Azure AD / Okta / Keycloak), and populate the
 * session the same way afterwards.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/practice_groups.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/notification_recipients.php';

function custodia_start_session(): void
{
    // Security review 2026-09-03, finding 1.5: this is the one chokepoint
    // every PHP entry point in the app passes through before any output —
    // see includes/security_headers.php's own comment for the full map.
    custodia_send_security_headers();

    if (session_status() === PHP_SESSION_NONE) {
        session_name(custodia_config()['session_name']);
        session_start();
    }
}

/** Returns the full current user row (without password_hash) or null if not logged in. */
function custodia_current_user(): ?array
{
    static $cached = null;
    static $resolved = false;
    if ($resolved) {
        return $cached;
    }
    $resolved = true;

    custodia_start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    // Idle-session lockout — security review 2026-09-03, finding 1.6: a
    // signed-in session left unattended (shared workstation, unlocked
    // screen) stayed valid indefinitely. Any session with no recorded
    // activity for longer than the configured idle window is treated as
    // logged out; custodia_require_login() then bounces to login.php,
    // which shows the 'login_notice' left behind here.
    $idleLimitSeconds = custodia_config()['security']['idle_timeout_minutes'] * 60;
    $lastActivity = $_SESSION['last_activity'] ?? null;
    if ($lastActivity !== null && (time() - (int) $lastActivity) > $idleLimitSeconds) {
        $_SESSION = [];
        session_destroy();
        custodia_start_session();
        session_regenerate_id(true);
        $_SESSION['login_notice'] = 'You were signed out after a period of inactivity. Please sign in again.';
        return null;
    }
    $_SESSION['last_activity'] = time();

    $stmt = custodia_db()->prepare('SELECT * FROM users WHERE id = :id AND is_active = 1');
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    unset($row['password_hash']);
    $row['practice_group_names'] = custodia_list_user_group_names(custodia_db(), $row['id']);
    $cached = $row;
    return $cached;
}

/**
 * Attempts login; returns the user row on success, or null on bad
 * credentials/inactive/locked account.
 *
 * Account lockout — security review 2026-09-03, finding 1.6: with a
 * previously-published shared admin password (finding 1.1) and no
 * throttling, credential attacks against this form were free.
 * custodia_config()['security'] controls the failure threshold and
 * lockout duration. A locked account fails closed here (password is never
 * even checked) so the lock can't be raced by a fast guesser; the login
 * page uses custodia_account_lock_remaining_minutes() separately to show
 * a distinct "temporarily locked" message.
 */
function custodia_attempt_login(string $email, string $password): ?array
{
    $pdo = custodia_db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if ($row['locked_until'] !== null && strtotime($row['locked_until']) > time()) {
        return null;
    }

    if (!$row['is_active'] || !$row['password_hash'] || !password_verify($password, $row['password_hash'])) {
        if ($row['is_active'] && $row['password_hash']) {
            custodia_register_failed_login($pdo, $row);
        }
        return null;
    }

    if ((int) $row['failed_login_attempts'] > 0 || $row['locked_until'] !== null) {
        $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = :id')
            ->execute(['id' => $row['id']]);
    }

    custodia_start_session();
    session_regenerate_id(true); // fresh session id on every login — mitigates session fixation
    $_SESSION['user_id'] = $row['id'];
    $_SESSION['last_activity'] = time();

    unset($row['password_hash']);
    return $row;
}

/** Increments a user's failed-login counter and locks the account once the configured threshold is hit. */
function custodia_register_failed_login(PDO $pdo, array $row): void
{
    $security = custodia_config()['security'];
    $attempts = (int) $row['failed_login_attempts'] + 1;
    $lockedUntil = null;
    if ($attempts >= $security['max_failed_logins']) {
        $lockedUntil = (new DateTimeImmutable('now'))
            ->modify('+' . $security['lockout_minutes'] . ' minutes')
            ->format('Y-m-d H:i:s.u');
        $attempts = 0; // counter resets — a fresh window starts once the lock expires
    }
    $pdo->prepare('UPDATE users SET failed_login_attempts = :attempts, locked_until = :locked WHERE id = :id')
        ->execute(['attempts' => $attempts, 'locked' => $lockedUntil, 'id' => $row['id']]);

    if ($lockedUntil === null) {
        return;
    }

    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $row['id'], 'actionType' => 'ACCOUNT_LOCKED', 'entityType' => 'USER', 'entityId' => $row['id'],
            'ipAddress' => custodia_client_ip(),
            'reason' => "Locked after {$security['max_failed_logins']} consecutive failed sign-in attempts.",
        ]);

        // Two audiences, one event. To the account owner this is "you're
        // locked out, here's why" — and if it wasn't them typing, it is the
        // only warning they'll get that someone is guessing at their
        // password. To Admin/Records Manager it's a security signal worth
        // seeing in aggregate. ACCOUNT_LOCKED is in
        // CUSTODIA_EMAIL_MANDATORY_TYPES, so it ignores email preferences.
        $lockMinutes = $security['lockout_minutes'];
        custodia_notify_user(
            $pdo, $row['id'], 'ACCOUNT_LOCKED',
            'Your Custodia account has been locked',
            "After {$security['max_failed_logins']} failed sign-in attempts, your account is locked for {$lockMinutes} minutes. "
                . 'If this was not you, contact Records Management — someone may be trying to guess your password.',
            'USER', $row['id']
        );
        custodia_notify_users(
            $pdo, custodia_security_recipient_ids($pdo), 'ACCOUNT_LOCKED',
            'Account locked: ' . $row['full_name'],
            "{$row['full_name']} ({$row['email']}) was locked out after {$security['max_failed_logins']} consecutive failed sign-in attempts.",
            'USER', $row['id']
        );

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
    }
}

/**
 * Minutes remaining on an active lockout for this email, or null if the
 * account isn't currently locked (including when no account exists at all
 * — same as an unlocked account, so this never reveals whether an email is
 * registered on its own; only an actually-locked account is distinguishable).
 */
function custodia_account_lock_remaining_minutes(PDO $pdo, string $email): ?int
{
    $stmt = $pdo->prepare('SELECT locked_until FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $lockedUntil = $stmt->fetchColumn();
    if (!$lockedUntil) {
        return null;
    }
    $remainingSeconds = strtotime((string) $lockedUntil) - time();
    return $remainingSeconds > 0 ? (int) ceil($remainingSeconds / 60) : null;
}

function custodia_logout(): void
{
    custodia_start_session();
    $_SESSION = [];
    session_destroy();
}

/** Redirects to the login page (preserving the originally-requested URL) if not authenticated. */
function custodia_require_login(): array
{
    $user = custodia_current_user();
    if (!$user) {
        custodia_start_session();
        // REQUEST_URI already reflects the real browser-observed path (subfolder
        // and all), so it's stored as-is; only the hardcoded fallback and the
        // login redirect itself need to stay relative rather than root-absolute,
        // so this works whether the app is mounted at the domain root or a
        // subfolder (e.g. XAMPP's documented htdocs/custodia-php layout).
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
        header('Location: login.php');
        exit;
    }

    // Security review 2026-09-03, finding 1.1: an admin-issued or rotated
    // password (custodia_create_user()/custodia_reset_user_password() in
    // includes/users.php) sets must_reset_password — every page except the
    // reset form itself and sign-out bounces here until the user sets a
    // password only they know.
    if (!empty($user['must_reset_password'])) {
        $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($currentScript, ['change_password.php', 'logout.php'], true)) {
            header('Location: change_password.php');
            exit;
        }
    }

    return $user;
}

/** Layer 1 of the RBAC matrix — role capability. Call after custodia_require_login(). */
function custodia_require_role(array $user, array $allowedRoles): void
{
    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        require __DIR__ . '/../403.php';
        exit;
    }
}

/** Client IP, honoring a trusted reverse proxy's X-Forwarded-For if present. */
function custodia_client_ip(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** CSRF token helpers — one token per session, checked on every state-changing POST. */
function custodia_csrf_token(): string
{
    custodia_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function custodia_csrf_field(): string
{
    $token = htmlspecialchars(custodia_csrf_token(), ENT_QUOTES);
    return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">";
}

function custodia_require_csrf(): void
{
    custodia_start_session();
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $submitted)) {
        http_response_code(419);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Your session expired or this form was resubmitted. Please refresh and try again.']);
        exit;
    }
}
