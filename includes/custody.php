<?php
/**
 * Physical custody workflow — PHP port of CustodyMovementsService (blueprint
 * Sections 4.1–4.4): check-out, check-in, custodian-approved transfer
 * requests, and the Records Manager/Admin override. Every state transition
 * here writes its audit entry inside the SAME PDO transaction as the
 * physical_files/custody_movements update — a movement is never "completed"
 * without its audit row existing.
 *
 * NOTIFICATIONS (2026-09-09). Until this date none of these transitions told
 * anybody: a paralegal's check-out request, and more importantly a transfer
 * request — which only the file's CURRENT CUSTODIAN can approve — sat in the
 * Approvals queue until that person happened to look. Every blocking handoff
 * below now writes a notification inside the same transaction as its audit
 * entry, which also makes it an email (see custodia_notify_user()).
 * Recipient selection for the approval-side notifications is deliberately
 * narrower than the firm-wide permission — see includes/notification_recipients.php.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/matter_access.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/notification_recipients.php';

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

        if (!$autoApproved) {
            // The request blocks until somebody approves it, so somebody has
            // to be told it exists.
            $label = custodia_physical_file_label($pdo, $file['id']);
            custodia_notify_users(
                $pdo,
                custodia_matter_approver_ids($pdo, $file['matter_id'], $user['id']),
                'CHECK_OUT_REQUESTED',
                "{$user['full_name']} has requested a file: {$label}",
                $reason,
                'PHYSICAL_FILE',
                $file['id']
            );
        }

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
        //
        // Security review 2026-09-03, finding 2.2: the "manage THIS matter"
        // half of this used to also require role === 'PARTNER' literally,
        // but the Edit Matter Details "Incharge" field (matter.php) accepts
        // any active user regardless of role — a custom role like "Senior
        // Associate" or "Principal Associate" can genuinely be a matter's
        // managing_partner_id. That left a non-Partner incharge with no
        // floor at all: they could only approve if separately granted the
        // firm-wide approve_custody_movements permission, which grants
        // approval over every matter, not just their own. The floor now
        // checks the data fact (are they THIS matter's incharge?) instead
        // of the role label; the firm-wide-permission exclusion still
        // applies to literal Partners only, unchanged from before.
        $isMatterIncharge = $matter['managing_partner_id'] === $user['id'];
        $canApprove = (custodia_user_has_permission($pdo, $user, 'approve_custody_movements') && $user['role'] !== 'PARTNER')
            || $isMatterIncharge;
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

        $label = custodia_physical_file_label($pdo, $movement['physical_file_id']);
        if ($isTransfer) {
            custodia_notify_user(
                $pdo, $movement['to_user_id'], 'TRANSFER_APPROVED',
                "Transfer approved — you now hold {$label}",
                "{$user['full_name']} approved the transfer. The file is now recorded in your custody.",
                'PHYSICAL_FILE', $movement['physical_file_id']
            );
        } else {
            custodia_notify_user(
                $pdo, $movement['to_user_id'], 'CHECK_OUT_APPROVED',
                "Request approved — {$label}",
                "{$user['full_name']} approved your request. The file is now recorded in your custody.",
                'PHYSICAL_FILE', $movement['physical_file_id']
            );
        }

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

        // The reason matters more here than on an approval — a declined
        // request with no explanation just gets asked again.
        if (!empty($movement['requested_by_id'])) {
            $label = custodia_physical_file_label($pdo, $movement['physical_file_id']);
            custodia_notify_user(
                $pdo, $movement['requested_by_id'],
                $movement['movement_type'] === 'TRANSFER' ? 'TRANSFER_REJECTED' : 'CHECK_OUT_REJECTED',
                "Request declined — {$label}",
                "{$user['full_name']} declined the request. Reason: {$reason}",
                'PHYSICAL_FILE', $movement['physical_file_id']
            );
        }

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

        // An override takes a file out of someone's custody without their
        // involvement. That is a chain-of-custody exception, and the person
        // it happened to should not have to discover it from the audit log.
        if ($isOverride && !empty($file['current_custodian_id']) && $file['current_custodian_id'] !== $user['id']) {
            $label = custodia_physical_file_label($pdo, $file['id']);
            custodia_notify_user(
                $pdo, $file['current_custodian_id'], 'OVERRIDE_CHECK_IN',
                "Returned on your behalf — {$label}",
                "{$user['full_name']} returned this file to the registry on your behalf. Reason: {$reason}",
                'PHYSICAL_FILE', $file['id']
            );
        }

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

        // The current custodian is the ONLY person who can approve this
        // (see custodia_approve_movement()'s TRANSFER branch), so they are
        // the only person it makes sense to notify. Before this existed, a
        // transfer request was invisible to them until they happened to open
        // the Approvals page.
        if (!empty($file['current_custodian_id'])) {
            $label = custodia_physical_file_label($pdo, $file['id']);
            custodia_notify_user(
                $pdo, $file['current_custodian_id'], 'TRANSFER_REQUESTED',
                "{$user['full_name']} has requested a file you hold: {$label}",
                $reason,
                'PHYSICAL_FILE', $file['id']
            );
        }

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
