<?php
/**
 * One-off remediation, step 2: "General Practice" covers 147 of 151 real
 * matters as a meaningless catch-all tag — per the user's explicit choice,
 * treat it as "anyone at the firm" rather than trying to re-tag 147 matters.
 * The General Practice group already has a practice_group_matter_grants row
 * for every General Practice matter (from bulk_grant_practice_group_matter_access.php),
 * so simply adding every active, non-firm-wide real user as a MEMBER of that
 * group grants them all 147 matters through the existing mechanism — no new
 * grants needed. Firm-wide roles (SYSTEM_ADMIN/RECORDS_MANAGER) already see
 * everything and are skipped; demo accounts (@custodia.demo) are left alone.
 *
 *   php bulk_add_general_practice_members.php
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

$groupStmt = $pdo->prepare("SELECT id FROM practice_groups WHERE name = 'General Practice'");
$groupStmt->execute();
$groupId = $groupStmt->fetchColumn();
if (!$groupId) {
    exit("General Practice group not found.\n");
}

$users = $pdo->query(
    "SELECT id, full_name FROM users
     WHERE is_active = 1 AND email NOT LIKE '%custodia.demo' AND role NOT IN ('SYSTEM_ADMIN', 'RECORDS_MANAGER')
     ORDER BY full_name"
)->fetchAll();

$added = 0;
$skipped = 0;
foreach ($users as $u) {
    try {
        custodia_add_group_member($pdo, $actor, $groupId, $u['id'], '127.0.0.1');
        $added++;
    } catch (CustodiaHttpException $e) {
        $skipped++; // already a member
    }
}

echo "Added {$added}, skipped {$skipped} (already members), out of " . count($users) . " active real users checked.\n";
