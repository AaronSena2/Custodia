<?php
/**
 * One-off: grant the "Registry" practice group access to every EXISTING
 * matter, per explicit user request (2026-09-06) — "Registry" is the
 * records/registry staff group and needs visibility into every matter
 * regardless of practice area, same standing as any other Practice-Group
 * matter grant (see includes/matter_access.php's custodia_assert_matter_access()
 * and includes/matters.php's custodia_list_matters_for_user()).
 *
 * This only backfills matters that already existed before this script ran.
 * Every matter created from here on gets the same grant automatically —
 * see custodia_auto_grant_registry_group_access() in
 * includes/practice_groups.php, called from custodia_create_matter().
 * (The older bulk_clients_matters CSV importer inserts matters directly and
 * does not call that function; if it's ever re-run, just re-run this script
 * afterward — it's safe to re-run any time.)
 *
 * Reuses custodia_grant_group_matter_access() so every grant gets a normal
 * audit trail and member notifications exactly like a one-by-one manual
 * grant from a matter's Team & Access tab would. Safe to re-run: the
 * function itself rejects a duplicate (group, matter) grant, counted here
 * as skipped.
 *
 *   php bulk_grant_registry_group_all_matters.php
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/errors.php';
require_once __DIR__ . '/includes/practice_groups.php';

$pdo = custodia_db();

$actorStmt = $pdo->prepare("SELECT * FROM users WHERE full_name = 'Aaron Sebina' AND role = 'SYSTEM_ADMIN' LIMIT 1");
$actorStmt->execute();
$actor = $actorStmt->fetch();
if (!$actor) {
    exit("Actor account (Aaron Sebina, SYSTEM_ADMIN) not found — edit this script to select a different acting admin.\n");
}

$groupStmt = $pdo->prepare('SELECT id, name FROM practice_groups WHERE name = :name');
$groupStmt->execute(['name' => CUSTODIA_AUTO_GRANT_MATTER_GROUP_NAME]);
$group = $groupStmt->fetch();
if (!$group) {
    exit('No practice group named "' . CUSTODIA_AUTO_GRANT_MATTER_GROUP_NAME . "\" was found. Create it first (Admin -> Practice Groups), then re-run this script.\n");
}

$matterIds = $pdo->query('SELECT id FROM matters ORDER BY matter_number')->fetchAll(PDO::FETCH_COLUMN);

$reason = 'Bulk grant: the "Registry" practice group given access to all existing matters, per explicit request.';

$granted = 0;
$skipped = 0;
foreach ($matterIds as $matterId) {
    try {
        custodia_grant_group_matter_access($pdo, $actor, $group['id'], $matterId, $reason, '127.0.0.1');
        $granted++;
    } catch (CustodiaHttpException $e) {
        $skipped++; // already granted (re-run) — the function's own dedupe check
    }
}

echo "Granted {$granted}, skipped {$skipped} (already had a grant), out of " . count($matterIds) . " matters checked.\n";
