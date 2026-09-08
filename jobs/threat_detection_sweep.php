<?php
/**
 * Threat / anomaly detection sweep — periodically scans recent audit_log
 * activity for a handful of patterns worth an admin's attention:
 *   - one actor downloading an unusual number of documents in a short window
 *   - one actor viewing an unusually broad spread of matters in a short window
 *   - any ethical-wall bypass (custodia_bypass_ethical_wall() in
 *     includes/matter_access.php) — also satisfies that function's own
 *     long-standing "TODO(notifications): notify the managing partner" note
 *
 * audit_log already captures every state-changing action (see
 * includes/audit.php) with actor_id/action_type/entity_type/entity_id/
 * created_at on every row — this is pure analysis over data already being
 * written, no new instrumentation required. This is a periodic sweep, not
 * an inline per-request check (chosen deliberately, to keep the request
 * path untouched) — worst case there's up to CUSTODIA_THREAT_SWEEP_WINDOW_MINUTES
 * of lag between the activity and the alert landing in an admin's inbox.
 *
 * Run every 15 minutes via Task Scheduler:
 *   schtasks /create /tn "Custodia Threat Detection Sweep" /tr "C:\xampp\php\php.exe C:\path\to\Registry System\jobs\threat_detection_sweep.php" /sc minute /mo 15
 * Cron equivalent: (star)/15 * * * * php /path/to/Registry System/jobs/threat_detection_sweep.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/reports.php';

// How far back each run looks — matches the recommended 15-minute Task
// Scheduler cadence above, so "N events in the window" reads naturally as
// "N events in the last sweep interval." Tune independently of the cron
// schedule if you want a longer/shorter detection window than the run cadence.
const CUSTODIA_THREAT_SWEEP_WINDOW_MINUTES = 15;

// Thresholds — tune to the firm's real usage patterns once there's a
// baseline; these starting points assume normal use rarely exceeds either
// in a 15-minute window, while an account pulling a whole matter's file
// room, or fishing through unrelated matters, would.
const CUSTODIA_THREAT_BULK_DOWNLOAD_THRESHOLD = 15; // DOWNLOADs by one actor within the window
const CUSTODIA_THREAT_BROAD_ACCESS_THRESHOLD = 10;  // distinct matters VIEWed by one actor within the window

// Once an actor has been alerted for a given rule, don't raise the same
// rule+actor combination again until this many minutes have passed — an
// ongoing binge would otherwise re-trigger every single sweep run and
// flood the admin inbox with duplicates of the same underlying event.
const CUSTODIA_THREAT_ALERT_COOLDOWN_MINUTES = 60;

$pdo = custodia_db();
$actor = custodia_reports_default_actor($pdo);
$windowStart = (new DateTimeImmutable('now'))
    ->modify('-' . CUSTODIA_THREAT_SWEEP_WINDOW_MINUTES . ' minutes')
    ->format('Y-m-d H:i:s.u');

$adminRecipients = $pdo->query(
    "SELECT id FROM users WHERE role IN ('SYSTEM_ADMIN', 'RECORDS_MANAGER') AND is_active = 1"
)->fetchAll(PDO::FETCH_COLUMN);

/**
 * Has this actor already been alerted for this rule within the cooldown
 * window? Keyed off the THREAT_ALERT rows this job itself writes (metadata
 * ->>'rule'), so the cooldown survives across separate runs without any
 * extra state table. For the ethical-wall-bypass rule, $rule is unique per
 * underlying audit_log row (see below), so this doubles as "already
 * alerted for this exact bypass" — i.e. alert exactly once per bypass,
 * ever, rather than on a rolling cooldown.
 */
function custodia_threat_recently_alerted(PDO $pdo, string $rule, string $actorId): bool
{
    $cutoff = (new DateTimeImmutable('now'))
        ->modify('-' . CUSTODIA_THREAT_ALERT_COOLDOWN_MINUTES . ' minutes')
        ->format('Y-m-d H:i:s.u');
    $stmt = $pdo->prepare(
        "SELECT 1 FROM audit_log
         WHERE action_type = 'THREAT_ALERT' AND entity_id = :actor_id AND created_at > :cutoff
           AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rule')) = :rule
         LIMIT 1"
    );
    $stmt->execute(['actor_id' => $actorId, 'cutoff' => $cutoff, 'rule' => $rule]);
    return (bool) $stmt->fetchColumn();
}

/** Writes the THREAT_ALERT audit row (chained — this is exactly the kind of security-relevant event finding 4.3 kept chained) and notifies every given recipient, in one transaction. */
function custodia_raise_threat_alert(PDO $pdo, array $actor, array $recipients, string $rule, string $subjectActorId, string $subjectActorName, string $title, string $body, array $metadata): void
{
    $pdo->beginTransaction();
    try {
        custodia_audit_record($pdo, [
            'actorId' => $actor['id'], 'actionType' => 'THREAT_ALERT', 'entityType' => 'USER', 'entityId' => $subjectActorId,
            'ipAddress' => '127.0.0.1',
            'metadata' => array_merge(['rule' => $rule, 'subjectActorName' => $subjectActorName], $metadata),
        ]);
        if (!empty($recipients)) {
            custodia_notify_users($pdo, $recipients, 'THREAT_ALERT', $title, $body, 'USER', $subjectActorId);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$alertsRaised = 0;

// Rule 1: bulk download — one actor, many DOWNLOAD rows, short window.
$bulkDownloads = $pdo->prepare(
    "SELECT al.actor_id, COUNT(*) AS cnt, u.full_name
     FROM audit_log al JOIN users u ON u.id = al.actor_id
     WHERE al.action_type = 'DOWNLOAD' AND al.created_at > :start
     GROUP BY al.actor_id, u.full_name
     HAVING cnt >= :threshold"
);
$bulkDownloads->bindValue('start', $windowStart);
$bulkDownloads->bindValue('threshold', CUSTODIA_THREAT_BULK_DOWNLOAD_THRESHOLD, PDO::PARAM_INT);
$bulkDownloads->execute();
foreach ($bulkDownloads->fetchAll() as $row) {
    if (custodia_threat_recently_alerted($pdo, 'BULK_DOWNLOAD', $row['actor_id'])) {
        continue;
    }
    custodia_raise_threat_alert(
        $pdo, $actor, $adminRecipients, 'BULK_DOWNLOAD', $row['actor_id'], $row['full_name'],
        'Unusual activity: bulk downloads',
        "{$row['full_name']} downloaded {$row['cnt']} documents in the last " . CUSTODIA_THREAT_SWEEP_WINDOW_MINUTES . " minutes.",
        ['count' => (int) $row['cnt'], 'windowMinutes' => CUSTODIA_THREAT_SWEEP_WINDOW_MINUTES]
    );
    $alertsRaised++;
}

// Rule 2: broad/unusual matter access — one actor, many distinct matters VIEWed, short window.
$broadAccess = $pdo->prepare(
    "SELECT al.actor_id, COUNT(DISTINCT al.entity_id) AS cnt, u.full_name
     FROM audit_log al JOIN users u ON u.id = al.actor_id
     WHERE al.action_type = 'VIEW' AND al.entity_type = 'MATTER' AND al.created_at > :start
     GROUP BY al.actor_id, u.full_name
     HAVING cnt >= :threshold"
);
$broadAccess->bindValue('start', $windowStart);
$broadAccess->bindValue('threshold', CUSTODIA_THREAT_BROAD_ACCESS_THRESHOLD, PDO::PARAM_INT);
$broadAccess->execute();
foreach ($broadAccess->fetchAll() as $row) {
    if (custodia_threat_recently_alerted($pdo, 'BROAD_ACCESS', $row['actor_id'])) {
        continue;
    }
    custodia_raise_threat_alert(
        $pdo, $actor, $adminRecipients, 'BROAD_ACCESS', $row['actor_id'], $row['full_name'],
        'Unusual activity: broad matter access',
        "{$row['full_name']} viewed {$row['cnt']} different matters in the last " . CUSTODIA_THREAT_SWEEP_WINDOW_MINUTES . " minutes.",
        ['count' => (int) $row['cnt'], 'windowMinutes' => CUSTODIA_THREAT_SWEEP_WINDOW_MINUTES]
    );
    $alertsRaised++;
}

// Rule 3: ethical wall bypass — every occurrence, always alerted exactly
// once (rule key includes the audit_log row id), and routed to the
// matter's own managing partner in addition to the admin recipients.
$bypasses = $pdo->prepare(
    "SELECT al.id, al.actor_id, al.entity_id AS matter_id, al.reason, al.metadata_json, u.full_name, m.matter_number
     FROM audit_log al
     JOIN users u ON u.id = al.actor_id
     JOIN matters m ON m.id = al.entity_id
     WHERE al.action_type = 'ETHICAL_WALL_BYPASS' AND al.created_at > :start"
);
$bypasses->execute(['start' => $windowStart]);
foreach ($bypasses->fetchAll() as $row) {
    $rule = 'ETHICAL_WALL_BYPASS:' . $row['id'];
    if (custodia_threat_recently_alerted($pdo, $rule, $row['actor_id'])) {
        continue;
    }
    $metadata = json_decode($row['metadata_json'] ?? '{}', true) ?: [];
    $recipients = $adminRecipients;
    if (!empty($metadata['managingPartnerId']) && !in_array($metadata['managingPartnerId'], $recipients, true)) {
        $recipients[] = $metadata['managingPartnerId'];
    }
    custodia_raise_threat_alert(
        $pdo, $actor, $recipients, $rule, $row['actor_id'], $row['full_name'],
        'Ethical wall bypassed',
        "{$row['full_name']} bypassed the ethical wall on matter {$row['matter_number']}. Reason given: " . ($row['reason'] !== null && $row['reason'] !== '' ? $row['reason'] : '(none)'),
        ['matterId' => $row['matter_id'], 'auditLogId' => $row['id']]
    );
    $alertsRaised++;
}

fwrite(STDOUT, "Threat detection sweep complete: {$alertsRaised} alert(s) raised.\n");
