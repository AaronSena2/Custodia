<?php
/** Read side of the audit trail — PHP port of AuditQueryService. Writing always goes through includes/audit.php. */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/permissions.php';

/** Resolves a matterId filter into the concrete (entity_type, entity_id) pairs it covers. */
function custodia_matter_scoped_audit_ids(PDO $pdo, string $matterId): array
{
    $files = $pdo->prepare('SELECT id FROM physical_files WHERE matter_id = :mid');
    $files->execute(['mid' => $matterId]);
    $fileIds = array_column($files->fetchAll(), 'id');

    $docs = $pdo->prepare('SELECT id FROM digital_documents WHERE matter_id = :mid');
    $docs->execute(['mid' => $matterId]);
    $docIds = array_column($docs->fetchAll(), 'id');

    return ['fileIds' => $fileIds, 'docIds' => $docIds];
}

/**
 * Builds the WHERE clause + params for an audit_log query, applying RBAC scope
 * (Admin/RM see all; Partner sees their own matters; Associate/Paralegal see
 * only their own actions; Guest sees none) plus any explicit filters.
 */
function custodia_build_audit_where(PDO $pdo, array $user, array $query): array
{
    if ($user['role'] === 'GUEST_AUDITOR') {
        throw custodia_forbidden('Guests may not view the audit log.');
    }

    $clauses = [];
    $params = [];
    $n = 0;

    if (in_array($user['role'], ['ASSOCIATE', 'PARALEGAL'], true)) {
        $clauses[] = 'actor_id = :scope_actor';
        $params['scope_actor'] = $user['id'];
    } elseif ($user['role'] === 'PARTNER') {
        $managed = $pdo->prepare('SELECT id FROM matters WHERE managing_partner_id = :uid');
        $managed->execute(['uid' => $user['id']]);
        $matterIds = array_column($managed->fetchAll(), 'id');

        $scopeOr = ["1=0"]; // matches nothing if the partner manages no matters
        if (!empty($matterIds)) {
            foreach ($matterIds as $mid) {
                $ids = custodia_matter_scoped_audit_ids($pdo, $mid);
                $n++;
                $mKey = "mtr{$n}";
                $params[$mKey] = $mid;
                $matterOr = "(entity_type = 'MATTER' AND entity_id = :{$mKey})";

                if (!empty($ids['fileIds'])) {
                    $ph = [];
                    foreach ($ids['fileIds'] as $i => $fid) {
                        $k = "pf{$n}_{$i}";
                        $ph[] = ":{$k}";
                        $params[$k] = $fid;
                    }
                    $matterOr .= " OR (entity_type = 'PHYSICAL_FILE' AND entity_id IN (" . implode(',', $ph) . '))';
                }
                if (!empty($ids['docIds'])) {
                    $ph = [];
                    foreach ($ids['docIds'] as $i => $did) {
                        $k = "dd{$n}_{$i}";
                        $ph[] = ":{$k}";
                        $params[$k] = $did;
                    }
                    $matterOr .= " OR (entity_type = 'DIGITAL_DOCUMENT' AND entity_id IN (" . implode(',', $ph) . '))';
                }
                $scopeOr[] = "({$matterOr})";
            }
        }
        $clauses[] = '(' . implode(' OR ', $scopeOr) . ')';
    }
    // SYSTEM_ADMIN / RECORDS_MANAGER: no forced scope — full visibility.

    if (!empty($query['actorId'])) {
        $clauses[] = 'actor_id = :f_actor';
        $params['f_actor'] = $query['actorId'];
    }
    if (!empty($query['entityType'])) {
        $clauses[] = 'entity_type = :f_etype';
        $params['f_etype'] = $query['entityType'];
    }
    if (!empty($query['actionType'])) {
        $clauses[] = 'action_type = :f_atype';
        $params['f_atype'] = $query['actionType'];
    }
    if (!empty($query['matterId'])) {
        $ids = custodia_matter_scoped_audit_ids($pdo, $query['matterId']);
        $or = ["(entity_type = 'MATTER' AND entity_id = :f_matter)"];
        $params['f_matter'] = $query['matterId'];
        if (!empty($ids['fileIds'])) {
            $ph = [];
            foreach ($ids['fileIds'] as $i => $fid) {
                $k = "qf{$i}";
                $ph[] = ":{$k}";
                $params[$k] = $fid;
            }
            $or[] = "(entity_type = 'PHYSICAL_FILE' AND entity_id IN (" . implode(',', $ph) . '))';
        }
        if (!empty($ids['docIds'])) {
            $ph = [];
            foreach ($ids['docIds'] as $i => $did) {
                $k = "qd{$i}";
                $ph[] = ":{$k}";
                $params[$k] = $did;
            }
            $or[] = "(entity_type = 'DIGITAL_DOCUMENT' AND entity_id IN (" . implode(',', $ph) . '))';
        }
        $clauses[] = '(' . implode(' OR ', $or) . ')';
    }
    if (!empty($query['q'])) {
        $clauses[] = 'reason LIKE :f_q';
        $params['f_q'] = '%' . $query['q'] . '%';
    }
    if (!empty($query['from'])) {
        $clauses[] = 'created_at >= :f_from';
        $params['f_from'] = $query['from'];
    }
    if (!empty($query['to'])) {
        $clauses[] = 'created_at <= :f_to';
        $params['f_to'] = $query['to'];
    }

    $where = $clauses ? implode(' AND ', $clauses) : '1=1';
    return [$where, $params];
}

function custodia_audit_list(PDO $pdo, array $user, array $query, int $page = 1, int $pageSize = 50): array
{
    [$where, $params] = custodia_build_audit_where($pdo, $user, $query);

    $countStmt = $pdo->prepare("SELECT COUNT(*) AS n FROM audit_log WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetch()['n'];

    $offset = max(0, ($page - 1) * $pageSize);
    $sql = "SELECT al.*, u.full_name AS actor_name, u.role AS actor_role
            FROM audit_log al JOIN users u ON u.id = al.actor_id
            WHERE {$where} ORDER BY al.created_at DESC LIMIT {$pageSize} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entries = $stmt->fetchAll();

    return ['total' => $total, 'page' => $page, 'pageSize' => $pageSize, 'entries' => $entries];
}

function custodia_audit_export_csv(PDO $pdo, array $user, array $query, string $ipAddress): string
{
    custodia_assert_permission($pdo, $user, 'export_audit_log');
    [$where, $params] = custodia_build_audit_where($pdo, $user, $query);
    $sql = "SELECT al.*, u.full_name AS actor_name, u.email AS actor_email
            FROM audit_log al JOIN users u ON u.id = al.actor_id
            WHERE {$where} ORDER BY al.created_at ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $lines = ['"Timestamp","Actor","Actor Email","Action","Entity Type","Entity ID","IP Address","Reason","Entry Hash"'];
    $csvEscape = fn($v) => '"' . str_replace('"', '""', $v ?? '') . '"';
    foreach ($rows as $r) {
        $lines[] = implode(',', [
            $csvEscape($r['created_at']), $csvEscape($r['actor_name']), $csvEscape($r['actor_email']),
            $csvEscape($r['action_type']), $csvEscape($r['entity_type']), $csvEscape($r['entity_id']),
            $csvEscape($r['ip_address']), $csvEscape($r['reason'] ?? ''), $csvEscape($r['entry_hash']),
        ]);
    }

    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $user['id'], 'actionType' => 'AUDIT_EXPORT', 'entityType' => 'AUDIT_LOG', 'entityId' => 'bulk-export',
            'ipAddress' => $ipAddress, 'metadata' => ['rowCount' => count($rows), 'query' => $query],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return implode("\n", $lines);
}

function custodia_audit_verify_integrity(PDO $pdo, array $user): array
{
    custodia_assert_permission($pdo, $user, 'verify_audit_chain');
    return custodia_audit_verify_chain($pdo);
}
