<?php
/**
 * One-time credential rotation — security review 2026-09-03, finding 1.1
 * (CRITICAL): the shared demo password "ChangeMe123!" was published in
 * plain text on the public login page, alongside every account's email —
 * including the System Administrator's. Removing that list from
 * login.php stops the disclosure going forward, but the password itself
 * must be assumed compromised and is very likely still active on most
 * real accounts, so it needs to be invalidated everywhere.
 *
 * Run once, by hand, from the app's directory, after applying
 * sql/upgrade_020_force_password_reset.sql:
 *   php jobs/rotate_all_passwords.php
 *
 * For every active user: generates a fresh, unique random password,
 * stores its hash, and sets must_reset_password so the account is forced
 * through change_password.php (see includes/auth.php's
 * custodia_require_login()) before it can reach anything else. Prints an
 * email -> temporary password table to STDOUT ONLY — nothing is written
 * to disk or to the audit log's metadata — for the System Administrator
 * to redistribute out of band. This is the same "admin sets a temporary
 * password and shares it directly" model already used for admin-created
 * and CSV-bulk-imported accounts (see includes/users.php).
 *
 * Safe to re-run: every run rotates again (e.g. if the printed list is
 * ever believed to have been seen by the wrong person).
 *
 * This is a one-time/on-demand tool, not a scheduled job — do not add it
 * to Task Scheduler alongside jobs/verify_audit_chain.php or
 * jobs/overdue_sweep.php.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/users.php'; // custodia_generate_temp_password()

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line, not the web.\n");
}

$pdo = custodia_db();
$rows = $pdo->query('SELECT id, email FROM users WHERE is_active = 1 ORDER BY email ASC')->fetchAll();

if (!$rows) {
    fwrite(STDOUT, "No active users found — nothing to rotate.\n");
    exit(0);
}

fwrite(STDOUT, 'Rotating passwords for ' . count($rows) . " active account(s)...\n\n");
fwrite(STDOUT, str_pad('Email', 40) . "Temporary password\n");
fwrite(STDOUT, str_repeat('-', 70) . "\n");

$pdo->beginTransaction();
try {
    foreach ($rows as $row) {
        $temp = custodia_generate_temp_password();
        $pdo->prepare('UPDATE users SET password_hash = :hash, must_reset_password = 1 WHERE id = :id')
            ->execute(['hash' => password_hash($temp, PASSWORD_DEFAULT), 'id' => $row['id']]);
        custodia_audit_record($pdo, [
            'actorId' => $row['id'], // system-initiated bulk rotation; no human admin session in a CLI context
            'actionType' => 'USER_PASSWORD_ROTATED', 'entityType' => 'USER', 'entityId' => $row['id'],
            'ipAddress' => '127.0.0.1',
            'reason' => 'Bulk credential rotation — security review 2026-09-03, finding 1.1 (shared demo password published on public login page).',
        ]);
        fwrite(STDOUT, str_pad($row['email'], 40) . $temp . "\n");
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Rotation failed, no changes were committed: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "\nDone. Share each temporary password with its user ONE AT A TIME, out of\n");
fwrite(STDOUT, "band (phone call, in person, a direct Teams/Signal message) — never by\n");
fwrite(STDOUT, "re-posting this whole list anywhere shared (email thread, group chat,\n");
fwrite(STDOUT, "shared doc). Every account will be required to set its own password the\n");
fwrite(STDOUT, "moment it next signs in, and cannot reach any other page until it does.\n");
