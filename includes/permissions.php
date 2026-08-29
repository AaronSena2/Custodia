<?php
/**
 * Role → permission matrix, admin-configurable from Admin → Permissions.
 * This governs the flat "which role(s) may do X" capability gates that used
 * to be hardcoded arrays scattered across includes/*.php (create a matter,
 * approve a movement, export the audit log, etc.) — see each call site's
 * comment for what it replaced.
 *
 * Deliberately does NOT touch the deeper three-layer per-matter RBAC in
 * includes/matter_access.php (ethical wall → confidentiality → assignment),
 * managing-partner-specific overrides (e.g. a Partner approving movements on
 * their own matter regardless of this matrix), or baseline role-tier rules
 * like "Guest/Auditor can't create documents" — those depend on matter state
 * or a role's fundamental tier, not on an admin-configurable toggle, and
 * folding them into this matrix would be a much bigger, riskier redesign
 * than what this feature is for.
 *
 * manage_users is special: it gates both user-account management AND this
 * permission matrix itself, so custodia_update_role_permissions() refuses to
 * let SYSTEM_ADMIN lose it — otherwise an admin could lock every admin out
 * of ever fixing the matrix again. See custodia_assert_manage_users_kept()
 * below.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/roles.php';

const CUSTODIA_PERMISSIONS = [
    'manage_users' => [
        'label' => 'Manage User Accounts',
        'description' => 'Create, edit, deactivate/reactivate user accounts, and reset passwords. Also controls access to this Permissions screen.',
    ],
    'manage_retention_policies' => [
        'label' => 'Manage Retention Policies',
        'description' => 'Create and configure document/matter retention policies.',
    ],
    'manage_practice_groups' => [
        'label' => 'Manage Practice Groups',
        'description' => 'Create/rename practice groups, manage their membership, and grant a group access to a matter from Admin → Practice Groups.',
    ],
    'create_matters' => [
        'label' => 'Create Matters',
        'description' => 'Open new matters.',
    ],
    'create_clients' => [
        'label' => 'Create Clients',
        'description' => 'Add a new client from the Clients directory. Matters are opened against an existing client picked from a dropdown, so a client needs to exist here first.',
    ],
    'edit_matters' => [
        'label' => 'Edit Matter Details',
        'description' => 'Edit an existing matter\'s number, client, practice area, incharge, status, and confidentiality.',
    ],
    'deactivate_matters' => [
        'label' => 'Deactivate/Reactivate Matters',
        'description' => 'One-click close (status → Closed) or reactivate (status → Active) a matter, separate from editing its other details.',
    ],
    'edit_clients' => [
        'label' => 'Edit Clients',
        'description' => "Rename an existing client.",
    ],
    'deactivate_clients' => [
        'label' => 'Deactivate/Reactivate Clients',
        'description' => 'Mark a client inactive or reactivate it. Informational only — an inactive client can still be selected when opening or editing a matter.',
    ],
    'manage_physical_locations' => [
        'label' => 'Manage Physical Locations',
        'description' => 'Add new physical storage locations (building/room/shelf/bin) that physical files can be registered or returned to, from Admin → Locations.',
    ],
    'register_physical_files' => [
        'label' => 'Register Physical Files',
        'description' => 'Register a new physical file and generate its barcode.',
    ],
    'edit_physical_files' => [
        'label' => 'Edit Physical File Profile',
        'description' => "Edit an existing physical file's jacket/box label and physical file number. Location may only be changed while the file is In Registry — see includes/custody.php for why it's otherwise tied to custody movements.",
    ],
    'close_physical_files' => [
        'label' => 'Close/Reopen Physical Files',
        'description' => "Mark a physical file Closed or Open — a lifecycle status independent of its custody status (In Registry, Issued, etc.).",
    ],
    'approve_custody_movements' => [
        'label' => 'Approve Custody Movements',
        'description' => "Approve or reject pending issues and transfers firm-wide. A matter's own incharge can always act on requests for that matter regardless of this setting.",
    ],
    'auto_approve_checkout' => [
        'label' => 'Auto-Approved Issue',
        'description' => 'Issue a physical file immediately, without a separate approval step.',
    ],
    'override_custody' => [
        'label' => 'Override Return',
        'description' => "Force-return a physical file that's issued to someone else.",
    ],
    'override_document_locks' => [
        'label' => 'Override Document Locks',
        'description' => "Force-release another user's document edit lock.",
    ],
    'override_document_protection' => [
        'label' => 'Override Document Protection',
        'description' => 'Mark/unmark a digital document as Protected, and bypass the view-permission and no-download restrictions on any Protected document.',
    ],
    'decide_access_requests' => [
        'label' => 'Decide Access Requests',
        'description' => "Approve or deny requests for access to restricted matters firm-wide. A matter's own incharge can always decide requests for that matter regardless of this setting.",
    ],
    'view_audit_log' => [
        'label' => 'View Audit Log',
        'description' => 'View the firm-wide Audit Log page. Off by default for every role but System Administrator — grant it to let a role see the audit trail at all before Export/Verify below mean anything for them.',
    ],
    'export_audit_log' => [
        'label' => 'Export Audit Log',
        'description' => 'Export the audit trail as a CSV report.',
    ],
    'verify_audit_chain' => [
        'label' => 'Verify Audit Chain Integrity',
        'description' => "Run the hash-chain tamper-check across the whole audit log.",
    ],
];

/** Seed values only — matches this app's original hardcoded behavior exactly, so installing this feature changes nothing until an admin edits the matrix. */
const CUSTODIA_DEFAULT_ROLE_PERMISSIONS = [
    'SYSTEM_ADMIN' => [
        'manage_users', 'manage_retention_policies', 'manage_practice_groups', 'create_matters', 'create_clients', 'edit_matters', 'deactivate_matters',
        'edit_clients', 'deactivate_clients', 'manage_physical_locations', 'register_physical_files', 'edit_physical_files', 'close_physical_files',
        'approve_custody_movements', 'auto_approve_checkout', 'override_custody', 'override_document_locks', 'override_document_protection',
        'decide_access_requests', 'view_audit_log', 'export_audit_log', 'verify_audit_chain',
    ],
    'RECORDS_MANAGER' => [
        'manage_retention_policies', 'create_matters', 'create_clients', 'register_physical_files',
        'approve_custody_movements', 'auto_approve_checkout', 'override_custody', 'override_document_locks', 'override_document_protection',
        'decide_access_requests', 'export_audit_log',
    ],
    'PARTNER' => [
        'create_matters', 'create_clients', 'approve_custody_movements', 'auto_approve_checkout', 'decide_access_requests', 'export_audit_log',
    ],
    'ASSOCIATE' => ['auto_approve_checkout'],
    'PARALEGAL' => [],
    'GUEST_AUDITOR' => [],
];

function custodia_role_has_permission(PDO $pdo, string $role, string $permissionKey): bool
{
    static $cache = [];
    if (!isset($cache[$role])) {
        $stmt = $pdo->prepare('SELECT permission_key FROM role_permissions WHERE role = :role');
        $stmt->execute(['role' => $role]);
        $cache[$role] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return in_array($permissionKey, $cache[$role], true);
}

function custodia_user_has_permission(PDO $pdo, array $user, string $permissionKey): bool
{
    return custodia_role_has_permission($pdo, $user['role'], $permissionKey);
}

function custodia_assert_permission(PDO $pdo, array $user, string $permissionKey): void
{
    if (!custodia_user_has_permission($pdo, $user, $permissionKey)) {
        $label = CUSTODIA_PERMISSIONS[$permissionKey]['label'] ?? $permissionKey;
        throw custodia_forbidden("You don't have the \"{$label}\" permission.");
    }
}

/** @return array<string, array{role: string, granted: bool}[]> keyed by permission_key, each an ordered list across every role in the catalog */
function custodia_list_role_permissions(PDO $pdo): array
{
    $granted = $pdo->query('SELECT role, permission_key FROM role_permissions')->fetchAll();
    $grantedSet = [];
    foreach ($granted as $row) {
        $grantedSet[$row['role']][$row['permission_key']] = true;
    }

    $roleKeys = custodia_role_keys($pdo);
    $matrix = [];
    foreach (CUSTODIA_PERMISSIONS as $key => $meta) {
        $matrix[$key] = [];
        foreach ($roleKeys as $role) {
            $matrix[$key][] = ['role' => $role, 'granted' => isset($grantedSet[$role][$key])];
        }
    }
    return $matrix;
}

/**
 * Replaces the entire role_permissions table with $grants (a flat list of
 * "ROLE:permission_key" strings from the matrix form's checked checkboxes).
 * Refuses to save if SYSTEM_ADMIN would lose manage_users — see file docblock.
 */
function custodia_update_role_permissions(PDO $pdo, array $actor, array $grants, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'manage_users');

    $validRoleKeys = custodia_role_keys($pdo);
    $pairs = [];
    foreach ($grants as $grant) {
        $parts = explode(':', $grant, 2);
        if (count($parts) !== 2) {
            continue;
        }
        [$role, $key] = $parts;
        if (!in_array($role, $validRoleKeys, true) || !isset(CUSTODIA_PERMISSIONS[$key])) {
            continue; // ignore anything not in the known catalog rather than erroring on a tampered/stale form
        }
        $pairs[] = [$role, $key];
    }

    $hasAdminManageUsers = false;
    foreach ($pairs as [$role, $key]) {
        if ($role === 'SYSTEM_ADMIN' && $key === 'manage_users') {
            $hasAdminManageUsers = true;
            break;
        }
    }
    if (!$hasAdminManageUsers) {
        throw custodia_bad_request('System Administrator must always retain the "Manage User Accounts" permission, so this screen can never be locked away from every admin.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM role_permissions');
        $insert = $pdo->prepare('INSERT INTO role_permissions (role, permission_key) VALUES (:role, :key)');
        foreach ($pairs as [$role, $key]) {
            $insert->execute(['role' => $role, 'key' => $key]);
        }

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'PERMISSIONS_UPDATED', 'entityType' => 'ROLE_PERMISSIONS', 'entityId' => 'matrix',
            'ipAddress' => $ipAddress, 'metadata' => ['grantCount' => count($pairs)],
        ]);
        $pdo->commit();
        return ['grantCount' => count($pairs)];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
