<?php
/**
 * The role catalog — no longer a fixed enum. A System Administrator (the
 * manage_users permission, same gate as Admin → User Accounts and Admin →
 * Permissions) can add new roles from Admin → Permissions, where a new role
 * immediately appears as a new column in the capability matrix, unchecked
 * everywhere, ready to configure.
 *
 * Deliberately narrow: creating a role only gives it a key and a label.
 * It does NOT get any special standing in the deeper per-matter RBAC in
 * matter_access.php (ethical wall → confidentiality → assignment) — a new
 * role sees only matters it's explicitly assigned to, the same tier
 * Associate/Paralegal are in today, and can never become "firm-wide" (see
 * every matter, the way SYSTEM_ADMIN/RECORDS_MANAGER can) without a further,
 * separate change to that scoping logic. This was a deliberate scope
 * decision, not an oversight — see the "Roles and permissions" section of
 * the README.
 *
 * A role can be deleted (custodia_delete_role()) only if it isn't one of
 * the six built-ins and no user currently holds it — built-ins are load-
 * bearing (matter_access.php, custody.php, and others special-case those
 * exact role strings), and deleting a role out from under an existing user
 * would leave their `users.role` foreign key referencing nothing. Renaming
 * a role isn't supported yet.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/permissions.php';

/** Seed values only — the six roles this app was originally built around. */
const CUSTODIA_BUILTIN_ROLES = [
    'SYSTEM_ADMIN' => 'System Administrator',
    'RECORDS_MANAGER' => 'Records Manager',
    'PARTNER' => 'Partner',
    'ASSOCIATE' => 'Associate',
    'PARALEGAL' => 'Paralegal',
    'GUEST_AUDITOR' => 'Guest / Auditor',
];

/** @return array{role_key: string, label: string, is_builtin: int, created_at: string}[] */
function custodia_list_roles(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM roles ORDER BY is_builtin DESC, label ASC')->fetchAll();
}

/** @return string[] */
function custodia_role_keys(PDO $pdo): array
{
    return array_column(custodia_list_roles($pdo), 'role_key');
}

/** Falls back to the raw role key if it's somehow not in the catalog, rather than erroring on display. */
function custodia_role_label(PDO $pdo, string $role): string
{
    static $labels = null;
    if ($labels === null) {
        $labels = array_column(custodia_list_roles($pdo), 'label', 'role_key');
    }
    return $labels[$role] ?? $role;
}

function custodia_create_role(PDO $pdo, array $actor, string $roleKey, string $label, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'manage_users');

    $roleKey = strtoupper(trim($roleKey));
    $label = trim($label);
    if (!preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $roleKey)) {
        throw custodia_bad_request('Role key must be 2–64 characters: letters, numbers, and underscores, starting with a letter (e.g. COMPLIANCE_OFFICER).');
    }
    if ($label === '') {
        throw custodia_bad_request('Role label is required.');
    }

    $dupe = $pdo->prepare('SELECT role_key FROM roles WHERE role_key = :key');
    $dupe->execute(['key' => $roleKey]);
    if ($dupe->fetch()) {
        throw custodia_bad_request("A role with the key \"{$roleKey}\" already exists.");
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO roles (role_key, label, is_builtin) VALUES (:key, :label, 0)')
            ->execute(['key' => $roleKey, 'label' => $label]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'ROLE_CREATED', 'entityType' => 'ROLE', 'entityId' => $roleKey,
            'ipAddress' => $ipAddress, 'metadata' => ['label' => $label],
        ]);
        $pdo->commit();
        return ['roleKey' => $roleKey];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_delete_role(PDO $pdo, array $actor, string $roleKey, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'manage_users');

    $stmt = $pdo->prepare('SELECT * FROM roles WHERE role_key = :key');
    $stmt->execute(['key' => $roleKey]);
    $role = $stmt->fetch();
    if (!$role) {
        throw custodia_not_found('Role not found.');
    }
    if ($role['is_builtin']) {
        throw custodia_bad_request('Built-in roles can\'t be deleted.');
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = :key');
    $countStmt->execute(['key' => $roleKey]);
    $userCount = (int) $countStmt->fetchColumn();
    if ($userCount > 0) {
        $plural = $userCount === 1 ? 'user' : 'users';
        throw custodia_bad_request("Cannot delete this role — {$userCount} {$plural} still assigned to it. Reassign them to a different role first.");
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM role_permissions WHERE role = :key')->execute(['key' => $roleKey]);
        $pdo->prepare('DELETE FROM roles WHERE role_key = :key')->execute(['key' => $roleKey]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'ROLE_DELETED', 'entityType' => 'ROLE', 'entityId' => $roleKey,
            'ipAddress' => $ipAddress, 'metadata' => ['label' => $role['label']],
        ]);
        $pdo->commit();
        return ['roleKey' => $roleKey];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
