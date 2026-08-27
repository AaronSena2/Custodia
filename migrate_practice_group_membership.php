<?php
/**
 * One-off migration: reads users.practice_areas (JSON array of names) and
 * writes the equivalent practice_group_members rows, resolving each name to
 * a practice_groups.id (creating the group if a user's tag was never in the
 * catalog — the JSON column was always free text, never validated against
 * it). Run ONCE, after sql/upgrade_014_practice_groups.sql's steps 1-3 and
 * before its step 4 (dropping users.practice_areas). Safe to re-run: uses
 * INSERT IGNORE, so already-migrated rows are just skipped.
 *
 *   php migrate_practice_group_membership.php
 */

require_once __DIR__ . '/includes/db.php';

$pdo = custodia_db();

$users = $pdo->query('SELECT id, practice_areas FROM users WHERE practice_areas IS NOT NULL')->fetchAll();

$findGroup = $pdo->prepare('SELECT id FROM practice_groups WHERE name = :name');
$insertGroup = $pdo->prepare('INSERT INTO practice_groups (id, name) VALUES (:id, :name)');
$insertMember = $pdo->prepare('INSERT IGNORE INTO practice_group_members (id, practice_group_id, user_id) VALUES (:id, :gid, :uid)');

$groupIdByName = [];
$membershipRows = 0;

foreach ($users as $user) {
    $names = json_decode($user['practice_areas'], true) ?: [];
    foreach ($names as $name) {
        if (!isset($groupIdByName[$name])) {
            $findGroup->execute(['name' => $name]);
            $existing = $findGroup->fetch();
            if ($existing) {
                $groupIdByName[$name] = $existing['id'];
            } else {
                $newId = custodia_uuid();
                $insertGroup->execute(['id' => $newId, 'name' => $name]);
                $groupIdByName[$name] = $newId;
                echo "Created missing practice group: {$name}\n";
            }
        }

        $insertMember->execute([
            'id' => custodia_uuid(),
            'gid' => $groupIdByName[$name],
            'uid' => $user['id'],
        ]);
        if ($insertMember->rowCount() > 0) {
            $membershipRows++;
        }
    }
}

echo "Done. Inserted {$membershipRows} practice_group_members rows across " . count($users) . " users checked.\n";
