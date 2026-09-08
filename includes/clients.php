<?php
/**
 * Clients — top of the Client → Matter → File hierarchy, with a profile
 * page (client.php) showing bio data (name/email/phone/address/created) and
 * the client's matters — see custodia_client_detail() in includes/matters.php
 * (it lives there, not here, so it can reuse custodia_list_matters_for_user()'s
 * RBAC filtering without a circular require).
 *
 * Matters are opened against an existing client selected from a dropdown
 * (matters.php/matter.php), not free text. custodia_find_or_create_client()
 * still exists purely for seed.php's convenience — the matter create/edit
 * forms no longer call it.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/permissions.php';

function custodia_find_or_create_client(PDO $pdo, string $name): string
{
    $name = trim($name);

    $stmt = $pdo->prepare('SELECT id FROM clients WHERE name = :name');
    $stmt->execute(['name' => $name]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return $existing;
    }

    $id = custodia_uuid();
    $pdo->prepare('INSERT INTO clients (id, name) VALUES (:id, :name)')->execute(['id' => $id, 'name' => $name]);
    return $id;
}

/**
 * Firm-wide directory — every client is visible to every logged-in user
 * except Guest/Auditor, the same way practice areas or document types are
 * treated as non-confidential in themselves; only a matter's own contents
 * are gated by ethical walls/confidentiality, not the fact that a client
 * with a given name exists.
 */
function custodia_list_clients(PDO $pdo, array $user): array
{
    if ($user['role'] === 'GUEST_AUDITOR') {
        return [];
    }

    return $pdo->query(
        'SELECT c.*, COUNT(m.id) AS matter_count
         FROM clients c LEFT JOIN matters m ON m.client_id = c.id
         GROUP BY c.id
         ORDER BY c.is_active DESC, c.name ASC'
    )->fetchAll();
}

/** @param array{name:string,email?:string,phone?:string,address?:string} $fields */
function custodia_create_client(PDO $pdo, array $user, array $fields, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'create_clients');

    $name = trim($fields['name'] ?? '');
    $email = trim($fields['email'] ?? '') ?: null;
    $phone = trim($fields['phone'] ?? '') ?: null;
    $address = trim($fields['address'] ?? '') ?: null;

    if ($name === '') {
        throw custodia_bad_request('Client name is required.');
    }
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw custodia_bad_request("That doesn't look like a valid email address.");
    }

    $dupe = $pdo->prepare('SELECT id FROM clients WHERE name = :name');
    $dupe->execute(['name' => $name]);
    if ($dupe->fetch()) {
        throw custodia_bad_request('A client with that name already exists.');
    }

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare('INSERT INTO clients (id, name, email, phone, address) VALUES (:id, :name, :email, :phone, :address)')
            ->execute(['id' => $id, 'name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'CLIENT_CREATED', 'entityType' => 'CLIENT', 'entityId' => $id,
            'ipAddress' => $ipAddress, 'metadata' => ['name' => $name],
        ]);
        $pdo->commit();
        return ['id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Creates a client and, in the same transaction, an initial matter for it —
 * backs the "New Client" modal's optional "Also add a matter" section, so a
 * brand-new client doesn't have to be created and then re-opened just to
 * give it its first matter. Pass $matterFields as null to skip the matter
 * entirely (ordinary client-only creation, identical to
 * custodia_create_client() otherwise — that function is left as-is for its
 * other callers, e.g. custodia_bulk_import_clients()).
 *
 * @param array{name:string,email?:string,phone?:string,address?:string} $clientFields
 * @param array{matterNumber:string,practiceArea:string,managingPartnerId:string,confidentiality?:string}|null $matterFields
 */
function custodia_create_client_with_matter(PDO $pdo, array $user, array $clientFields, ?array $matterFields, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'create_clients');

    $name = trim($clientFields['name'] ?? '');
    $email = trim($clientFields['email'] ?? '') ?: null;
    $phone = trim($clientFields['phone'] ?? '') ?: null;
    $address = trim($clientFields['address'] ?? '') ?: null;

    if ($name === '') {
        throw custodia_bad_request('Client name is required.');
    }
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw custodia_bad_request("That doesn't look like a valid email address.");
    }

    $dupe = $pdo->prepare('SELECT id FROM clients WHERE name = :name');
    $dupe->execute(['name' => $name]);
    if ($dupe->fetch()) {
        throw custodia_bad_request('A client with that name already exists.');
    }

    $matterNumber = null;
    $practiceArea = null;
    $managingPartnerId = null;
    $confidentiality = 'STANDARD';
    if ($matterFields !== null) {
        // Same permission and validation rules as custodia_create_matter()
        // in includes/matters.php — duplicated rather than called directly
        // since that function opens/commits its own transaction and this
        // needs the client and matter inserts to succeed or fail together.
        custodia_assert_permission($pdo, $user, 'create_matters');

        $matterNumber = trim($matterFields['matterNumber'] ?? '');
        $practiceArea = trim($matterFields['practiceArea'] ?? '');
        $managingPartnerId = trim($matterFields['managingPartnerId'] ?? '');
        $confidentiality = $matterFields['confidentiality'] ?? 'STANDARD';

        if ($matterNumber === '' || $practiceArea === '' || $managingPartnerId === '') {
            throw custodia_bad_request('Matter number, practice area, and incharge are all required to add a matter.');
        }
        if (!in_array($confidentiality, ['STANDARD', 'RESTRICTED', 'PRIVILEGED'], true)) {
            throw custodia_bad_request('Invalid confidentiality tier.');
        }
        if ($user['role'] === 'PARTNER' && $managingPartnerId !== $user['id']) {
            throw custodia_forbidden('Partners may only open matters they manage themselves.');
        }

        $mpStmt = $pdo->prepare('SELECT id FROM users WHERE id = :id AND is_active = 1');
        $mpStmt->execute(['id' => $managingPartnerId]);
        if (!$mpStmt->fetch()) {
            throw custodia_bad_request('Incharge must be an active user.');
        }

        $matterDupe = $pdo->prepare('SELECT id FROM matters WHERE matter_number = :num');
        $matterDupe->execute(['num' => $matterNumber]);
        if ($matterDupe->fetch()) {
            throw custodia_bad_request('A matter with that matter number already exists.');
        }
    }

    $pdo->beginTransaction();
    try {
        $clientId = custodia_uuid();
        $pdo->prepare('INSERT INTO clients (id, name, email, phone, address) VALUES (:id, :name, :email, :phone, :address)')
            ->execute(['id' => $clientId, 'name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'CLIENT_CREATED', 'entityType' => 'CLIENT', 'entityId' => $clientId,
            'ipAddress' => $ipAddress, 'metadata' => ['name' => $name],
        ]);

        $matterId = null;
        if ($matterFields !== null) {
            $matterId = custodia_uuid();
            $pdo->prepare(
                'INSERT INTO matters (id, matter_number, client_id, practice_area, managing_partner_id, confidentiality)
                 VALUES (:id, :num, :client, :area, :mp, :conf)'
            )->execute([
                'id' => $matterId, 'num' => $matterNumber, 'client' => $clientId, 'area' => $practiceArea,
                'mp' => $managingPartnerId, 'conf' => $confidentiality,
            ]);
            $pdo->prepare('INSERT INTO matter_team_members (id, matter_id, user_id, role_on_matter) VALUES (:id, :mid, :uid, "Incharge")')
                ->execute(['id' => custodia_uuid(), 'mid' => $matterId, 'uid' => $managingPartnerId]);

            custodia_audit_record($pdo, [
                'actorId' => $user['id'], 'actionType' => 'MATTER_CREATED', 'entityType' => 'MATTER', 'entityId' => $matterId,
                'ipAddress' => $ipAddress,
            ]);
        }

        $pdo->commit();
        return ['id' => $clientId, 'matterId' => $matterId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** @param array{name:string,email?:string,phone?:string,address?:string} $fields */
function custodia_update_client(PDO $pdo, array $user, string $clientId, array $fields, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'edit_clients');

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute(['id' => $clientId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('Client not found.');
    }

    $name = trim($fields['name'] ?? '');
    $email = trim($fields['email'] ?? '') ?: null;
    $phone = trim($fields['phone'] ?? '') ?: null;
    $address = trim($fields['address'] ?? '') ?: null;

    if ($name === '') {
        throw custodia_bad_request('Client name is required.');
    }
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw custodia_bad_request("That doesn't look like a valid email address.");
    }

    $dupe = $pdo->prepare('SELECT id FROM clients WHERE name = :name AND id != :id');
    $dupe->execute(['name' => $name, 'id' => $clientId]);
    if ($dupe->fetch()) {
        throw custodia_bad_request('A client with that name already exists.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE clients SET name = :name, email = :email, phone = :phone, address = :address WHERE id = :id')
            ->execute(['name' => $name, 'email' => $email, 'phone' => $phone, 'address' => $address, 'id' => $clientId]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'CLIENT_UPDATED', 'entityType' => 'CLIENT', 'entityId' => $clientId,
            'ipAddress' => $ipAddress, 'metadata' => ['oldName' => $target['name'], 'newName' => $name],
        ]);
        $pdo->commit();
        return ['id' => $clientId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_deactivate_client(PDO $pdo, array $user, string $clientId, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'deactivate_clients');

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute(['id' => $clientId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('Client not found.');
    }
    if (!$target['is_active']) {
        throw custodia_bad_request('Client is already deactivated.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE clients SET is_active = 0, deactivated_at = NOW(6) WHERE id = :id')->execute(['id' => $clientId]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'CLIENT_DEACTIVATED', 'entityType' => 'CLIENT', 'entityId' => $clientId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $clientId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_reactivate_client(PDO $pdo, array $user, string $clientId, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'deactivate_clients');

    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = :id');
    $stmt->execute(['id' => $clientId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('Client not found.');
    }
    if ($target['is_active']) {
        throw custodia_bad_request('Client is already active.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE clients SET is_active = 1, deactivated_at = NULL WHERE id = :id')->execute(['id' => $clientId]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'CLIENT_REACTIVATED', 'entityType' => 'CLIENT', 'entityId' => $clientId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $clientId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Imports many clients from CSV rows (see custodia_read_csv_rows() —
 * expects columns "name", "email", "phone", "address"; only name is
 * required). Each row goes through the normal custodia_create_client(), one
 * row at a time, so a single bad row (missing name, duplicate, invalid
 * email) doesn't block the rest of the file — the result always separates
 * what succeeded from what didn't, with a reason per skip.
 *
 * @param array<int, array<string, string>> $rows
 */
function custodia_bulk_import_clients(PDO $pdo, array $actor, array $rows, string $ipAddress): array
{
    custodia_assert_permission($pdo, $actor, 'create_clients');

    $created = [];
    $skipped = [];
    foreach ($rows as $i => $row) {
        $rowNum = $i + 2; // +1 for 1-indexing, +1 for the header row
        $name = $row['name'] ?? '';
        try {
            $result = custodia_create_client($pdo, $actor, [
                'name' => $name,
                'email' => $row['email'] ?? '',
                'phone' => $row['phone'] ?? '',
                'address' => $row['address'] ?? '',
            ], $ipAddress);
            $created[] = ['row' => $rowNum, 'name' => $name, 'id' => $result['id']];
        } catch (CustodiaHttpException $e) {
            $skipped[] = ['row' => $rowNum, 'name' => $name, 'reason' => $e->getMessage()];
        }
    }

    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'CLIENTS_BULK_IMPORTED', 'entityType' => 'CLIENT', 'entityId' => 'bulk-import',
            'ipAddress' => $ipAddress, 'metadata' => ['createdCount' => count($created), 'skippedCount' => count($skipped)],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['created' => $created, 'skipped' => $skipped];
}
