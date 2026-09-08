<?php
/**
 * Practice Groups — Admin → Practice Groups (manage_practice_groups
 * permission). Converted from the old flat "Practice Areas" catalog
 * (sql/upgrade_014_practice_groups.sql): matters.practice_area and
 * retention_policies.practice_area still stay plain text, matched by name,
 * not a foreign key to this table — same as before. What changed is USER
 * membership: it used to be a JSON array of names on the user row, and is
 * now a real practice_group_members join table, managed from a group's own
 * page (practice_group.php) instead of a free-text field on the user form.
 *
 * Two matter-access grant mechanisms live here too:
 *   - a whole group can be granted access to a matter (practice_group_matter_grants)
 *   - an individual user can be granted direct access — see
 *     custodia_admin_grant_matter_access() in includes/access_requests.php,
 *     which reuses the access_requests table instead of a new one.
 * Both are read by includes/matter_access.php's custodia_assert_matter_access()
 * and includes/matters.php's custodia_matters_for_user().
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/notifications.php';

/** No permission required — this just powers dropdowns/filters for anyone who can already see matter/user forms. */
function custodia_list_practice_groups(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM practice_groups ORDER BY name ASC')->fetchAll();
}

function custodia_get_practice_group(PDO $pdo, string $groupId): array
{
    $stmt = $pdo->prepare('SELECT * FROM practice_groups WHERE id = :id');
    $stmt->execute(['id' => $groupId]);
    $group = $stmt->fetch();
    if (!$group) {
        throw custodia_not_found('Practice group not found.');
    }
    return $group;
}

function custodia_create_practice_group(PDO $pdo, array $actor, string $name, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'manage_practice_groups');

    $name = trim($name);
    if ($name === '') {
        throw custodia_bad_request('Practice group name is required.');
    }

    $dupe = $pdo->prepare('SELECT id FROM practice_groups WHERE name = :name');
    $dupe->execute(['name' => $name]);
    if ($dupe->fetch()) {
        throw custodia_bad_request('A practice group with that name already exists.');
    }

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare('INSERT INTO practice_groups (id, name) VALUES (:id, :name)')->execute(['id' => $id, 'name' => $name]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'PRACTICE_GROUP_CREATED', 'entityType' => 'PRACTICE_GROUP', 'entityId' => $id,
            'ipAddress' => $ipAddress, 'metadata' => ['name' => $name],
        ]);
        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_update_practice_group(PDO $pdo, array $actor, string $groupId, string $name, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'manage_practice_groups');

    $target = custodia_get_practice_group($pdo, $groupId);

    $name = trim($name);
    if ($name === '') {
        throw custodia_bad_request('Practice group name is required.');
    }

    $dupe = $pdo->prepare('SELECT id FROM practice_groups WHERE name = :name AND id != :id');
    $dupe->execute(['name' => $name, 'id' => $groupId]);
    if ($dupe->fetch()) {
        throw custodia_bad_request('A practice group with that name already exists.');
    }

    $oldName = $target['name'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE practice_groups SET name = :name WHERE id = :id')->execute(['name' => $name, 'id' => $groupId]);

        if ($oldName !== $name) {
            $pdo->prepare('UPDATE matters SET practice_area = :new WHERE practice_area = :old')->execute(['new' => $name, 'old' => $oldName]);
            $pdo->prepare('UPDATE retention_policies SET practice_area = :new WHERE practice_area = :old')->execute(['new' => $name, 'old' => $oldName]);
        }

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'PRACTICE_GROUP_UPDATED', 'entityType' => 'PRACTICE_GROUP', 'entityId' => $groupId,
            'ipAddress' => $ipAddress, 'metadata' => ['oldName' => $oldName, 'newName' => $name],
        ]);
        $pdo->commit();
        return ['id' => $groupId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Membership ───────────────────────────────────────────────────────

function custodia_list_group_members(PDO $pdo, string $groupId): array
{
    $stmt = $pdo->prepare(
        'SELECT pgm.id, u.id AS user_id, u.full_name, u.role, u.is_active
         FROM practice_group_members pgm JOIN users u ON u.id = pgm.user_id
         WHERE pgm.practice_group_id = :gid ORDER BY u.full_name ASC'
    );
    $stmt->execute(['gid' => $groupId]);
    return $stmt->fetchAll();
}

/** @return string[] group names this user belongs to — replaces the old users.practice_areas JSON array. */
function custodia_list_user_group_names(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT pg.name FROM practice_group_members pgm JOIN practice_groups pg ON pg.id = pgm.practice_group_id
         WHERE pgm.user_id = :uid ORDER BY pg.name ASC'
    );
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function custodia_add_group_member(PDO $pdo, array $actor, string $groupId, string $userId, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'manage_practice_groups');
    custodia_get_practice_group($pdo, $groupId); // 404s if missing

    $userStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id');
    $userStmt->execute(['id' => $userId]);
    if (!$userStmt->fetch()) {
        throw custodia_not_found('User not found.');
    }

    $dupe = $pdo->prepare('SELECT id FROM practice_group_members WHERE practice_group_id = :gid AND user_id = :uid');
    $dupe->execute(['gid' => $groupId, 'uid' => $userId]);
    if ($dupe->fetch()) {
        throw custodia_bad_request('That user is already a member of this group.');
    }

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare('INSERT INTO practice_group_members (id, practice_group_id, user_id) VALUES (:id, :gid, :uid)')
            ->execute(['id' => $id, 'gid' => $groupId, 'uid' => $userId]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'PRACTICE_GROUP_MEMBER_ADDED', 'entityType' => 'PRACTICE_GROUP', 'entityId' => $groupId,
            'ipAddress' => $ipAddress, 'metadata' => ['userId' => $userId],
        ]);
        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_remove_group_member(PDO $pdo, array $actor, string $membershipId, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'manage_practice_groups');

    $stmt = $pdo->prepare('SELECT * FROM practice_group_members WHERE id = :id');
    $stmt->execute(['id' => $membershipId]);
    $membership = $stmt->fetch();
    if (!$membership) {
        throw custodia_not_found('Group membership not found.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM practice_group_members WHERE id = :id')->execute(['id' => $membershipId]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'PRACTICE_GROUP_MEMBER_REMOVED', 'entityType' => 'PRACTICE_GROUP', 'entityId' => $membership['practice_group_id'],
            'ipAddress' => $ipAddress, 'metadata' => ['userId' => $membership['user_id']],
        ]);
        $pdo->commit();
        return ['id' => $membershipId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Group → matter access grants ─────────────────────────────────────

function custodia_list_group_matter_grants(PDO $pdo, string $groupId): array
{
    $stmt = $pdo->prepare(
        'SELECT g.id, g.reason, g.created_at, m.id AS matter_id, m.matter_number, c.name AS client_name, u.full_name AS granted_by_name
         FROM practice_group_matter_grants g
         JOIN matters m ON m.id = g.matter_id
         JOIN clients c ON c.id = m.client_id
         JOIN users u ON u.id = g.granted_by_id
         WHERE g.practice_group_id = :gid ORDER BY g.created_at DESC'
    );
    $stmt->execute(['gid' => $groupId]);
    return $stmt->fetchAll();
}

/** Groups currently granted access to this matter — shown on matter.php's Team & Access tab. */
function custodia_list_matter_group_grants(PDO $pdo, string $matterId): array
{
    $stmt = $pdo->prepare(
        'SELECT g.id, g.created_at, pg.id AS practice_group_id, pg.name AS group_name, u.full_name AS granted_by_name
         FROM practice_group_matter_grants g
         JOIN practice_groups pg ON pg.id = g.practice_group_id
         JOIN users u ON u.id = g.granted_by_id
         WHERE g.matter_id = :mid ORDER BY g.created_at DESC'
    );
    $stmt->execute(['mid' => $matterId]);
    return $stmt->fetchAll();
}

function custodia_grant_group_matter_access(PDO $pdo, array $actor, string $groupId, string $matterId, ?string $reason, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'manage_practice_groups');
    custodia_get_practice_group($pdo, $groupId); // 404s if missing

    $matterStmt = $pdo->prepare('SELECT id FROM matters WHERE id = :id');
    $matterStmt->execute(['id' => $matterId]);
    if (!$matterStmt->fetch()) {
        throw custodia_not_found('Matter not found.');
    }

    $dupe = $pdo->prepare('SELECT id FROM practice_group_matter_grants WHERE practice_group_id = :gid AND matter_id = :mid');
    $dupe->execute(['gid' => $groupId, 'mid' => $matterId]);
    if ($dupe->fetch()) {
        throw custodia_bad_request('This group already has access to this matter.');
    }

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare('INSERT INTO practice_group_matter_grants (id, practice_group_id, matter_id, granted_by_id, reason) VALUES (:id, :gid, :mid, :by, :reason)')
            ->execute(['id' => $id, 'gid' => $groupId, 'mid' => $matterId, 'by' => $actor['id'], 'reason' => $reason]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'PRACTICE_GROUP_MATTER_ACCESS_GRANTED', 'entityType' => 'MATTER', 'entityId' => $matterId,
            'reason' => $reason, 'ipAddress' => $ipAddress, 'metadata' => ['practiceGroupId' => $groupId],
        ]);

        $group = custodia_get_practice_group($pdo, $groupId);
        $matterLabelStmt = $pdo->prepare('SELECT m.matter_number, c.name AS client_name FROM matters m JOIN clients c ON c.id = m.client_id WHERE m.id = :id');
        $matterLabelStmt->execute(['id' => $matterId]);
        $matterLabel = $matterLabelStmt->fetch();
        $matterText = $matterLabel ? "{$matterLabel['matter_number']} ({$matterLabel['client_name']})" : 'a matter';
        $memberIds = array_column(custodia_list_group_members($pdo, $groupId), 'user_id');
        custodia_notify_users(
            $pdo, $memberIds, 'GROUP_MATTER_ACCESS_GRANTED',
            "Your \"{$group['name']}\" group was granted access: {$matterText}", $reason, 'MATTER', $matterId
        );

        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_revoke_group_matter_access(PDO $pdo, array $actor, string $grantId, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'manage_practice_groups');

    $stmt = $pdo->prepare('SELECT * FROM practice_group_matter_grants WHERE id = :id');
    $stmt->execute(['id' => $grantId]);
    $grant = $stmt->fetch();
    if (!$grant) {
        throw custodia_not_found('Access grant not found.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM practice_group_matter_grants WHERE id = :id')->execute(['id' => $grantId]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'PRACTICE_GROUP_MATTER_ACCESS_REVOKED', 'entityType' => 'MATTER', 'entityId' => $grant['matter_id'],
            'ipAddress' => $ipAddress, 'metadata' => ['practiceGroupId' => $grant['practice_group_id']],
        ]);
        $pdo->commit();
        return ['id' => $grantId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Does this user have matter access via ANY practice group they belong to? Used by matter_access.php and matters.php. */
function custodia_user_has_group_matter_access(PDO $pdo, string $userId, string $matterId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM practice_group_matter_grants g
         JOIN practice_group_members pgm ON pgm.practice_group_id = g.practice_group_id
         WHERE g.matter_id = :mid AND pgm.user_id = :uid LIMIT 1'
    );
    $stmt->execute(['mid' => $matterId, 'uid' => $userId]);
    return (bool) $stmt->fetch();
}

// ── Standing "sees every matter" group ──────────────────────────────

/**
 * Name of the practice group that automatically gets access to every new
 * matter, per explicit firm decision (2026-09-06): "Registry" is the
 * records/registry staff group, not a legal practice team, and they need
 * visibility into every matter regardless of practice area. Matched by
 * exact name against practice_groups.name.
 */
const CUSTODIA_AUTO_GRANT_MATTER_GROUP_NAME = 'Registry';

/**
 * Grants CUSTODIA_AUTO_GRANT_MATTER_GROUP_NAME automatic access to a
 * just-created matter. Called from custodia_create_matter() inside that
 * function's own transaction, so the matter row and this grant either both
 * land or both roll back together.
 *
 * Deliberately bypasses the manage_practice_groups permission check that
 * custodia_grant_group_matter_access() enforces — this isn't a
 * user-initiated grant, it's standing firm policy applied automatically on
 * every matter opening, so any user who can create a matter can trigger it.
 * The audit entry still attributes it to the matter's creator.
 *
 * Silently does nothing if no group named "Registry" exists in this
 * environment (renamed, deleted, or a firm that opts out of this default)
 * or if the grant already exists — both are fine, not errors.
 */
function custodia_auto_grant_registry_group_access(PDO $pdo, array $user, string $matterId, string $ipAddress): void
{
    $groupStmt = $pdo->prepare('SELECT id, name FROM practice_groups WHERE name = :name');
    $groupStmt->execute(['name' => CUSTODIA_AUTO_GRANT_MATTER_GROUP_NAME]);
    $group = $groupStmt->fetch();
    if (!$group) {
        return;
    }

    $dupe = $pdo->prepare('SELECT id FROM practice_group_matter_grants WHERE practice_group_id = :gid AND matter_id = :mid');
    $dupe->execute(['gid' => $group['id'], 'mid' => $matterId]);
    if ($dupe->fetch()) {
        return;
    }

    $grantId = custodia_uuid();
    $reason = 'Automatic: the "Registry" group is granted access to every new matter by default.';
    $pdo->prepare('INSERT INTO practice_group_matter_grants (id, practice_group_id, matter_id, granted_by_id, reason) VALUES (:id, :gid, :mid, :by, :reason)')
        ->execute(['id' => $grantId, 'gid' => $group['id'], 'mid' => $matterId, 'by' => $user['id'], 'reason' => $reason]);

    custodia_audit_record($pdo, [
        'actorId' => $user['id'], 'actionType' => 'PRACTICE_GROUP_MATTER_ACCESS_GRANTED', 'entityType' => 'MATTER', 'entityId' => $matterId,
        'reason' => $reason, 'ipAddress' => $ipAddress, 'metadata' => ['practiceGroupId' => $group['id'], 'auto' => true],
    ]);

    $memberIds = array_column(custodia_list_group_members($pdo, $group['id']), 'user_id');
    custodia_notify_users(
        $pdo, $memberIds, 'GROUP_MATTER_ACCESS_GRANTED',
        "Your \"{$group['name']}\" group was granted access: a new matter was opened", null, 'MATTER', $matterId
    );
}
