<?php
/** Confidential-matter access approval — PHP port of AccessRequestsService. */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/digital_documents.php';

function custodia_resolve_matter_id(PDO $pdo, string $entityType, string $entityId): string
{
    if ($entityType === 'MATTER') {
        return $entityId;
    }
    if ($entityType === 'PHYSICAL_FILE') {
        $stmt = $pdo->prepare('SELECT matter_id FROM physical_files WHERE id = :id');
        $stmt->execute(['id' => $entityId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw custodia_not_found('Physical file not found.');
        }
        return $row['matter_id'];
    }
    $stmt = $pdo->prepare('SELECT matter_id FROM digital_documents WHERE id = :id');
    $stmt->execute(['id' => $entityId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw custodia_not_found('Document not found.');
    }
    return $row['matter_id'];
}

/** Short human-readable label for a notification body — "M-2024-0187 (Northgate Logistics, Inc.)" or a document's title. Best-effort: falls back to the raw entity type if the row is somehow gone by the time this runs. */
function custodia_describe_access_entity(PDO $pdo, string $entityType, string $entityId): string
{
    if ($entityType === 'MATTER') {
        $stmt = $pdo->prepare('SELECT m.matter_number, c.name AS client_name FROM matters m JOIN clients c ON c.id = m.client_id WHERE m.id = :id');
        $stmt->execute(['id' => $entityId]);
        $row = $stmt->fetch();
        return $row ? "{$row['matter_number']} ({$row['client_name']})" : 'a matter';
    }
    if ($entityType === 'DIGITAL_DOCUMENT') {
        $stmt = $pdo->prepare('SELECT title FROM digital_documents WHERE id = :id');
        $stmt->execute(['id' => $entityId]);
        $row = $stmt->fetch();
        return $row ? $row['title'] : 'a document';
    }
    return 'a file';
}

function custodia_create_access_request(PDO $pdo, array $user, string $entityType, string $entityId, string $requestType, string $reason, string $ipAddress): array
{
    custodia_resolve_matter_id($pdo, $entityType, $entityId); // validates the entity exists

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare('INSERT INTO access_requests (id, requester_id, entity_type, entity_id, request_type, reason) VALUES (:id, :uid, :etype, :eid, :rtype, :reason)')
            ->execute(['id' => $id, 'uid' => $user['id'], 'etype' => $entityType, 'eid' => $entityId, 'rtype' => $requestType, 'reason' => $reason]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'ACCESS_REQUESTED', 'entityType' => $entityType, 'entityId' => $entityId,
            'reason' => $reason, 'ipAddress' => $ipAddress, 'metadata' => ['accessRequestId' => $id, 'requestType' => $requestType],
        ]);
        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** The requester's own most recent access request for this entity, if any — lets a page tell "never asked" from "already pending" from "already denied". */
function custodia_find_own_latest_access_request(PDO $pdo, string $userId, string $entityType, string $entityId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM access_requests
         WHERE requester_id = :uid AND entity_type = :etype AND entity_id = :eid
         ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute(['uid' => $userId, 'etype' => $entityType, 'eid' => $entityId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function custodia_list_pending_access_requests_for_approver(PDO $pdo, array $user): array
{
    if (!custodia_user_has_permission($pdo, $user, 'decide_access_requests')) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT ar.*, u.full_name AS requester_name, u.role AS requester_role
         FROM access_requests ar JOIN users u ON u.id = ar.requester_id
         WHERE ar.status = 'PENDING' ORDER BY ar.created_at ASC"
    );
    $stmt->execute();
    $pending = $stmt->fetchAll();

    if (in_array($user['role'], custodia_firm_wide_roles(), true)) {
        return $pending;
    }

    // Partner: only requests touching a matter they manage.
    $managedStmt = $pdo->prepare('SELECT id FROM matters WHERE managing_partner_id = :uid');
    $managedStmt->execute(['uid' => $user['id']]);
    $managedIds = array_flip(array_column($managedStmt->fetchAll(), 'id'));

    return array_values(array_filter($pending, function ($r) use ($pdo, $managedIds) {
        try {
            $matterId = custodia_resolve_matter_id($pdo, $r['entity_type'], $r['entity_id']);
        } catch (CustodiaHttpException $e) {
            return false; // entity was deleted since the request was made
        }
        return isset($managedIds[$matterId]);
    }));
}

/**
 * Same carve-out as custodia_approve_movement(): the managing-partner path is
 * a data-driven fact, not a role privilege, so it stays outside the
 * permission matrix. Shared by custodia_decide_access_request(),
 * custodia_admin_grant_matter_access(), and custodia_revoke_matter_access() —
 * all three are "who may decide who can see this matter" and must agree.
 */
function custodia_can_decide_matter_access(PDO $pdo, array $user, string $matterId): bool
{
    $matterStmt = $pdo->prepare('SELECT managing_partner_id FROM matters WHERE id = :id');
    $matterStmt->execute(['id' => $matterId]);
    $matter = $matterStmt->fetch();
    if (!$matter) {
        return false;
    }
    // Security review 2026-09-03, finding 2.2: the "manage THIS matter"
    // half of this used to also require role === 'PARTNER' literally, but
    // the Edit Matter Details "Incharge" field (matter.php) accepts any
    // active user regardless of role — a custom role like "Senior
    // Associate" or "Principal Associate" can genuinely be a matter's
    // managing_partner_id. That left a non-Partner incharge with no floor
    // at all: they could only decide access requests if separately granted
    // the firm-wide decide_access_requests permission, which grants that
    // power over every matter, not just their own. The floor now checks
    // the data fact (are they THIS matter's incharge?) instead of the role
    // label; the firm-wide-permission exclusion still applies to literal
    // Partners only, unchanged from before.
    $isMatterIncharge = $matter['managing_partner_id'] === $user['id'];
    return (custodia_user_has_permission($pdo, $user, 'decide_access_requests') && $user['role'] !== 'PARTNER')
        || $isMatterIncharge;
}

/** @param ?int $expiresInHours Only meaningful when $approve is true; null means the grant never expires. */
function custodia_decide_access_request(PDO $pdo, array $user, string $requestId, bool $approve, ?string $reason, string $ipAddress, ?int $expiresInHours = null): array
{
    $stmt = $pdo->prepare('SELECT * FROM access_requests WHERE id = :id');
    $stmt->execute(['id' => $requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw custodia_not_found('Access request not found.');
    }
    if ($request['status'] !== 'PENDING') {
        throw custodia_forbidden('This request has already been decided.');
    }

    $matterId = custodia_resolve_matter_id($pdo, $request['entity_type'], $request['entity_id']);
    if (!custodia_can_decide_matter_access($pdo, $user, $matterId)) {
        throw custodia_forbidden('You may not decide this request.');
    }

    $pdo->beginTransaction();
    try {
        $status = $approve ? 'APPROVED' : 'DENIED';
        // The expiry is computed by MySQL's own clock (DATE_ADD from NOW(6)),
        // not PHP's — the two processes' configured timezones don't
        // necessarily agree (they don't in this environment: PHP defaults to
        // Europe/Berlin while the OS/MySQL run Africa/Nairobi), and every
        // later "is this grant still active?" check compares against
        // MySQL's NOW(6) too, so the expiry has to be laid down on that same
        // clock or the two would silently drift apart by the timezone gap.
        $expiresExpr = ($approve && $expiresInHours !== null) ? "DATE_ADD(NOW(6), INTERVAL {$expiresInHours} HOUR)" : 'NULL';
        $pdo->prepare("UPDATE access_requests SET status = :status, approver_id = :uid, decided_at = NOW(6), expires_at = {$expiresExpr} WHERE id = :id")
            ->execute(['status' => $status, 'uid' => $user['id'], 'id' => $requestId]);

        $expiresAt = $pdo->query("SELECT expires_at FROM access_requests WHERE id = " . $pdo->quote($requestId))->fetchColumn();

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => $approve ? 'ACCESS_APPROVED' : 'ACCESS_DENIED',
            'entityType' => $request['entity_type'], 'entityId' => $request['entity_id'], 'reason' => $reason,
            'ipAddress' => $ipAddress, 'metadata' => ['accessRequestId' => $requestId, 'requesterId' => $request['requester_id'], 'expiresAt' => $expiresAt],
        ]);

        $entityLabel = custodia_describe_access_entity($pdo, $request['entity_type'], $request['entity_id']);
        custodia_notify_user(
            $pdo, $request['requester_id'],
            $approve ? 'ACCESS_REQUEST_APPROVED' : 'ACCESS_REQUEST_DENIED',
            $approve ? "Access request approved: {$entityLabel}" : "Access request denied: {$entityLabel}",
            $approve ? ($expiresAt ? "Access expires " . $expiresAt . '.' : 'Access does not expire.') : $reason,
            $request['entity_type'], $request['entity_id']
        );

        $pdo->commit();
        return ['id' => $requestId, 'status' => $status];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Admin-initiated direct grant — same effect as a self-service access
 * request that's already been approved, minus the PENDING step. Reuses the
 * access_requests table (and therefore custodia_assert_matter_access()'s
 * existing hasApprovedAccess check) instead of a separate grants table.
 */
function custodia_admin_grant_matter_access(PDO $pdo, array $actor, string $userId, string $matterId, string $reason, string $ipAddress): array
{
    if (!custodia_can_decide_matter_access($pdo, $actor, $matterId)) {
        throw custodia_forbidden('You may not grant access to this matter.');
    }

    $targetStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id');
    $targetStmt->execute(['id' => $userId]);
    if (!$targetStmt->fetch()) {
        throw custodia_not_found('User not found.');
    }

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare(
            'INSERT INTO access_requests (id, requester_id, entity_type, entity_id, request_type, reason, status, approver_id, decided_at)
             VALUES (:id, :uid, "MATTER", :mid, "ADMIN_GRANT", :reason, "APPROVED", :approver, NOW(6))'
        )->execute(['id' => $id, 'uid' => $userId, 'mid' => $matterId, 'reason' => $reason, 'approver' => $actor['id']]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'ACCESS_GRANTED_BY_ADMIN', 'entityType' => 'MATTER', 'entityId' => $matterId,
            'reason' => $reason, 'ipAddress' => $ipAddress, 'metadata' => ['accessRequestId' => $id, 'granteeId' => $userId],
        ]);

        $entityLabel = custodia_describe_access_entity($pdo, 'MATTER', $matterId);
        custodia_notify_user($pdo, $userId, 'ACCESS_GRANTED', "You were granted access: {$entityLabel}", $reason, 'MATTER', $matterId);

        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * "Share Document" — deliberately more open than custodia_admin_grant_document_access():
 * anyone who can already view the document may share it with a colleague
 * (matches the old, now-removed external Share Link button's own permission
 * model — any matter-accessing user could generate one), it just now only
 * targets an existing internal account, chosen from a dropdown instead of a
 * free-text email. Recipient must already have ordinary matter access —
 * this only ever ADDS the document-level view grant a Protected document
 * additionally requires, it never grants matter access itself (that's a
 * bigger decision than "share one document", left to the Team & Access tab).
 */
function custodia_share_document_with_user(PDO $pdo, array $actor, string $documentId, string $targetUserId, ?int $expiresInHours, string $ipAddress): array
{
    $docStmt = $pdo->prepare('SELECT * FROM digital_documents WHERE id = :id');
    $docStmt->execute(['id' => $documentId]);
    $doc = $docStmt->fetch();
    if (!$doc) {
        throw custodia_not_found('Document not found.');
    }
    // Actor must already be able to view this document themselves.
    custodia_assert_document_view_access($pdo, $actor, $doc);

    $targetStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $targetStmt->execute(['id' => $targetUserId]);
    $target = $targetStmt->fetch();
    if (!$target) {
        throw custodia_not_found('User not found.');
    }

    try {
        custodia_assert_matter_access($pdo, $target, $doc['matter_id']);
    } catch (CustodiaHttpException $e) {
        throw custodia_bad_request("{$target['full_name']} doesn't have access to this matter yet — add them to the matter's team or grant matter access first, then share the document.");
    }

    $entityLabel = custodia_describe_access_entity($pdo, 'DIGITAL_DOCUMENT', $documentId);

    // Every share — protected doc or not — writes the same 'SHARED' grant
    // row. custodia_document_actor_is_restricted() keys off exactly this row
    // to cap the recipient to a watermarked, no-download/no-print view of
    // *this* document with no upload/reshare/edit-profile, regardless of
    // whatever broader matter access they already hold. Inserts the same
    // shape of row custodia_admin_grant_document_access() does — deliberately
    // NOT calling that function, since its custodia_can_decide_matter_access()
    // gate is the wrong permission model here (this feature is intentionally
    // open to anyone who can view the document, not just those who can decide
    // access requests).
    $reason = "Shared by {$actor['full_name']}.";
    $expiresExpr = $expiresInHours !== null ? "DATE_ADD(NOW(6), INTERVAL {$expiresInHours} HOUR)" : 'NULL';

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare(
            "INSERT INTO access_requests (id, requester_id, entity_type, entity_id, request_type, reason, status, approver_id, decided_at, expires_at)
             VALUES (:id, :uid, 'DIGITAL_DOCUMENT', :did, 'SHARED', :reason, 'APPROVED', :approver, NOW(6), {$expiresExpr})"
        )->execute(['id' => $id, 'uid' => $targetUserId, 'did' => $documentId, 'reason' => $reason, 'approver' => $actor['id']]);

        $expiresAt = $pdo->query('SELECT expires_at FROM access_requests WHERE id = ' . $pdo->quote($id))->fetchColumn();

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'DOCUMENT_SHARED', 'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $documentId,
            'reason' => $reason, 'ipAddress' => $ipAddress, 'metadata' => ['accessRequestId' => $id, 'granteeId' => $targetUserId, 'expiresAt' => $expiresAt],
        ]);

        $body = $expiresAt ? "Access expires {$expiresAt}." : null;
        custodia_notify_user($pdo, $targetUserId, 'DOCUMENT_SHARED', "{$actor['full_name']} shared a document with you: {$entityLabel}", $body, 'DIGITAL_DOCUMENT', $documentId);

        $pdo->commit();
        return ['granted' => true, 'id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Same shape as custodia_admin_grant_matter_access(), targeting a specific
 * Protected document instead of a whole matter — the resulting APPROVED row
 * is exactly what custodia_user_has_active_document_view_grant() checks.
 * custodia_revoke_matter_access() below already handles revoking either kind
 * (it resolves the governing matter generically), so there's no separate
 * revoke-document function.
 */
function custodia_admin_grant_document_access(PDO $pdo, array $actor, string $userId, string $documentId, string $reason, ?int $expiresInHours, string $ipAddress): array
{
    $matterId = custodia_resolve_matter_id($pdo, 'DIGITAL_DOCUMENT', $documentId);
    if (!custodia_can_decide_matter_access($pdo, $actor, $matterId)) {
        throw custodia_forbidden('You may not grant access to this document.');
    }

    $targetStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id');
    $targetStmt->execute(['id' => $userId]);
    if (!$targetStmt->fetch()) {
        throw custodia_not_found('User not found.');
    }

    // Computed on MySQL's own clock, not PHP's — see the identical comment in
    // custodia_decide_access_request().
    $expiresExpr = $expiresInHours !== null ? "DATE_ADD(NOW(6), INTERVAL {$expiresInHours} HOUR)" : 'NULL';

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare(
            "INSERT INTO access_requests (id, requester_id, entity_type, entity_id, request_type, reason, status, approver_id, decided_at, expires_at)
             VALUES (:id, :uid, 'DIGITAL_DOCUMENT', :did, 'ADMIN_GRANT', :reason, 'APPROVED', :approver, NOW(6), {$expiresExpr})"
        )->execute(['id' => $id, 'uid' => $userId, 'did' => $documentId, 'reason' => $reason, 'approver' => $actor['id']]);

        $expiresAt = $pdo->query('SELECT expires_at FROM access_requests WHERE id = ' . $pdo->quote($id))->fetchColumn();

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'ACCESS_GRANTED_BY_ADMIN', 'entityType' => 'DIGITAL_DOCUMENT', 'entityId' => $documentId,
            'reason' => $reason, 'ipAddress' => $ipAddress, 'metadata' => ['accessRequestId' => $id, 'granteeId' => $userId, 'expiresAt' => $expiresAt],
        ]);

        $entityLabel = custodia_describe_access_entity($pdo, 'DIGITAL_DOCUMENT', $documentId);
        $body = $reason . ($expiresAt ? " (access expires {$expiresAt})" : '');
        custodia_notify_user($pdo, $userId, 'ACCESS_GRANTED', "You were granted access: {$entityLabel}", $body, 'DIGITAL_DOCUMENT', $documentId);

        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Approved individual view grants for this document — mirrors custodia_list_matter_individual_grants(). */
function custodia_list_document_individual_grants(PDO $pdo, string $documentId): array
{
    $stmt = $pdo->prepare(
        "SELECT ar.id, ar.reason, ar.decided_at, ar.expires_at, u.full_name AS grantee_name, a.full_name AS approver_name
         FROM access_requests ar
         JOIN users u ON u.id = ar.requester_id
         JOIN users a ON a.id = ar.approver_id
         WHERE ar.entity_type = 'DIGITAL_DOCUMENT' AND ar.entity_id = :did AND ar.status = 'APPROVED'
           AND (ar.expires_at IS NULL OR ar.expires_at > NOW(6))
         ORDER BY ar.decided_at DESC"
    );
    $stmt->execute(['did' => $documentId]);
    return $stmt->fetchAll();
}

function custodia_revoke_matter_access(PDO $pdo, array $actor, string $requestId, string $ipAddress): array
{
    $stmt = $pdo->prepare('SELECT * FROM access_requests WHERE id = :id');
    $stmt->execute(['id' => $requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw custodia_not_found('Access grant not found.');
    }
    if ($request['status'] !== 'APPROVED') {
        throw custodia_bad_request("This request is {$request['status']}, not an active grant.");
    }

    $matterId = custodia_resolve_matter_id($pdo, $request['entity_type'], $request['entity_id']);
    if (!custodia_can_decide_matter_access($pdo, $actor, $matterId)) {
        throw custodia_forbidden('You may not revoke access to this matter.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE access_requests SET status = "REVOKED" WHERE id = :id')->execute(['id' => $requestId]);

        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'ACCESS_GRANT_REVOKED', 'entityType' => 'MATTER', 'entityId' => $matterId,
            'ipAddress' => $ipAddress, 'metadata' => ['accessRequestId' => $requestId, 'granteeId' => $request['requester_id']],
        ]);
        $pdo->commit();
        return ['id' => $requestId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Everything individually (not via a Practice Group) shared with this user —
 * an approved, unexpired access_requests row in their own name, for either a
 * whole matter or a single document. Powers shared_with_me.php.
 * @return array{matters: array, documents: array}
 */
function custodia_list_shared_with_me(PDO $pdo, array $user): array
{
    $matterStmt = $pdo->prepare(
        "SELECT ar.id, ar.reason, ar.decided_at, ar.expires_at, m.id AS matter_id, m.matter_number, c.name AS client_name, a.full_name AS approver_name
         FROM access_requests ar
         JOIN matters m ON m.id = ar.entity_id
         JOIN clients c ON c.id = m.client_id
         JOIN users a ON a.id = ar.approver_id
         WHERE ar.requester_id = :uid AND ar.entity_type = 'MATTER' AND ar.status = 'APPROVED'
           AND (ar.expires_at IS NULL OR ar.expires_at > NOW(6))
         ORDER BY ar.decided_at DESC"
    );
    $matterStmt->execute(['uid' => $user['id']]);

    $docStmt = $pdo->prepare(
        "SELECT ar.id, ar.reason, ar.decided_at, ar.expires_at, dd.id AS document_id, dd.title, dd.doc_number, m.id AS matter_id, m.matter_number, a.full_name AS approver_name
         FROM access_requests ar
         JOIN digital_documents dd ON dd.id = ar.entity_id
         JOIN matters m ON m.id = dd.matter_id
         JOIN users a ON a.id = ar.approver_id
         WHERE ar.requester_id = :uid AND ar.entity_type = 'DIGITAL_DOCUMENT' AND ar.status = 'APPROVED'
           AND (ar.expires_at IS NULL OR ar.expires_at > NOW(6))
         ORDER BY ar.decided_at DESC"
    );
    $docStmt->execute(['uid' => $user['id']]);

    return ['matters' => $matterStmt->fetchAll(), 'documents' => $docStmt->fetchAll()];
}

/** Approved individual access grants for this matter — shown on matter.php's Team & Access tab. */
function custodia_list_matter_individual_grants(PDO $pdo, string $matterId): array
{
    $stmt = $pdo->prepare(
        "SELECT ar.id, ar.reason, ar.decided_at, ar.request_type, u.full_name AS grantee_name, a.full_name AS approver_name
         FROM access_requests ar
         JOIN users u ON u.id = ar.requester_id
         JOIN users a ON a.id = ar.approver_id
         WHERE ar.entity_type = 'MATTER' AND ar.entity_id = :mid AND ar.status = 'APPROVED'
         ORDER BY ar.decided_at DESC"
    );
    $stmt->execute(['mid' => $matterId]);
    return $stmt->fetchAll();
}
