<?php
/**
 * One-off: grant every Practice Group access to every matter (all clients),
 * per explicit user request — removes practice-group as an access boundary
 * firm-wide (any group member can then see any matter via
 * custodia_user_has_group_matter_access()). Reuses
 * custodia_grant_group_matter_access() so every grant gets a normal audit
 * trail and member notifications exactly like a one-by-one manual grant
 * from matter.php's Team & Access tab would. Safe to re-run: the function
 * itself rejects a duplicate (group, matter) grant, counted here as skipped.
 *
 *   php bulk_grant_all_groups_all_matters.php
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/errors.php';
require_once __DIR__ . '/includes/practice_groups.php';

$pdo = custodia_db();

$actorStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$actorStmt->execute(['id' => '634141cc-7793-413f-b5a7-51c23c658f9e']); // Aaron Sebina, SYSTEM_ADMIN
$actor = $actorStmt->fetch();
if (!$actor) {
    exit("Actor account not found.\n");
}

$groups = $pdo->query('SELECT id, name FROM practice_groups ORDER BY name')->fetchAll();
$matterIds = $pdo->query('SELECT id FROM matters ORDER BY matter_number')->fetchAll(PDO::FETCH_COLUMN);

$reason = 'Bulk grant: all practice groups given access to all matters, per explicit request.';

$granted = 0;
$skipped = 0;
$total = count($groups) * count($matterIds);
$i = 0;

foreach ($groups as $group) {
    foreach ($matterIds as $matterId) {
        $i++;
        try {
            custodia_grant_group_matter_access($pdo, $actor, $group['id'], $matterId, $reason, '127.0.0.1');
            $granted++;
        } catch (CustodiaHttpException $e) {
            $skipped++; // already granted (re-run) — the function's own dedupe check
        }
        if ($i % 2000 === 0) {
            echo "  {$i}/{$total} processed ({$granted} granted, {$skipped} skipped)...\n";
        }
    }
}

echo "Done. Granted {$granted}, skipped {$skipped} (already had a grant), out of {$total} group x matter combinations checked.\n";
