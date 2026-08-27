<?php
/**
 * One-off remediation: real matter data was imported with no real
 * matter_team_members assignments (only the demo placeholder partner), so no
 * non-admin/non-records-manager user could see any real matter. Per the
 * user's explicit choice, this bulk-grants each matter's own tagged Practice
 * Group access to that matter — coarse (anyone in "Litigation" sees every
 * Litigation matter, not just ones they're actually staffed on) but restores
 * real staff access immediately. Reuses custodia_grant_group_matter_access()
 * so every grant gets a normal audit trail exactly like a one-by-one manual
 * grant would. Safe to re-run: the function itself rejects a duplicate
 * (group, matter) grant, which this script just counts as "already done".
 *
 *   php bulk_grant_practice_group_matter_access.php
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/errors.php';
require_once __DIR__ . '/includes/practice_groups.php';

$pdo = custodia_db();

$actorStmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$actorStmt->execute(['id' => '76e3780e-7897-404d-805a-529a158409b5']); // Aaron Sebina, SYSTEM_ADMIN
$actor = $actorStmt->fetch();
if (!$actor) {
    exit("Actor account not found.\n");
}

$matters = $pdo->query(
    "SELECT m.id AS matter_id, m.matter_number, m.practice_area, pg.id AS group_id
     FROM matters m JOIN practice_groups pg ON pg.name = m.practice_area
     ORDER BY m.matter_number"
)->fetchAll();

$granted = 0;
$skipped = 0;
foreach ($matters as $row) {
    try {
        custodia_grant_group_matter_access(
            $pdo, $actor, $row['group_id'], $row['matter_id'],
            'Bulk remediation: restoring practice-group access after matter import left no real team assignments.',
            '127.0.0.1'
        );
        $granted++;
    } catch (CustodiaHttpException $e) {
        $skipped++; // already granted (re-run) — the function's own dedupe check
    }
}

echo "Granted {$granted}, skipped {$skipped} (already had a grant), out of " . count($matters) . " matters checked.\n";
