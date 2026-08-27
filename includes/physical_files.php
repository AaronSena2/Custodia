<?php
/** Physical file registry queries — PHP port of PhysicalFilesService. */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/matter_access.php';
require_once __DIR__ . '/matters.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

/**
 * Powers the Dashboard's "Overdue Returns" panel. A file is overdue when its
 * most recent completed CHECK_OUT movement's due_back_at has passed and the
 * file is still CHECKED_OUT.
 */
function custodia_list_overdue_files(PDO $pdo, array $user): array
{
    if ($user['role'] === 'GUEST_AUDITOR') {
        return [];
    }

    // Reuse the same "which matters can this user see" logic as the matters list,
    // then constrain the overdue-files query to that set unless firm-wide.
    $accessibleMatterIds = null;
    if (!in_array($user['role'], custodia_firm_wide_roles(), true)) {
        $matters = custodia_list_matters_for_user($pdo, $user);
        $accessibleMatterIds = array_column($matters, 'id');
        if (empty($accessibleMatterIds)) {
            return [];
        }
    }

    $params = [];
    $matterFilterSql = '';
    if ($accessibleMatterIds !== null) {
        $placeholders = [];
        foreach ($accessibleMatterIds as $i => $id) {
            $key = "m{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $id;
        }
        $matterFilterSql = 'AND pf.matter_id IN (' . implode(',', $placeholders) . ')';
    }

    $sql = "SELECT pf.*, m.matter_number, c.name AS client_name,
                   cu.id AS custodian_id, cu.full_name AS custodian_name,
                   cm.id AS movement_id, cm.due_back_at
            FROM physical_files pf
            JOIN matters m ON m.id = pf.matter_id
            JOIN clients c ON c.id = m.client_id
            LEFT JOIN users cu ON cu.id = pf.current_custodian_id
            JOIN custody_movements cm ON cm.id = (
                SELECT id FROM custody_movements
                WHERE physical_file_id = pf.id AND movement_type = 'CHECK_OUT' AND status = 'COMPLETED'
                ORDER BY requested_at DESC LIMIT 1
            )
            WHERE pf.status = 'CHECKED_OUT' {$matterFilterSql}
            AND cm.due_back_at IS NOT NULL AND cm.due_back_at < NOW(6)
            ORDER BY cm.due_back_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Powers the Dashboard's "Files Checked Out" stat tile — same RBAC scoping as custodia_list_overdue_files(). */
function custodia_count_checked_out_files(PDO $pdo, array $user): int
{
    if ($user['role'] === 'GUEST_AUDITOR') {
        return 0;
    }

    $accessibleMatterIds = null;
    if (!in_array($user['role'], custodia_firm_wide_roles(), true)) {
        $matters = custodia_list_matters_for_user($pdo, $user);
        $accessibleMatterIds = array_column($matters, 'id');
        if (empty($accessibleMatterIds)) {
            return 0;
        }
    }

    $params = [];
    $matterFilterSql = '';
    if ($accessibleMatterIds !== null) {
        $placeholders = [];
        foreach ($accessibleMatterIds as $i => $id) {
            $key = "m{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $id;
        }
        $matterFilterSql = 'AND matter_id IN (' . implode(',', $placeholders) . ')';
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS n FROM physical_files WHERE status = 'CHECKED_OUT' {$matterFilterSql}");
    $stmt->execute($params);
    return (int) $stmt->fetch()['n'];
}

function custodia_list_files_for_matter(PDO $pdo, array $user, string $matterId): array
{
    custodia_assert_matter_access($pdo, $user, $matterId);
    $stmt = $pdo->prepare(
        'SELECT pf.*, pl.building, pl.room, pl.shelf, pl.bin, cu.full_name AS custodian_name,
                lcm.movement_type AS last_movement_type, lcm.completed_at AS last_movement_completed_at,
                lcm.due_back_at AS last_movement_due_back_at,
                (SELECT COUNT(*) FROM digital_documents WHERE linked_physical_file_id = pf.id) AS linked_doc_count,
                (SELECT dd.doc_number FROM digital_documents dd WHERE dd.linked_physical_file_id = pf.id ORDER BY dd.created_at ASC LIMIT 1) AS linked_doc_number,
                (SELECT dd.title FROM digital_documents dd WHERE dd.linked_physical_file_id = pf.id ORDER BY dd.created_at ASC LIMIT 1) AS linked_doc_title
         FROM physical_files pf
         LEFT JOIN physical_locations pl ON pl.id = pf.current_location_id
         LEFT JOIN users cu ON cu.id = pf.current_custodian_id
         LEFT JOIN custody_movements lcm ON lcm.id = (
             SELECT id FROM custody_movements
             WHERE physical_file_id = pf.id AND status = \'COMPLETED\'
             ORDER BY completed_at DESC, requested_at DESC LIMIT 1
         )
         WHERE pf.matter_id = :mid ORDER BY pf.created_at ASC'
    );
    $stmt->execute(['mid' => $matterId]);
    return $stmt->fetchAll();
}

/** "Returned — Aug 19" / "Due back Aug 21 — overdue" style text for the Physical Files table's Last Movement column. */
function custodia_last_movement_label(array $file): string
{
    $dueBack = $file['last_movement_due_back_at'] ?? null;
    if ($file['status'] === 'CHECKED_OUT' && $dueBack) {
        $isOverdue = (new DateTimeImmutable($dueBack)) < new DateTimeImmutable();
        return 'Due back ' . custodia_format_date($dueBack) . ($isOverdue ? ' — overdue' : '');
    }

    $type = $file['last_movement_type'] ?? null;
    $when = $file['last_movement_completed_at'] ?? null;
    if (!$type || !$when) {
        return '—';
    }
    $verbs = [
        'CHECK_IN' => 'Returned',
        'CHECK_OUT' => 'Issued',
        'TRANSFER' => 'Transfer requested',
        'ARCHIVE' => 'Archived',
        'RECALL' => 'Recalled',
    ];
    $verb = $verbs[$type] ?? ucfirst(strtolower($type));
    return "{$verb} — " . custodia_format_date($when);
}

/** Barcode lookup — the primary Scan Station endpoint. */
function custodia_find_file_by_barcode(PDO $pdo, array $user, string $barcode, string $ipAddress): array
{
    $stmt = $pdo->prepare(
        'SELECT pf.*, m.id AS matter_pk, m.matter_number, c.name AS client_name, m.practice_area,
                pl.building, pl.room, pl.shelf, pl.bin,
                cu.id AS custodian_id, cu.full_name AS custodian_name
         FROM physical_files pf
         JOIN matters m ON m.id = pf.matter_id
         JOIN clients c ON c.id = m.client_id
         LEFT JOIN physical_locations pl ON pl.id = pf.current_location_id
         LEFT JOIN users cu ON cu.id = pf.current_custodian_id
         WHERE pf.barcode = :barcode'
    );
    $stmt->execute(['barcode' => $barcode]);
    $file = $stmt->fetch();
    if (!$file) {
        throw custodia_not_found('No physical file matches that barcode.');
    }

    custodia_assert_matter_access($pdo, $user, $file['matter_id']);

    $movStmt = $pdo->prepare('SELECT * FROM custody_movements WHERE physical_file_id = :id ORDER BY requested_at DESC LIMIT 1');
    $movStmt->execute(['id' => $file['id']]);
    $file['latest_movement'] = $movStmt->fetch() ?: null;

    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'SCAN', 'entityType' => 'PHYSICAL_FILE', 'entityId' => $file['id'], 'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $file;
}

/** RBAC matrix: "Register physical file / generate barcode" — Admin and Records Manager only. */
function custodia_register_physical_file(PDO $pdo, array $user, string $matterId, string $jacketLabel, ?string $locationId, ?string $barcode, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'register_physical_files');
    custodia_assert_matter_access($pdo, $user, $matterId);

    $barcode = $barcode ?: ('PF-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 9)));

    $pdo->beginTransaction();
    try {
        $id = custodia_uuid();
        $pdo->prepare('INSERT INTO physical_files (id, matter_id, jacket_label, barcode, current_location_id) VALUES (:id, :mid, :label, :barcode, :loc)')
            ->execute(['id' => $id, 'mid' => $matterId, 'label' => $jacketLabel, 'barcode' => $barcode, 'loc' => $locationId]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'PHYSICAL_FILE_REGISTERED', 'entityType' => 'PHYSICAL_FILE', 'entityId' => $id, 'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $id, 'barcode' => $barcode];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Lifecycle status (Open/Closed) — independent of custody status, see includes/permissions.php's close_physical_files entry. */
function custodia_close_physical_file(PDO $pdo, array $user, string $fileId, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'close_physical_files');

    $stmt = $pdo->prepare('SELECT * FROM physical_files WHERE id = :id');
    $stmt->execute(['id' => $fileId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('Physical file not found.');
    }
    custodia_assert_matter_access($pdo, $user, $target['matter_id']);
    if ($target['lifecycle_status'] === 'CLOSED') {
        throw custodia_bad_request('Physical file is already closed.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE physical_files SET lifecycle_status = 'CLOSED' WHERE id = :id")->execute(['id' => $fileId]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'PHYSICAL_FILE_CLOSED', 'entityType' => 'PHYSICAL_FILE', 'entityId' => $fileId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $fileId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_reopen_physical_file(PDO $pdo, array $user, string $fileId, string $ipAddress): array
{
    custodia_assert_permission($pdo, $user, 'close_physical_files');

    $stmt = $pdo->prepare('SELECT * FROM physical_files WHERE id = :id');
    $stmt->execute(['id' => $fileId]);
    $target = $stmt->fetch();
    if (!$target) {
        throw custodia_not_found('Physical file not found.');
    }
    custodia_assert_matter_access($pdo, $user, $target['matter_id']);
    if ($target['lifecycle_status'] === 'OPEN') {
        throw custodia_bad_request('Physical file is already open.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE physical_files SET lifecycle_status = 'OPEN' WHERE id = :id")->execute(['id' => $fileId]);

        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'PHYSICAL_FILE_REOPENED', 'entityType' => 'PHYSICAL_FILE', 'entityId' => $fileId,
            'ipAddress' => $ipAddress,
        ]);
        $pdo->commit();
        return ['id' => $fileId];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function custodia_list_physical_locations(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM physical_locations ORDER BY building ASC, room ASC')->fetchAll();
}
