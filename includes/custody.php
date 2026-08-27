<?php
/**
 * Physical custody workflow — PHP port of CustodyMovementsService (blueprint
 * Sections 4.1–4.4): check-out, check-in, custodian-approved transfer
 * requests, and the Records Manager/Admin override. Every state transition
 * here writes its audit entry inside the SAME PDO transaction as the
 * physical_files/custody_movements update — a movement is never "completed"
 * without its audit row existing.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/matter_access.php';
require_once __DIR__ . '/permissions.php';

function custodia_load_file_or_throw(PDO $pdo, string $fileId): array
{
    $stmt = $pdo->prepare('SELECT * FROM physical_files WHERE id = :id');
    $stmt->execute(['id' => $fileId]);
    $file = $stmt->fetch();
    if (!$file) {
        throw custodia_not_found('Physical file not found.');
    }
    return $file;
}

// ── 4.1 Check-out ──────────────────────────────────────────────────
function custodia_checkout(PDO $pdo, array $user, string $fileId, string $reason, string $dueBackAt, string $ipAddress): array
{
    $file = custodia_load_file_or_throw($pdo, $fileId);
    custodia_assert_matter_access($pdo, $user, $file['matter_id']);

    if ($file['status'] !== 'IN_REGISTRY') {
        throw custodia_bad_request("File is {$file['status']}, not IN_REGISTRY — it cannot be issued right now.");
    }

    $autoApproved = custodia_user_has_permission($pdo, $user, 'auto_approve_checkout');

    $pdo->beginTransaction();
    try {
        $movementId = custodia_uuid();
        $status = $autoApproved ? 'COMPLETED' : 'PENDING_APPROVAL';
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO custody_movements
             (id, physical_file_id, movement_type, to_user_id, requested_by_id, approved_by_id, reason, due_back_at, status, completed_at, requested_at)
             VALUES (:id, :file_id, "CHECK_OUT", :to_user_id, :requested_by_id, :approved_by_id, :reason, :due_back_at, :status, :completed_at, :requested_at)'
        );
        $stmt->execute([
            'id' => $movementId,
            'file_id' => $file['id'],
            'to_user_id' => $user['id'],
            'requested_by_id' => $user['id'],
            'approved_by_id' => $autoApproved ? $user['id'] : null,
            'reason' => $reason,
            'due_back_at' => $dueBackAt,
            'status' => $status,
            'completed_at' => $autoApproved ? $now : null,
            'requested_at' => $now,
        ]);

        if ($autoApproved) {
            $upd = $pdo->prepare('UPDATE physical_files SET status = "CHECKED_OUT", current_custodian_id = :uid, current_location_id = NULL WHERE id = :id');
            $upd->execute(['uid' => $user['id'], 'id' => $file['id']]);
        }

        custodia_audit_record($pdo, [
            'actorId' => $user['id'],
            'actionType' => $autoApproved ? 'CHECK_OUT' : 'CHECK_OUT_REQUESTED',
            'entityType' => 'PHYSICAL_FILE',
            'entityId' => $file['id'],
            'reason' => $reason,
            'ipAddress' => $ipAddress,
            'metadata' => ['movementId' => $movementId],
        ]);

        $pdo->commit();
        return ['id' => $movementId, 'status' => $status];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * A Records Manager/Partner/Admin approves a Paralegal's pending check-out
 * request — OR, for a TRANSFER, the file's current custodian approves someone
 * else's request to receive it. Those are different approval authorities
 * (a permission/managing-partner check vs. "are you the one holding the
 * file right now"), so they're branched below rather than sharing one gate.
 */
function custodia_approve_movement(PDO $pdo, array $user, string $movementId, string $ipAddress): array
{
    $stmt = $pdo->prepare(
        'SELECT cm.*, pf.matter_id AS pf_matter_id, pf.current_custodian_id AS pf_current_custodian_id
         FROM custody_movements cm
         JOIN physical_files pf ON pf.id = cm.physical_file_id WHERE cm.id = :id'
    );
    $stmt->execute(['id' => $movementId]);
    $movement = $stmt->fetch();
    if (!$movement) {
        throw custodia_not_found('Movement not found.');
    }
    if ($movement['status'] !== 'PENDING_APPROVAL') {
        throw custodia_bad_request("Movement is {$movement['status']}, not awaiting approval.");
    }

    $isTransfer = $movement['movement_type'] === 'TRANSFER';

    if ($isTransfer) {
        custodia_assert_matter_access($pdo, $user, $movement['pf_matter_id']);
        if ($movement['pf_current_custodian_id'] !== $user['id']) {
            throw custodia_forbidden('Only the current custodian may approve this transfer request.');
        }
    } else {
        $matter = custodia_assert_matter_access($pdo, $user, $movement['pf_matter_id']);
        // The managing-partner override is a data-driven fact (do they manage
        // THIS matter?), not a role privilege, so it stays outside the
        // permission matrix — a Partner without the firm-wide permission
        // below can still always approve requests on matters they personally
        // manage.
        $canApprove = (custodia_user_has_permission($pdo, $user, 'approve_custody_movements') && $user['role'] !== 'PARTNER')
            || ($user['role'] === 'PARTNER' && $matter['managing_partner_id'] === $user['id']);
        if (!$canApprove) {
            throw custodia_forbidden('You may not approve this request.');
        }
    }

    $pdo->beginTransaction();
    try {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');

        if ($isTransfer) {
            // The custodian's approval IS the handoff — the requester already
            // said they want it, so there's no separate confirmation step.
            $upd = $pdo->prepare('UPDATE custody_movements SET status = "COMPLETED", approved_by_id = :uid, completed_at = :now WHERE id = :id');
            $upd->execute(['uid' => $user['id'], 'now' => $now, 'id' => $movement['id']]);

            $updFile = $pdo->prepare('UPDATE physical_files SET current_custodian_id = :cust, current_location_id = NULL WHERE id = :id');
            $updFile->execute(['cust' => $movement['to_user_id'], 'id' => $movement['physical_file_id']]);
        } else {
            $upd = $pdo->prepare('UPDATE custody_movements SET status = "COMPLETED", approved_by_id = :uid, completed_at = :now WHERE id = :id');
            $upd->execute(['uid' => $user['id'], 'now' => $now, 'id' => $movement['id']]);

            $updFile = $pdo->prepare('UPDATE physical_files SET status = "CHECKED_OUT", current_custodian_id = :cust, current_location_id = NULL WHERE id = :id');
            $updFile->execute(['cust' => $movement['to_user_id'], 'id' => $movement['physical_file_id']]);
        }

        custodia_audit_record($pdo, [
            'actorId' => $user['id'],
            'actionType' => $isTransfer ? 'TRANSFER_APPROVED' : 'CHECK_OUT_APPROVED',
            'entityType' => 'PHYSICAL_FILE',
            'entityId' => $movement['physical_file_id'],
            'ipAddress' => $ipAddress,
            'metadata' => ['movementId' => $movement['id']],
        ]);

        $pdo->commit();
        return ['id' => $movement['id']];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_reject_movement(PDO $pdo, array $user, string $movementId, string $reason, string $ipAddress): array
{
    $stmt = $pdo->prepare(
        'SELECT cm.*, pf.current_custodian_id AS pf_current_custodian_id FROM custody_movements cm
         JOIN physical_files pf ON pf.id = cm.physical_file_id WHERE cm.id = :id'
    );
    $stmt->execute(['id' => $movementId]);
    $movement = $stmt->fetch();
    if (!$movement) {
        throw custodia_not_found('Movement not found.');
    }

    if ($movement['movement_type'] === 'TRANSFER') {
        // Same authority as approving one — only the current custodian may decline a request for their file.
        if ($movement['pf_current_custodian_id'] !== $user['id']) {
            throw custodia_forbidden('Only the current custodian may reject this transfer request.');
        }
    } else {
        custodia_assert_permission($pdo, $user, 'approve_custody_movements');
    }

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE custody_movements SET status = "REJECTED" WHERE id = :id');
        $upd->execute(['id' => $movement['id']]);

        // Nothing to revert on the file itself — a pending transfer request
        // never moves custody or status, so rejecting it just closes the request.

        custodia_audit_record($pdo, [
            'actorId' => $user['id'],
            'actionType' => "{$movement['movement_type']}_REJECTED",
            'entityType' => 'PHYSICAL_FILE',
            'entityId' => $movement['physical_file_id'],
            'reason' => $reason,
            'ipAddress' => $ipAddress,
            'metadata' => ['movementId' => $movement['id']],
        ]);

        $pdo->commit();
        return ['id' => $movement['id']];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── 4.2 Check-in (self) / 4.4 override ──────────────────────────────
function custodia_checkin(PDO $pdo, array $user, string $fileId, string $locationId, ?string $reason, string $ipAddress): array
{
    $file = custodia_load_file_or_throw($pdo, $fileId);
    custodia_assert_matter_access($pdo, $user, $file['matter_id']);

    if ($file['status'] !== 'CHECKED_OUT') {
        throw custodia_bad_request("File is {$file['status']}, not CHECKED_OUT.");
    }
    if ($file['current_custodian_id'] !== $user['id']) {
        throw custodia_forbidden('Only the current custodian may return this file — a Records Manager or Admin can use the override return instead.');
    }

    return custodia_complete_checkin($pdo, $user, $file, $locationId, $reason ?: 'Routine return', $ipAddress, false);
}

function custodia_override_checkin(PDO $pdo, array $user, string $fileId, string $locationId, string $reason, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'override_custody');
    $file = custodia_load_file_or_throw($pdo, $fileId);
    custodia_assert_matter_access($pdo, $user, $file['matter_id']);

    if ($file['status'] !== 'CHECKED_OUT') {
        throw custodia_bad_request("File is {$file['status']}, not CHECKED_OUT.");
    }

    return custodia_complete_checkin($pdo, $user, $file, $locationId, $reason, $ipAddress, true);
}

function custodia_complete_checkin(PDO $pdo, array $user, array $file, string $locationId, string $reason, string $ipAddress, bool $isOverride): array
{
    $pdo->beginTransaction();
    try {
        $movementId = custodia_uuid();
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO custody_movements
             (id, physical_file_id, movement_type, from_user_id, requested_by_id, approved_by_id, reason, status, is_override, completed_at, requested_at)
             VALUES (:id, :file_id, "CHECK_IN", :from_user_id, :requested_by_id, :approved_by_id, :reason, "COMPLETED", :is_override, :completed_at, :requested_at)'
        );
        $stmt->execute([
            'id' => $movementId,
            'file_id' => $file['id'],
            'from_user_id' => $file['current_custodian_id'],
            'requested_by_id' => $user['id'],
            'approved_by_id' => $user['id'],
            'reason' => $reason,
            'is_override' => $isOverride ? 1 : 0,
            'completed_at' => $now,
            'requested_at' => $now,
        ]);

        $upd = $pdo->prepare('UPDATE physical_files SET status = "IN_REGISTRY", current_custodian_id = NULL, current_location_id = :loc WHERE id = :id');
        $upd->execute(['loc' => $locationId, 'id' => $file['id']]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'],
            'actionType' => $isOverride ? 'OVERRIDE_CHECK_IN' : 'CHECK_IN',
            'entityType' => 'PHYSICAL_FILE',
            'entityId' => $file['id'],
            'reason' => $reason,
            'ipAddress' => $ipAddress,
            'metadata' => ['movementId' => $movementId, 'previousCustodianId' => $file['current_custodian_id']],
        ]);

        $pdo->commit();
        return ['id' => $movementId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── 4.3 Custody transfer, requested by a non-custodian ──────────────
/**
 * Anyone with matter access who does NOT currently hold the file can ask its
 * current custodian to hand it over. The file stays exactly as it is
 * (CHECKED_OUT, same custodian) until that custodian approves — see
 * custodia_approve_movement()'s TRANSFER branch, which is the only thing
 * that actually moves custody.
 */
function custodia_request_transfer(PDO $pdo, array $user, string $fileId, string $reason, string $ipAddress): array
{
    $file = custodia_load_file_or_throw($pdo, $fileId);
    custodia_assert_matter_access($pdo, $user, $file['matter_id']);

    if ($file['status'] !== 'CHECKED_OUT') {
        throw custodia_bad_request("File is {$file['status']} — a transfer can only be requested for a file that is currently issued to someone.");
    }
    if ($file['current_custodian_id'] === $user['id']) {
        throw custodia_bad_request('You already have custody of this file.');
    }

    $existing = $pdo->prepare(
        "SELECT 1 FROM custody_movements WHERE physical_file_id = :fid AND movement_type = 'TRANSFER' AND status = 'PENDING_APPROVAL' LIMIT 1"
    );
    $existing->execute(['fid' => $file['id']]);
    if ($existing->fetch()) {
        throw custodia_bad_request('A transfer request is already pending for this file.');
    }

    $pdo->beginTransaction();
    try {
        $movementId = custodia_uuid();
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s.u');

        $stmt = $pdo->prepare(
            'INSERT INTO custody_movements
             (id, physical_file_id, movement_type, from_user_id, to_user_id, requested_by_id, reason, status, requested_at)
             VALUES (:id, :file_id, "TRANSFER", :from_user_id, :to_user_id, :requested_by_id, :reason, "PENDING_APPROVAL", :requested_at)'
        );
        $stmt->execute([
            'id' => $movementId,
            'file_id' => $file['id'],
            'from_user_id' => $file['current_custodian_id'],
            'to_user_id' => $user['id'],
            'requested_by_id' => $user['id'],
            'reason' => $reason,
            'requested_at' => $now,
        ]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'],
            'actionType' => 'TRANSFER_REQUESTED',
            'entityType' => 'PHYSICAL_FILE',
            'entityId' => $file['id'],
            'reason' => $reason,
            'ipAddress' => $ipAddress,
            'metadata' => ['movementId' => $movementId, 'currentCustodianId' => $file['current_custodian_id']],
        ]);

        $pdo->commit();
        return ['id' => $movementId, 'status' => 'PENDING_APPROVAL'];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Movements awaiting THIS user's approval: check-out requests (if they hold
 * approve_custody_movements or manage the matter — resolved inside
 * custodia_approve_movement, so this list is intentionally a superset for
 * Partners, same as before) plus TRANSFER requests where they're the file's
 * current custodian, regardless of role or permissions.
 */
function custodia_list_pending_for_approver(PDO $pdo, array $user): array
{
    $canApproveCheckouts = custodia_user_has_permission($pdo, $user, 'approve_custody_movements');

    $stmt = $pdo->prepare(
        "SELECT cm.*, pf.barcode, pf.jacket_label, m.matter_number, c.name AS client_name,
                ru.full_name AS requested_by_name, tu.full_name AS to_user_name
         FROM custody_movements cm
         JOIN physical_files pf ON pf.id = cm.physical_file_id
         JOIN matters m ON m.id = pf.matter_id
         JOIN clients c ON c.id = m.client_id
         JOIN users ru ON ru.id = cm.requested_by_id
         LEFT JOIN users tu ON tu.id = cm.to_user_id
         WHERE cm.status = 'PENDING_APPROVAL'
           AND (
             (cm.movement_type = 'TRANSFER' AND pf.current_custodian_id = :uid)
             OR (cm.movement_type != 'TRANSFER' AND :can_approve = 1)
           )
         ORDER BY cm.requested_at ASC"
    );
    $stmt->execute(['uid' => $user['id'], 'can_approve' => $canApproveCheckouts ? 1 : 0]);
    return $stmt->fetchAll();
}
