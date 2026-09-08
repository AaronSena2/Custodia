<?php
/**
 * One-time, admin-only migration runner for sql/upgrade_021_login_lockout.sql
 * — and, as a prerequisite, the SCHEMA-ONLY part of
 * sql/upgrade_020_force_password_reset.sql.
 *
 * Why 020 is involved here too: upgrade_021's own ALTER TABLE adds
 * failed_login_attempts/locked_until "AFTER must_reset_password" — so it
 * depends on the must_reset_password column already existing. Running this
 * page against a database where upgrade_020 was never applied either used
 * to fail with "Unknown column 'must_reset_password' in 'users'" before
 * ever reaching upgrade_021's own change. This version checks for and adds
 * that column first if it's missing, then proceeds exactly as before.
 *
 * Deliberately NOT done here: upgrade_020's data change
 * (`UPDATE users SET must_reset_password = 1 WHERE is_active = 1`), which
 * forces every currently-active account into the change-password flow on
 * its next sign-in. That's a real operational decision — right now it
 * would immediately affect everyone using the app, including you — and
 * shouldn't happen as a side effect of fixing a login crash. This page
 * only adds the column (defaulted to 0, so nobody is flagged) so login
 * stops crashing. Run the actual password-reset enforcement separately,
 * on your own schedule, following the instructions at the top of
 * sql/upgrade_020_force_password_reset.sql (php jobs/rotate_all_passwords.php,
 * then hand out the generated temporary passwords one at a time).
 *
 * Why this exists as a web page instead of the usual phpMyAdmin/mysql-CLI
 * flow: the missing columns broke login.php outright, so this page applies
 * the fix through the app's own already-configured database connection —
 * reachable because your existing signed-in session still works (nothing
 * about being already logged in touches these columns).
 *
 * Safe to run more than once: each change is checked for before being
 * applied, and skipped if it's already there.
 *
 * DELETE THIS FILE once it reports success — it's a one-off tool, not
 * meant to stay in the app long-term.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$user = custodia_require_login();
custodia_require_role($user, ['SYSTEM_ADMIN']);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><title>Upgrade 021</title>';
echo '<style>body{font-family:system-ui,sans-serif;background:#0b1220;color:#e6edf3;padding:2rem;max-width:640px;margin:0 auto}
.ok{color:#4ade80}.err{color:#f87171}.card{background:#111a2e;border:1px solid #24314f;border-radius:8px;padding:1.5rem;margin-top:1rem}
a{color:#2dd4bf}code{background:#1a2540;padding:0.15rem 0.4rem;border-radius:4px}ul{margin:0.5rem 0 0;padding-left:1.25rem}li{margin:0.35rem 0}</style></head><body>';
echo '<h1>Upgrade 021 — account lockout columns</h1>';

function custodia_upgrade_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column"
    );
    $stmt->execute(['table' => $table, 'column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

try {
    $pdo = custodia_db();
    $actions = [];

    // Prerequisite: upgrade_020's column only (see header comment — the
    // password-reset UPDATE is intentionally not run here).
    if (!custodia_upgrade_column_exists($pdo, 'users', 'must_reset_password')) {
        $pdo->exec(
            "ALTER TABLE users
              ADD COLUMN must_reset_password TINYINT(1) NOT NULL DEFAULT 0
                AFTER mfa_enabled"
        );
        $actions[] = 'Added <code>must_reset_password</code> (defaulted to 0 — no accounts flagged).';
    } else {
        $actions[] = '<code>must_reset_password</code> already existed — left as-is.';
    }

    if (!custodia_upgrade_column_exists($pdo, 'users', 'locked_until')) {
        $pdo->exec(
            "ALTER TABLE users
              ADD COLUMN failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0
                AFTER must_reset_password,
              ADD COLUMN locked_until DATETIME(6) NULL
                AFTER failed_login_attempts"
        );
        $actions[] = 'Added <code>failed_login_attempts</code> and <code>locked_until</code>.';
    } else {
        $actions[] = '<code>failed_login_attempts</code>/<code>locked_until</code> already existed — left as-is.';
    }

    echo '<div class="card"><p class="ok"><strong>Done.</strong></p><ul>';
    foreach ($actions as $a) {
        echo '<li>' . $a . '</li>';
    }
    echo '</ul><p style="margin-top:1rem">Login and password-reset should both work normally now.</p></div>';

    echo '<div class="card"><p><strong>Not done — a separate decision:</strong></p>';
    echo '<p>The security-review fix that forces everyone to set their own password (in place of the shared demo password) has not been applied. '
       . 'That step immediately affects every active user\'s next sign-in, so it\'s left for you to run deliberately — see the instructions at the top of '
       . '<code>sql/upgrade_020_force_password_reset.sql</code> when you\'re ready.</p></div>';

    echo '<p style="margin-top:1.5rem">You can now <a href="login.php">go to login</a> or <a href="admin.php">back to Admin</a>. Please delete this file (<code>run_upgrade_021.php</code>) from the app folder now that it has run.</p>';
} catch (Throwable $e) {
    echo '<div class="card"><p class="err"><strong>Something went wrong:</strong></p>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre></div>';
}

echo '</body></html>';
