<?php
/** Matters — PHP port of MattersService. */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/matter_access.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/clients.php';

function custodia_firm_wide_reach_matters(PDO $pdo, array $user): array
{
    // firm-wide roles see everything not walled from them.
    $stmt = $pdo->prepare(
        "SELECT m.*, c.name AS client_name FROM matters m
         JOIN clients c ON c.id = m.client_id
         WHERE m.id NOT IN (SELECT matter_id FROM ethical_walls WHERE user_id = :uid)
         ORDER BY m.open_date DESC"
    );
    $stmt->execute(['uid' => $user['id']]);
    return $stmt->fetchAll();
}

/** RBAC matrix: "View matter list" — All / All / Assigned + practice area / Assigned only / Assigned only / None. */
function custodia_list_matters_for_user(PDO $pdo, array $user): array
{
    if ($user['role'] === 'GUEST_AUDITOR') {
        return [];
    }

    if (in_array($user['role'], custodia_firm_wide_roles(), true)) {
        return custodia_firm_wide_reach_matters($pdo, $user);
    }

    $approvedStmt = $pdo->prepare("SELECT entity_id FROM access_requests WHERE requester_id = :uid AND entity_type = 'MATTER' AND status = 'APPROVED'");
    $approvedStmt->execute(['uid' => $user['id']]);
    $approvedIds = array_column($approvedStmt->fetchAll(), 'entity_id');

    $params = ['uid' => $user['id']];
    $orClauses = ['m.id IN (SELECT matter_id FROM matter_team_members WHERE user_id = :uid2)'];
    $params['uid2'] = $user['id'];

    if (!empty($approvedIds)) {
        $placeholders = [];
        foreach ($approvedIds as $i => $id) {
            $key = "approved{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $id;
        }
        $orClauses[] = 'm.id IN (' . implode(',', $placeholders) . ')';
    }

    // Matters reachable via a Practice Group matter-access grant — same
    // standing as the approved-access-request clause above.
    $orClauses[] = "m.id IN (SELECT g.matter_id FROM practice_group_matter_grants g
                             JOIN practice_group_members pgm ON pgm.practice_group_id = g.practice_group_id
                             WHERE pgm.user_id = :uid3)";
    $params['uid3'] = $user['id'];

    // Partners additionally see matters in any of their own practice groups.
    if ($user['role'] === 'PARTNER' && !empty($user['practice_group_names'])) {
        $paPlaceholders = [];
        foreach ($user['practice_group_names'] as $i => $pa) {
            $key = "pa{$i}";
            $paPlaceholders[] = ":{$key}";
            $params[$key] = $pa;
        }
        $orClauses[] = 'm.practice_area IN (' . implode(',', $paPlaceholders) . ')';
    }

    $sql = "SELECT m.*, c.name AS client_name FROM matters m
            JOIN clients c ON c.id = m.client_id
            WHERE m.id NOT IN (SELECT matter_id FROM ethical_walls WHERE user_id = :uid)
            AND (" . implode(' OR ', $orClauses) . ")
            ORDER BY m.open_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function custodia_matter_detail(PDO $pdo, array $user, string $matterId, string $ipAddress): array
{
    $matter = custodia_assert_matter_access($pdo, $user, $matterId);

    $clientStmt = $pdo->prepare('SELECT name FROM clients WHERE id = :id');
    $clientStmt->execute(['id' => $matter['client_id']]);
    $matter['client_name'] = $clientStmt->fetchColumn();

    $mpStmt = $pdo->prepare('SELECT id, full_name FROM users WHERE id = :id');
    $mpStmt->execute(['id' => $matter['managing_partner_id']]);
    $matter['managing_partner'] = $mpStmt->fetch();

    $teamStmt = $pdo->prepare(
        'SELECT mtm.id, mtm.role_on_matter, u.id AS user_id, u.full_name, u.role
         FROM matter_team_members mtm JOIN users u ON u.id = mtm.user_id WHERE mtm.matter_id = :id'
    );
    $teamStmt->execute(['id' => $matterId]);
    $matter['team_detail'] = $teamStmt->fetchAll();

    $wallStmt = $pdo->prepare(
        'SELECT ew.id, ew.reason, u.id AS user_id, u.full_name FROM ethical_walls ew JOIN users u ON u.id = ew.user_id WHERE ew.matter_id = :id'
    );
    $wallStmt->execute(['id' => $matterId]);
    $matter['ethical_walls_detail'] = $wallStmt->fetchAll();

    $countStmt = $pdo->prepare('SELECT (SELECT COUNT(*) FROM physical_files WHERE matter_id = :id1) AS physical_count, (SELECT COUNT(*) FROM digital_documents WHERE matter_id = :id2) AS document_count');
    $countStmt->execute(['id1' => $matterId, 'id2' => $matterId]);
    $matter['counts'] = $countStmt->fetch();

    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'VIEW', 'entityType' => 'MATTER', 'entityId' => $matter['id'], 'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $matter;
}

/**
 * Client profile page data: the client's bio fields plus the subset of its
 * matters this user can actually see — reuses custodia_list_matters_for_user()
 * rather than a plain "WHERE client_id = X" query so ethical walls/
 * assignment scoping apply here exactly as they do on the main Matters list.
 */
function custodia_client_detail(PDO $pdo, array $user, string $clientId, string $ipAddress): array
{
    if ($user['role'] === 'GUEST_AUDITOR') {
        throw custodia_forbidden('Guests may not view client profiles.');
    }

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute(['id' => $clientId]);
    $client = $stmt->fetch();
    if (!$client) {
        throw custodia_not_found('Client not found.');
    }

    $accessibleMatters = custodia_list_matters_for_user($pdo, $user);
    $client['matters'] = array_values(array_filter($accessibleMatters, fn ($m) => $m['client_id'] === $clientId));

    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'VIEW', 'entityType' => 'CLIENT', 'entityId' => $clientId, 'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $client;
}

function custodia_create_matter(PDO $pdo, array $user, array $dto, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'create_matters');
    if ($user['role'] === 'PARTNER' && $dto['managingPartnerId'] !== $user['id']) {
        throw custodia_forbidden('Partners may only open matters they manage themselves.');
    }

    $clientStmt = $pdo->prepare('SELECT id FROM clients WHERE id = :id');
    $clientStmt->execute(['id' => $dto['clientId']]);
    if (!$clientStmt->fetch()) {
        throw custodia_bad_request('Select a client for this matter.');
    }

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare(
            'INSERT INTO matters (id, matter_number, client_id, practice_area, managing_partner_id, confidentiality)
             VALUES (:id, :num, :client, :area, :mp, :conf)'
        )->execute([
            'id' => $id, 'num' => $dto['matterNumber'], 'client' => $dto['clientId'], 'area' => $dto['practiceArea'],
            'mp' => $dto['managingPartnerId'], 'conf' => $dto['confidentiality'] ?? 'STANDARD',
        ]);
        $pdo->prepare('INSERT INTO matter_team_members (id, matter_id, user_id, role_on_matter) VALUES (:id, :mid, :uid, "Incharge")')
            ->execute(['id' => custodia_uuid(), 'mid' => $id, 'uid' => $dto['managingPartnerId']]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'MATTER_CREATED', 'entityType' => 'MATTER', 'entityId' => $id, 'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** @param array{matterNumber:string,clientId:string,practiceArea:string,managingPartnerId:string,status:string,confidentiality:string,closeDate?:string} $fields */
function custodia_update_matter(PDO $pdo, array $user, string $matterId, array $fields, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'edit_matters');

    $stmt = $pdo->prepare('SELECT * FROM matters WHERE id = :id');
    $stmt->execute(['id' => $matterId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('Matter not found.');
    }

    $matterNumber = trim($fields['matterNumber'] ?? '');
    $clientId = $fields['clientId'] ?? '';
    $practiceArea = trim($fields['practiceArea'] ?? '');
    $managingPartnerId = $fields['managingPartnerId'] ?? '';
    $status = $fields['status'] ?? '';
    $confidentiality = $fields['confidentiality'] ?? '';
    $closeDate = trim($fields['closeDate'] ?? '') ?: null;

    if ($matterNumber === '' || $clientId === '' || $practiceArea === '') {
        throw custodia_bad_request('Matter number, client, and practice area are all required.');
    }
    if (!in_array($status, ['ACTIVE', 'ON_HOLD', 'CLOSED', 'ARCHIVED'], true)) {
        throw custodia_bad_request('Invalid status.');
    }
    if (!in_array($confidentiality, ['STANDARD', 'RESTRICTED', 'PRIVILEGED'], true)) {
        throw custodia_bad_request('Invalid confidentiality tier.');
    }

    $clientStmt = $pdo->prepare('SELECT id FROM clients WHERE id = :id');
    $clientStmt->execute(['id' => $clientId]);
    if (!$clientStmt->fetch()) {
        throw custodia_bad_request('Select a client for this matter.');
    }

    $mpStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND is_active = 1");
    $mpStmt->execute(['id' => $managingPartnerId]);
    if (!$mpStmt->fetch()) {
        throw custodia_bad_request('Incharge must be an active user.');
    }

    $dupe = $pdo->prepare('SELECT id FROM matters WHERE matter_number = :num AND id != :id');
    $dupe->execute(['num' => $matterNumber, 'id' => $matterId]);
    if ($dupe->fetch()) {
        throw custodia_bad_request('Another matter already uses that matter number.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE matters SET matter_number = :num, client_id = :client, practice_area = :area,
             managing_partner_id = :mp, status = :status, confidentiality = :conf, close_date = :close
             WHERE id = :id'
        )->execute([
            'num' => $matterNumber, 'client' => $clientId, 'area' => $practiceArea, 'mp' => $managingPartnerId,
            'status' => $status, 'conf' => $confidentiality, 'close' => $closeDate, 'id' => $matterId,
        ]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'MATTER_UPDATED', 'entityType' => 'MATTER', 'entityId' => $matterId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $matterId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** One-click "close this matter" shortcut, gated separately from full edit_matters — see includes/permissions.php's deactivate_matters entry. */
function custodia_deactivate_matter(PDO $pdo, array $user, string $matterId, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'deactivate_matters');

    $stmt = $pdo->prepare('SELECT * FROM matters WHERE id = :id');
    $stmt->execute(['id' => $matterId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('Matter not found.');
    }
    if ($target['status'] === 'CLOSED') {
        throw custodia_bad_request('Matter is already closed.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE matters SET status = \'CLOSED\', close_date = NOW(6) WHERE id = :id')->execute(['id' => $matterId]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'MATTER_DEACTIVATED', 'entityType' => 'MATTER', 'entityId' => $matterId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $matterId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_reactivate_matter(PDO $pdo, array $user, string $matterId, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'deactivate_matters');

    $stmt = $pdo->prepare('SELECT * FROM matters WHERE id = :id');
    $stmt->execute(['id' => $matterId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('Matter not found.');
    }
    if ($target['status'] === 'ACTIVE') {
        throw custodia_bad_request('Matter is already active.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE matters SET status = \'ACTIVE\', close_date = NULL WHERE id = :id')->execute(['id' => $matterId]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'MATTER_REACTIVATED', 'entityType' => 'MATTER', 'entityId' => $matterId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $matterId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_add_team_member(PDO $pdo, array $user, string $matterId, string $userId, string $roleOnMatter, string $ipAddress): array
{
    $matter = custodia_assert_matter_access($pdo, $user, $matterId);
    $canManageTeam = in_array($user['role'], custodia_firm_wide_roles(), true) || $matter['managing_partner_id'] === $user['id'];
    if (!$canManageTeam) {
        throw custodia_forbidden('Only the incharge, Records Manager, or Admin may edit the matter team.');
    }

    $pdo->beginTransaction();
    try {
        $memberId = custodia_uuid();
        $pdo->prepare('INSERT INTO matter_team_members (id, matter_id, user_id, role_on_matter) VALUES (:id, :mid, :uid, :role)')
            ->execute(['id' => $memberId, 'mid' => $matterId, 'uid' => $userId, 'role' => $roleOnMatter]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'TEAM_MEMBER_ADDED', 'entityType' => 'MATTER', 'entityId' => $matterId,
            'ipAddress' => $ipAddress, 'metadata' => ['addedUserId' => $userId, 'roleOnMatter' => $roleOnMatter],
        ]);
        $pdo->commit();
        return ['id' => $memberId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_create_ethical_wall(PDO $pdo, array $user, string $matterId, string $userId, string $reason, string $ipAddress): array
{
    if (!in_array($user['role'], custodia_firm_wide_roles(), true)) {
        throw custodia_forbidden('Only Records Manager or Admin may configure ethical walls.');
    }
    $pdo->beginTransaction();
    try {
        $wallId = custodia_uuid();
        $pdo->prepare('INSERT INTO ethical_walls (id, matter_id, user_id, reason, created_by) VALUES (:id, :mid, :uid, :reason, :created_by)')
            ->execute(['id' => $wallId, 'mid' => $matterId, 'uid' => $userId, 'reason' => $reason, 'created_by' => $user['id']]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'ETHICAL_WALL_CREATED', 'entityType' => 'MATTER', 'entityId' => $matterId,
            'reason' => $reason, 'ipAddress' => $ipAddress, 'metadata' => ['walledUserId' => $userId],
        ]);
        $pdo->commit();
        return ['id' => $wallId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
