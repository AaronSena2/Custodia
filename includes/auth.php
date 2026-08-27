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

function custodia_start_session(): void
{
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

/** Attempts login; returns the user row on success, or null on bad credentials/inactive account. */
function custodia_attempt_login(string $email, string $password): ?array
{
    $stmt = custodia_db()->prepare('SELECT * FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    if (!$row || !$row['is_active'] || !$row['password_hash'] || !password_verify($password, $row['password_hash'])) {
        return null;
    }

    custodia_start_session();
    session_regenerate_id(true); // fresh session id on every login — mitigates session fixation
    $_SESSION['user_id'] = $row['id'];

    unset($row['password_hash']);
    return $row;
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
