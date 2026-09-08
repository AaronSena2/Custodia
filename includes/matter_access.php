<?php
/**
 * Three-layer RBAC / matter access check — PHP port of MatterAccessService
 * (blueprint Section 3). All three layers must pass:
 *   1. Role capability        — enforced by the caller via custodia_require_role().
 *   2. Matter assignment      — team membership, firm-wide role, approved
 *                               access request, or a Partner's own practice area.
 *   3. Ethical wall / confidentiality tier — this file's real job.
 *
 * custodia_assert_matter_access() throws CustodiaHttpException on any
 * failure; callers should let that propagate to the action-endpoint's
 * top-level catch (see includes/action_bootstrap.php) rather than catching
 * it themselves, the same way the Node version's ForbiddenException/
 * NotFoundException propagated up to Nest's exception filter.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/practice_groups.php';

function custodia_firm_wide_roles(): array
{
    return ['SYSTEM_ADMIN', 'RECORDS_MANAGER'];
}

/**
 * Returns the matter row (plus 'team' => list of userIds currently on it) on
 * success. Throws on any failure — see file header.
 */
function custodia_assert_matter_access(PDO $pdo, array $user, string $matterId): array
{
    $stmt = $pdo->prepare('SELECT * FROM matters WHERE id = :id');
    $stmt->execute(['id' => $matterId]);
    $matter = $stmt->fetch();
    if (!$matter) {
        throw custodia_not_found('Matter not found.');
    }

    $teamStmt = $pdo->prepare('SELECT user_id FROM matter_team_members WHERE matter_id = :id');
    $teamStmt->execute(['id' => $matterId]);
    $teamUserIds = array_column($teamStmt->fetchAll(), 'user_id');

    $wallStmt = $pdo->prepare('SELECT user_id FROM ethical_walls WHERE matter_id = :id');
    $wallStmt->execute(['id' => $matterId]);
    $walledUserIds = array_column($wallStmt->fetchAll(), 'user_id');

    // Layer 3a — ethical wall. Hard deny-only, checked first; nothing overrides
    // it except the explicit, separately-logged custodia_bypass_ethical_wall().
    if (in_array($user['id'], $walledUserIds, true)) {
        throw custodia_forbidden('You are ethically walled from this matter.');
    }

    if ($user['role'] === 'GUEST_AUDITOR') {
        // Guests never get matter-level access — only explicit per-document
        // grants via a share link, which bypasses this check entirely.
        throw custodia_forbidden('Guest accounts do not have matter-level access.');
    }

    $isTeamMember = in_array($user['id'], $teamUserIds, true);
    $hasFirmWideReach = in_array($user['role'], custodia_firm_wide_roles(), true);

    // An approved AccessRequest is a durable grant — it must satisfy BOTH the
    // confidentiality gate below (3b) AND the assignment gate after it (2), or
    // approval would be pointless for anyone who isn't also a team member.
    $hasApprovedAccess = false;
    if (!$hasFirmWideReach && !$isTeamMember) {
        $arStmt = $pdo->prepare(
            "SELECT id FROM access_requests
             WHERE requester_id = :uid AND entity_type = 'MATTER' AND entity_id = :mid AND status = 'APPROVED'
             LIMIT 1"
        );
        $arStmt->execute(['uid' => $user['id'], 'mid' => $matter['id']]);
        $hasApprovedAccess = (bool) $arStmt->fetch();
    }

    // A Practice Group matter grant is a durable access source in its own
    // right, same standing as an individually-approved AccessRequest above —
    // it must satisfy BOTH gates below (3b and 2) for the same reason.
    $hasGroupAccess = (!$hasFirmWideReach && !$isTeamMember)
        ? custodia_user_has_group_matter_access($pdo, $user['id'], $matter['id'])
        : false;

    // Layer 3b — confidentiality tier. RESTRICTED/PRIVILEGED matters are
    // invisible to anyone not already on the team, even other partners,
    // until an AccessRequest is approved.
    if ($matter['confidentiality'] !== 'STANDARD' && !$hasFirmWideReach && !$isTeamMember && !$hasApprovedAccess && !$hasGroupAccess) {
        throw custodia_forbidden("This matter is {$matter['confidentiality']}. Request access before viewing it.");
    }

    // Layer 2 — matter assignment. Firm-wide roles, team members, and approved
    // requesters pass; a Partner additionally passes for matters in their own
    // practice area; everyone else must be on the team.
    if (!$hasFirmWideReach && !$isTeamMember && !$hasApprovedAccess && !$hasGroupAccess) {
        $practiceGroupNames = $user['practice_group_names'] ?? [];
        $partnerHasPracticeAreaReach = $user['role'] === 'PARTNER' && in_array($matter['practice_area'], $practiceGroupNames, true);
        if (!$partnerHasPracticeAreaReach) {
            throw custodia_forbidden('You are not assigned to this matter.');
        }
    }

    $matter['team'] = $teamUserIds;
    return $matter;
}

/**
 * System Admin-only escape hatch. Skips the wall check but writes a distinct,
 * alertable audit entry. Caller is responsible for wrapping this in a
 * transaction if it needs to be atomic with something else.
 */
function custodia_bypass_ethical_wall(PDO $pdo, array $user, string $matterId, string $reason, string $ipAddress): array
{
    if ($user['role'] !== 'SYSTEM_ADMIN') {
        throw custodia_forbidden('Only a System Administrator may bypass an ethical wall.');
    }
    $stmt = $pdo->prepare('SELECT * FROM matters WHERE id = :id');
    $stmt->execute(['id' => $matterId]);
    $matter = $stmt->fetch();
    if (!$matter) {
        throw custodia_not_found('Matter not found.');
    }

    custodia_audit_record($pdo, [
        'actorId' => $user['id'],
        'actionType' => 'ETHICAL_WALL_BYPASS',
        'entityType' => 'MATTER',
        'entityId' => $matter['id'],
        'reason' => $reason,
        'ipAddress' => $ipAddress,
        'metadata' => ['managingPartnerId' => $matter['managing_partner_id']],
    ]);

    // Notification to matter.managing_partner_id is handled by
    // jobs/threat_detection_sweep.php (2026-09-06), which alerts on every
    // ETHICAL_WALL_BYPASS row within its 15-minute sweep window and routes
    // the alert to the managingPartnerId recorded in metadata above, in
    // addition to SYSTEM_ADMIN/RECORDS_MANAGER — not done synchronously
    // here, so there's up to one sweep interval of lag before the partner
    // is notified.
    return $matter;
}
