<?php
/**
 * User account management — System Administrator only. There's no self-service
 * signup or "forgot password" flow in this app (see README "Known gaps"), so
 * account creation and password resets are both admin-driven: the admin sets
 * an initial/new password here and shares it with the user out of band, the
 * same way the seeded demo accounts work.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/roles.php';

/** Gates every user-management action AND access to Admin → Permissions (see includes/permissions.php's docblock for why those two are tied together). */
function custodia_assert_manage_users_permission(PDO $pdo, array $actor): void
{
    custodia_assert_permission($pdo, $actor, 'manage_users');
}

function custodia_list_users(PDO $pdo, array $actor): array
{
    custodia_assert_manage_users_permission($pdo, $actor);
    $rows = $pdo->query('SELECT * FROM users ORDER BY is_active DESC, full_name ASC')->fetchAll();
    foreach ($rows as &$row) {
        unset($row['password_hash']);
    }
    unset($row);
    return $rows;
}

/** Throws unless at least one OTHER active System Administrator exists — the last admin can't be demoted/deactivated. */
function custodia_assert_not_last_active_admin(PDO $pdo, string $excludingUserId): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'SYSTEM_ADMIN' AND is_active = 1 AND id != :id");
    $stmt->execute(['id' => $excludingUserId]);
    if ((int) $stmt->fetchColumn() === 0) {
        throw custodia_bad_request('Cannot remove the last active System Administrator.');
    }
}

/** @param array{employeeId:string,fullName:string,email:string,role:string,password:string,barNumber?:string} $fields */
function custodia_create_user(PDO $pdo, array $actor, array $fields, string $ipAddress): array
{
    custodia_assert_manage_users_permission($pdo, $actor);

    $employeeId = trim($fields['employeeId'] ?? '');
    $fullName = trim($fields['fullName'] ?? '');
    $email = trim($fields['email'] ?? '');
    $role = $fields['role'] ?? '';
    $password = (string) ($fields['password'] ?? '');
    $barNumber = trim($fields['barNumber'] ?? '') ?: null;

    if ($employeeId === '' || $fullName === '' || $email === '') {
        throw custodia_bad_request('Employee ID, full name, and email are all required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw custodia_bad_request("That doesn't look like a valid email address.");
    }
    if (!in_array($role, custodia_role_keys($pdo), true)) {
        throw custodia_bad_request('Invalid role.');
    }
    if (strlen($password) < 8) {
        throw custodia_bad_request('Temporary password must be at least 8 characters.');
    }

    $dupe = $pdo->prepare('SELECT id FROM users WHERE email = :email OR employee_id = :eid');
    $dupe->execute(['email' => $email, 'eid' => $employeeId]);
    if ($dupe->fetch()) {
        throw custodia_bad_request('A user with that email or employee ID already exists.');
    }

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare(
            'INSERT INTO users (id, employee_id, full_name, email, password_hash, role, bar_number, is_active, must_reset_password)
             VALUES (:id, :eid, :name, :email, :hash, :role, :bar, 1, 1)'
        )->execute([
            'id' => $id, 'eid' => $employeeId, 'name' => $fullName, 'email' => $email,
            'hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role,
            'bar' => $barNumber,
        ]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'USER_CREATED', 'entityType' => 'USER', 'entityId' => $id,
            'ipAddress' => $ipAddress, 'metadata' => ['email' => $email, 'role' => $role],
        ]);
        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** @param array{employeeId:string,fullName:string,email:string,role:string,barNumber?:string} $fields */
function custodia_update_user(PDO $pdo, array $actor, string $userId, array $fields, string $ipAddress): array
{
    custodia_assert_manage_users_permission($pdo, $actor);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('User not found.');
    }

    $employeeId = trim($fields['employeeId'] ?? '');
    $fullName = trim($fields['fullName'] ?? '');
    $email = trim($fields['email'] ?? '');
    $role = $fields['role'] ?? '';
    $barNumber = trim($fields['barNumber'] ?? '') ?: null;

    if ($employeeId === '' || $fullName === '' || $email === '') {
        throw custodia_bad_request('Employee ID, full name, and email are all required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw custodia_bad_request("That doesn't look like a valid email address.");
    }
    if (!in_array($role, custodia_role_keys($pdo), true)) {
        throw custodia_bad_request('Invalid role.');
    }

    $dupe = $pdo->prepare('SELECT id FROM users WHERE (email = :email OR employee_id = :eid) AND id != :id');
    $dupe->execute(['email' => $email, 'eid' => $employeeId, 'id' => $userId]);
    if ($dupe->fetch()) {
        throw custodia_bad_request('Another user already has that email or employee ID.');
    }

    // Changing your own role away from SYSTEM_ADMIN, or anyone else's, must
    // never leave the system with zero active admins to undo it.
    if ($target['role'] === 'SYSTEM_ADMIN' && $role !== 'SYSTEM_ADMIN') {
        custodia_assert_not_last_active_admin($pdo, $userId);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE users SET employee_id = :eid, full_name = :name, email = :email, role = :role, bar_number = :bar WHERE id = :id'
        )->execute([
            'eid' => $employeeId, 'name' => $fullName, 'email' => $email, 'role' => $role,
            'bar' => $barNumber, 'id' => $userId,
        ]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'USER_UPDATED', 'entityType' => 'USER', 'entityId' => $userId,
            'ipAddress' => $ipAddress, 'metadata' => ['email' => $email, 'role' => $role],
        ]);
        $pdo->commit();
        return ['id' => $userId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_deactivate_user(PDO $pdo, array $actor, string $userId, string $ipAddress): array
{
    custodia_assert_manage_users_permission($pdo, $actor);

    if ($userId === $actor['id']) {
        throw custodia_bad_request('You cannot deactivate your own account.');
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('User not found.');
    }
    if (!$target['is_active']) {
        throw custodia_bad_request('User is already deactivated.');
    }
    if ($target['role'] === 'SYSTEM_ADMIN') {
        custodia_assert_not_last_active_admin($pdo, $userId);
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET is_active = 0, deactivated_at = NOW(6) WHERE id = :id')->execute(['id' => $userId]);
        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'USER_DEACTIVATED', 'entityType' => 'USER', 'entityId' => $userId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $userId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_reactivate_user(PDO $pdo, array $actor, string $userId, string $ipAddress): array
{
    custodia_assert_manage_users_permission($pdo, $actor);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('User not found.');
    }
    if ($target['is_active']) {
        throw custodia_bad_request('User is already active.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET is_active = 1, deactivated_at = NULL WHERE id = :id')->execute(['id' => $userId]);
        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'USER_REACTIVATED', 'entityType' => 'USER', 'entityId' => $userId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $userId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * @param bool $forceReset Whether the account must set its own password on
 *                          next sign-in (security review 2026-09-03, finding
 *                          1.1) — true by default, since an admin-set
 *                          password is by definition known to someone other
 *                          than the account owner until they change it.
 */
function custodia_reset_user_password(PDO $pdo, array $actor, string $userId, string $newPassword, string $ipAddress, bool $forceReset = true): array
{
    custodia_assert_manage_users_permission($pdo, $actor);

    if (strlen($newPassword) < 8) {
        throw custodia_bad_request('New password must be at least 8 characters.');
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    if (!$stmt->fetch()) {
        throw custodia_not_found('User not found.');
    }

    $pdo->beginTransaction();
    try {
        // Also lifts any active lockout (security review 2026-09-03, finding
        // 1.6) — a fresh admin-issued password is the standard way to
        // unlock an account without waiting out the lockout timer.
        $pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_reset_password = :force,
                failed_login_attempts = 0, locked_until = NULL WHERE id = :id'
        )->execute([
                'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'force' => $forceReset ? 1 : 0,
                'id' => $userId,
            ]);
        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'USER_PASSWORD_RESET', 'entityType' => 'USER', 'entityId' => $userId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $userId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** URL-safe, unambiguous-enough random temporary password for a bulk-created account — same "admin sets it and shares it directly" model as a single Create User, just generated instead of typed. */
function custodia_generate_temp_password(): string
{
    return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 12);
}

/**
 * Imports many users from CSV rows (see custodia_read_csv_rows() — expects
 * columns "employee id", "full name", "email", "role", "bar number",
 * "practice areas"; only bar number/practice areas are optional). "role"
 * matches a role's key or label, case-insensitively, against the live
 * roles catalog (including custom roles). Each row goes through the normal
 * custodia_create_user() with a freshly generated password, one row at a
 * time, so a single bad row doesn't block the rest of the file.
 *
 * @param array<int, array<string, string>> $rows
 */
function custodia_bulk_import_users(PDO $pdo, array $actor, array $rows, string $ipAddress): array
{
    custodia_assert_manage_users_permission($pdo, $actor);

    $roleLookup = [];
    foreach (custodia_list_roles($pdo) as $r) {
        $roleLookup[strtolower($r['role_key'])] = $r['role_key'];
        $roleLookup[strtolower($r['label'])] = $r['role_key'];
    }

    $created = [];
    $skipped = [];
    foreach ($rows as $i => $row) {
        $rowNum = $i + 2;
        $fullName = $row['full name'] ?? '';
        $roleInput = $row['role'] ?? '';
        $roleKey = $roleLookup[strtolower($roleInput)] ?? null;

        if ($roleKey === null) {
            $skipped[] = ['row' => $rowNum, 'name' => $fullName, 'reason' => $roleInput === '' ? 'Role is required.' : "Unrecognized role \"{$roleInput}\"."];
            continue;
        }

        $password = custodia_generate_temp_password();
        try {
            $result = custodia_create_user($pdo, $actor, [
                'employeeId' => $row['employee id'] ?? '',
                'fullName' => $fullName,
                'email' => $row['email'] ?? '',
                'role' => $roleKey,
                'password' => $password,
                'barNumber' => $row['bar number'] ?? '',
            ], $ipAddress);
            $created[] = [
                'row' => $rowNum, 'fullName' => $fullName, 'email' => $row['email'] ?? '',
                'employeeId' => $row['employee id'] ?? '', 'id' => $result['id'], 'password' => $password,
            ];
        } catch (CustodiaHttpException $e) {
            $skipped[] = ['row' => $rowNum, 'name' => $fullName, 'reason' => $e->getMessage()];
        }
    }

    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'USERS_BULK_IMPORTED', 'entityType' => 'USER', 'entityId' => 'bulk-import',
            'ipAddress' => $ipAddress, 'metadata' => ['createdCount' => count($created), 'skippedCount' => count($skipped)],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['created' => $created, 'skipped' => $skipped];
}
