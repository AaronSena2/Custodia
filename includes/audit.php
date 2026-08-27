<?php
/**
 * Hash-chained, append-only audit log — the PHP port of the Node version's
 * AuditService (blueprint Section 4.9). Every state-changing action in the
 * system must go through custodia_audit_record(), always inside the SAME
 * PDO transaction as the state change itself, so "it happened" and "it was
 * logged" commit or roll back together.
 *
 * Concurrency: Postgres's advisory lock has no direct MySQL equivalent that
 * is scoped to a transaction, so this port uses a well-known MySQL pattern
 * instead — a singleton `audit_chain_state` row that every writer does
 * `SELECT ... FOR UPDATE` on before reading the current chain tip. InnoDB
 * holds that row lock until COMMIT/ROLLBACK, which serializes "read latest
 * hash, then append" exactly the way the advisory lock did, without ever
 * needing a cross-row "SELECT the last audit_log row" race.
 */

require_once __DIR__ . '/db.php';

function custodia_genesis_hash(): string
{
    return str_repeat('0', 64);
}

/** ISO-8601 with millisecond precision and a literal "Z", matching JS's Date#toISOString(). */
function custodia_iso8601(DateTimeImmutable $dt): string
{
    $utc = $dt->setTimezone(new DateTimeZone('UTC'));
    return $utc->format('Y-m-d\TH:i:s.') . substr($utc->format('u'), 0, 3) . 'Z';
}

/**
 * @param PDO $pdo Must already be inside a transaction started by the caller.
 * @param array{actorId:string,actionType:string,entityType:string,entityId:string,
 *              ipAddress:string,reason?:?string,geoLocation?:?string,metadata?:array} $params
 */
function custodia_audit_record(PDO $pdo, array $params): array
{
    // Serialize concurrent writers: lock the singleton chain-state row for the
    // rest of this transaction before reading/using its value.
    $pdo->query('SELECT last_hash FROM audit_chain_state WHERE id = 1 FOR UPDATE')->fetch();

    $stmt = $pdo->query('SELECT last_hash FROM audit_chain_state WHERE id = 1');
    $row = $stmt->fetch();
    $prevHash = $row ? $row['last_hash'] : custodia_genesis_hash();

    $now = new DateTimeImmutable('now');
    $metadata = $params['metadata'] ?? [];
    $metadataJson = json_encode(empty($metadata) ? new stdClass() : $metadata, JSON_UNESCAPED_SLASHES);

    $reason = $params['reason'] ?? '';
    $geoLocation = $params['geoLocation'] ?? '';

    $payload = implode('|', [
        $prevHash,
        $params['actorId'],
        $params['actionType'],
        $params['entityType'],
        $params['entityId'],
        $reason,
        $params['ipAddress'],
        $geoLocation,
        custodia_iso8601($now),
        $metadataJson,
    ]);
    $entryHash = hash('sha256', $payload);

    $id = custodia_uuid();
    $insert = $pdo->prepare(
        'INSERT INTO audit_log (id, actor_id, action_type, entity_type, entity_id, reason, ip_address, geo_location, metadata_json, prev_hash, entry_hash, created_at)
         VALUES (:id, :actor_id, :action_type, :entity_type, :entity_id, :reason, :ip_address, :geo_location, :metadata_json, :prev_hash, :entry_hash, :created_at)'
    );
    $insert->execute([
        'id' => $id,
        'actor_id' => $params['actorId'],
        'action_type' => $params['actionType'],
        'entity_type' => $params['entityType'],
        'entity_id' => $params['entityId'],
        'reason' => ($params['reason'] ?? null),
        'ip_address' => $params['ipAddress'],
        'geo_location' => ($params['geoLocation'] ?? null),
        'metadata_json' => $metadataJson,
        'prev_hash' => $prevHash,
        'entry_hash' => $entryHash,
        'created_at' => $now->format('Y-m-d H:i:s.u'),
    ]);

    $upsert = $pdo->prepare(
        'INSERT INTO audit_chain_state (id, last_hash) VALUES (1, :hash)
         ON DUPLICATE KEY UPDATE last_hash = :hash2'
    );
    $upsert->execute(['hash' => $entryHash, 'hash2' => $entryHash]);

    return [
        'id' => $id,
        'actorId' => $params['actorId'],
        'actionType' => $params['actionType'],
        'entityType' => $params['entityType'],
        'entityId' => $params['entityId'],
        'reason' => $params['reason'] ?? null,
        'ipAddress' => $params['ipAddress'],
        'geoLocation' => $params['geoLocation'] ?? null,
        'metadata' => $metadata,
        'prevHash' => $prevHash,
        'entryHash' => $entryHash,
        'createdAt' => $now,
    ];
}

/**
 * Recomputes the chain from the genesis hash and compares it to what's stored.
 * Call this from the Audit Explorer's "Verify Chain Integrity" button, or a
 * periodic cron job — see admin/retention (jobs README section) for the
 * equivalent recurring-job pattern.
 */
function custodia_audit_verify_chain(PDO $pdo, int $pageSize = 5000): array
{
    $expectedPrevHash = custodia_genesis_hash();
    $checked = 0;
    $lastId = null;

    while (true) {
        if ($lastId === null) {
            $stmt = $pdo->prepare('SELECT * FROM audit_log ORDER BY created_at ASC, id ASC LIMIT :limit');
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            // Keyset pagination on (created_at, id) since created_at alone is not unique enough.
            $stmt = $pdo->prepare(
                'SELECT * FROM audit_log
                 WHERE (created_at, id) > (
                   (SELECT created_at FROM audit_log WHERE id = :last_id), :last_id2
                 )
                 ORDER BY created_at ASC, id ASC LIMIT :limit'
            );
            $stmt->bindValue(':last_id', $lastId);
            $stmt->bindValue(':last_id2', $lastId);
            $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $stmt->execute();
        }
        $page = $stmt->fetchAll();
        if (count($page) === 0) {
            break;
        }

        foreach ($page as $row) {
            $createdAt = new DateTimeImmutable($row['created_at']);
            $payload = implode('|', [
                $expectedPrevHash,
                $row['actor_id'],
                $row['action_type'],
                $row['entity_type'],
                $row['entity_id'],
                $row['reason'] ?? '',
                $row['ip_address'],
                $row['geo_location'] ?? '',
                custodia_iso8601($createdAt),
                $row['metadata_json'] ?? '{}',
            ]);
            $recomputed = hash('sha256', $payload);

            if ($row['prev_hash'] !== $expectedPrevHash || $row['entry_hash'] !== $recomputed) {
                return ['valid' => false, 'brokenAtId' => $row['id'], 'checked' => $checked];
            }
            $expectedPrevHash = $row['entry_hash'];
            $checked++;
        }
        $lastId = $page[count($page) - 1]['id'];
    }

    return ['valid' => true, 'checked' => $checked];
}
