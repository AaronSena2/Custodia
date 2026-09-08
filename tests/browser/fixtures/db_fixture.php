<?php
/**
 * Test-only fixture helper for tests/browser/'s Playwright suite. NOT part
 * of the deployed app — never referenced from anywhere under the app root
 * itself, only invoked directly by the Node test runner via child_process.
 *
 * Deliberately raw SQL rather than routing through the app's own
 * custodia_create_user()/custodia_update_role_permissions() (includes/users.php,
 * includes/permissions.php): those exist to be called from an authenticated
 * admin action with a real actor and audit trail, which fixture setup for a
 * test doesn't need — and custodia_update_role_permissions() in particular
 * replaces the ENTIRE role_permissions table in one call (DELETE FROM
 * role_permissions, then reinsert everything passed in), so driving it from
 * here would mean reading the full existing matrix first just to avoid
 * wiping every other role's permissions out from under whichever spec file
 * runs next. A scoped INSERT for just the rows this fixture needs is
 * simpler and can't affect other tests' state.
 *
 * Usage: php db_fixture.php <command> '<json args>'
 * Commands: create-user, delete-user, create-role, delete-role, reset-lockout
 * Prints a JSON object to stdout on success; non-zero exit + stderr message
 * on failure.
 */

require_once __DIR__ . '/../../../includes/db.php';

function custodia_test_uuid(): string
{
    return sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        random_int(0, 0xffffffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffffffffffff)
    );
}

$command = $argv[1] ?? '';
$args = json_decode($argv[2] ?? '{}', true);
if (!is_array($args)) {
    $args = [];
}

$pdo = custodia_db();

try {
    switch ($command) {
        case 'create-user':
            $id = custodia_test_uuid();
            $pdo->prepare(
                'INSERT INTO users (id, employee_id, full_name, email, password_hash, role, is_active, must_reset_password)
                 VALUES (:id, :emp, :name, :email, :hash, :role, 1, :reset)'
            )->execute([
                'id' => $id,
                'emp' => 'E2E-' . substr($id, 0, 8),
                'name' => $args['fullName'] ?? 'E2E Test User',
                'email' => $args['email'],
                'hash' => password_hash($args['password'], PASSWORD_DEFAULT),
                'role' => $args['role'],
                'reset' => !empty($args['mustResetPassword']) ? 1 : 0,
            ]);
            echo json_encode(['id' => $id]);
            break;

        case 'delete-user':
            // A throwaway test user that never triggered an audit_log row
            // (fk_al_actor) can be hard-deleted cleanly; one that did
            // (a failed-login lockout, a VIEW, an approval, ...) can't be —
            // audit_log is append-only/immutable by design (the hash chain
            // depends on it), so cleanup falls back to deactivating instead
            // of deleting, the same as the real app would for any user who
            // has left an audit trail. Leftover deactivated E2E test rows
            // are harmless (unique per-run email/employee_id, is_active = 0
            // so they never show up as a real account) — not deleting them
            // is the correct behavior here, not a bug.
            try {
                $pdo->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $args['email']]);
                echo json_encode(['ok' => true, 'mode' => 'deleted']);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                $pdo->prepare('UPDATE users SET is_active = 0, deactivated_at = NOW(6) WHERE email = :email')
                    ->execute(['email' => $args['email']]);
                echo json_encode(['ok' => true, 'mode' => 'deactivated']);
            }
            break;

        case 'create-role':
            $roleKey = $args['roleKey'];
            $pdo->prepare('INSERT INTO roles (role_key, label, is_builtin) VALUES (:key, :label, 0)')
                ->execute(['key' => $roleKey, 'label' => $args['label'] ?? $roleKey]);
            $insert = $pdo->prepare('INSERT INTO role_permissions (role, permission_key) VALUES (:role, :perm)');
            foreach ($args['permissions'] ?? [] as $perm) {
                $insert->execute(['role' => $roleKey, 'perm' => $perm]);
            }
            echo json_encode(['roleKey' => $roleKey]);
            break;

        case 'delete-role':
            $roleKey = $args['roleKey'];
            $pdo->prepare('DELETE FROM role_permissions WHERE role = :role')->execute(['role' => $roleKey]);
            // If delete-user above had to fall back to deactivating rather
            // than deleting (an audit trail exists for that user), the
            // deactivated row still FK-references this role_key, so this
            // DELETE can legitimately still fail — that's expected, not an
            // error worth failing test cleanup over. Test role keys are
            // timestamped by the caller specifically so a left-behind role
            // never collides with (or is assumed present by) a later run.
            try {
                $pdo->prepare('DELETE FROM roles WHERE role_key = :role')->execute(['role' => $roleKey]);
                echo json_encode(['ok' => true, 'mode' => 'deleted']);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
                echo json_encode(['ok' => true, 'mode' => 'left-in-place-still-referenced']);
            }
            break;

        case 'reset-lockout':
            $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE email = :email')
                ->execute(['email' => $args['email']]);
            echo json_encode(['ok' => true]);
            break;

        case 'set-matter-incharge':
            // Used by rbac.spec.js to test the finding-2.2 data-driven
            // managing-partner override with a non-Partner role.
            $pdo->prepare('UPDATE matters SET managing_partner_id = :uid WHERE id = :mid')
                ->execute(['uid' => $args['userId'], 'mid' => $args['matterId']]);
            echo json_encode(['ok' => true]);
            break;

        case 'add-team-member':
            $pdo->prepare(
                'INSERT INTO matter_team_members (id, matter_id, user_id, role_on_matter) VALUES (:id, :mid, :uid, :role)'
            )->execute([
                'id' => custodia_test_uuid(),
                'mid' => $args['matterId'],
                'uid' => $args['userId'],
                'role' => $args['roleOnMatter'] ?? 'Team Member',
            ]);
            echo json_encode(['ok' => true]);
            break;

        case 'remove-team-member':
            $pdo->prepare('DELETE FROM matter_team_members WHERE matter_id = :mid AND user_id = :uid')
                ->execute(['mid' => $args['matterId'], 'uid' => $args['userId']]);
            echo json_encode(['ok' => true]);
            break;

        case 'get-file-id-by-barcode':
            $stmt = $pdo->prepare('SELECT id, status FROM physical_files WHERE barcode = :bc');
            $stmt->execute(['bc' => $args['barcode']]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException("No physical file found with barcode {$args['barcode']}");
            }
            echo json_encode($row);
            break;

        case 'get-first-location-id':
            $row = $pdo->query('SELECT id FROM physical_locations LIMIT 1')->fetch();
            if (!$row) {
                throw new RuntimeException('No physical_locations rows exist.');
            }
            echo json_encode(['id' => $row['id']]);
            break;

        case 'set-file-status':
            // Forces a physical_files row into a known state directly,
            // bypassing custodia_checkout()/custodia_complete_checkin()
            // entirely. custody.spec.js uses this at the start of each test
            // instead of asserting a precondition on whatever seed.php (or a
            // previous run) happened to leave a barcode in: seed.php only
            // seeds one of the three fixture barcodes as IN_REGISTRY — the
            // other two are deliberately seeded CHECKED_OUT/OFFSITE_ARCHIVE
            // as fixture data for the app's own overdue/offsite-archive
            // features — so "assume the seeded status" was never a safe
            // assumption to begin with, independent of any cleanup bug.
            // Existence is checked with its own SELECT rather than trusting
            // UPDATE's rowCount(): PDO/MySQL's affected-rows count reflects
            // rows actually CHANGED, not rows MATCHED by the WHERE clause —
            // forcing a file that's already in the target state (e.g. the
            // very first run against a freshly reseeded DB, where
            // PF-000482912 already starts IN_REGISTRY) is a real no-op
            // update, and rowCount() === 0 for that case does not mean the
            // barcode doesn't exist.
            $exists = $pdo->prepare('SELECT 1 FROM physical_files WHERE barcode = :bc');
            $exists->execute(['bc' => $args['barcode']]);
            if (!$exists->fetchColumn()) {
                throw new RuntimeException("No physical file found with barcode {$args['barcode']}");
            }
            $pdo->prepare(
                'UPDATE physical_files SET status = :status, current_custodian_id = :cust, current_location_id = :loc WHERE barcode = :bc'
            )->execute([
                'status' => $args['status'],
                'cust' => $args['custodianId'] ?? null,
                'loc' => $args['locationId'] ?? null,
                'bc' => $args['barcode'],
            ]);
            echo json_encode(['ok' => true]);
            break;

        case 'get-matter-id-by-number':
            $stmt = $pdo->prepare('SELECT id FROM matters WHERE matter_number = :num');
            $stmt->execute(['num' => $args['matterNumber']]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException("No matter found with number {$args['matterNumber']}");
            }
            echo json_encode(['id' => $row['id']]);
            break;

        default:
            fwrite(STDERR, "Unknown command: {$command}\n");
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
