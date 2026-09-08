<?php
/**
 * Seeds demo data — same users, matters, physical files, and document as the
 * original design mockups (and the earlier Node version), so this rebuild is
 * directly comparable.
 *
 * Run with: php seed.php
 * (Re-running is safe — it upserts users by email and skips matter/file/
 * document creation if a matter with the same matter_number already exists.)
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/roles.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/clients.php';
require_once __DIR__ . '/includes/retention_policies.php';

const DEMO_PASSWORD = 'ChangeMe123!';

function seed_upsert_user(PDO $pdo, array $data): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email');
    $stmt->execute(['email' => $data['email']]);
    $existing = $stmt->fetch();
    if ($existing) {
        return $existing;
    }

    $id = custodia_uuid();
    $insert = $pdo->prepare(
        'INSERT INTO users (id, employee_id, full_name, email, password_hash, role, bar_number, mfa_enabled)
         VALUES (:id, :employee_id, :full_name, :email, :password_hash, :role, :bar_number, :mfa_enabled)'
    );
    $insert->execute([
        'id' => $id,
        'employee_id' => $data['employee_id'],
        'full_name' => $data['full_name'],
        'email' => $data['email'],
        'password_hash' => password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT),
        'role' => $data['role'],
        'bar_number' => $data['bar_number'] ?? null,
        'mfa_enabled' => !empty($data['mfa_enabled']) ? 1 : 0,
    ]);

    $data['id'] = $id;
    seed_assign_practice_groups($pdo, $id, $data['practice_areas'] ?? []);
    return $data;
}

/** Resolves each name to a practice_groups row (creating it if this is the first user seeded into it) and adds the membership. */
function seed_assign_practice_groups(PDO $pdo, string $userId, array $groupNames): void
{
    $findGroup = $pdo->prepare('SELECT id FROM practice_groups WHERE name = :name');
    $insertGroup = $pdo->prepare('INSERT INTO practice_groups (id, name) VALUES (:id, :name)');
    $insertMember = $pdo->prepare('INSERT IGNORE INTO practice_group_members (id, practice_group_id, user_id) VALUES (:id, :gid, :uid)');

    foreach ($groupNames as $name) {
        $findGroup->execute(['name' => $name]);
        $group = $findGroup->fetch();
        $groupId = $group['id'] ?? null;
        if (!$groupId) {
            $groupId = custodia_uuid();
            $insertGroup->execute(['id' => $groupId, 'name' => $name]);
        }
        $insertMember->execute(['id' => custodia_uuid(), 'gid' => $groupId, 'uid' => $userId]);
    }
}

function seed_ensure_matter(PDO $pdo, array $data): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM matters WHERE matter_number = :num');
    $stmt->execute(['num' => $data['matter_number']]);
    if ($stmt->fetch()) {
        return null; // already seeded on a previous run
    }

    $id = custodia_uuid();
    $clientId = custodia_find_or_create_client($pdo, $data['client_name']);
    $insert = $pdo->prepare(
        'INSERT INTO matters (id, matter_number, client_id, practice_area, managing_partner_id, status, confidentiality, open_date, close_date)
         VALUES (:id, :matter_number, :client_id, :practice_area, :managing_partner_id, :status, :confidentiality, :open_date, :close_date)'
    );
    $insert->execute([
        'id' => $id,
        'matter_number' => $data['matter_number'],
        'client_id' => $clientId,
        'practice_area' => $data['practice_area'],
        'managing_partner_id' => $data['managing_partner_id'],
        'status' => $data['status'],
        'confidentiality' => $data['confidentiality'],
        'open_date' => $data['open_date'],
        'close_date' => $data['close_date'] ?? null,
    ]);

    foreach ($data['team'] ?? [] as $member) {
        $teamInsert = $pdo->prepare(
            'INSERT INTO matter_team_members (id, matter_id, user_id, role_on_matter) VALUES (:id, :matter_id, :user_id, :role_on_matter)'
        );
        $teamInsert->execute([
            'id' => custodia_uuid(),
            'matter_id' => $id,
            'user_id' => $member['userId'],
            'role_on_matter' => $member['roleOnMatter'],
        ]);
    }

    $data['id'] = $id;
    return $data;
}

$pdo = custodia_db();

// Ensure the audit hash-chain singleton row exists before anything writes to it.
$pdo->exec("INSERT IGNORE INTO audit_chain_state (id, last_hash) VALUES (1, '" . custodia_genesis_hash() . "')");

$admin  = seed_upsert_user($pdo, ['employee_id' => 'EMP-1001', 'full_name' => 'Sam Okafor', 'email' => 'sam.okafor@custodia.demo', 'role' => 'SYSTEM_ADMIN', 'practice_areas' => [], 'mfa_enabled' => true]);
$rita   = seed_upsert_user($pdo, ['employee_id' => 'EMP-1002', 'full_name' => 'Rita Alvarez', 'email' => 'rita.alvarez@custodia.demo', 'role' => 'RECORDS_MANAGER', 'practice_areas' => [], 'mfa_enabled' => true]);
$daniel = seed_upsert_user($pdo, ['employee_id' => 'EMP-1003', 'full_name' => 'Daniel Reyes', 'email' => 'daniel.reyes@custodia.demo', 'role' => 'PARTNER', 'bar_number' => 'NY-2004-3312', 'practice_areas' => ['Corporate'], 'mfa_enabled' => true]);
$priya  = seed_upsert_user($pdo, ['employee_id' => 'EMP-1004', 'full_name' => 'Priya Nair', 'email' => 'priya.nair@custodia.demo', 'role' => 'PARTNER', 'bar_number' => 'NY-2009-8841', 'practice_areas' => ['Litigation'], 'mfa_enabled' => true]);
$elena  = seed_upsert_user($pdo, ['employee_id' => 'EMP-1005', 'full_name' => 'Elena Cho', 'email' => 'elena.cho@custodia.demo', 'role' => 'ASSOCIATE', 'bar_number' => 'NY-2020-1187', 'practice_areas' => ['Corporate']]);
$marcus = seed_upsert_user($pdo, ['employee_id' => 'EMP-1006', 'full_name' => 'Marcus Webb', 'email' => 'marcus.webb@custodia.demo', 'role' => 'PARALEGAL', 'practice_areas' => ['Corporate', 'Trusts & Estates']]);

// Physical locations (only created once — guarded by a marker check on building name).
$locStmt = $pdo->prepare('SELECT id FROM physical_locations WHERE building = :b LIMIT 1');
function seed_ensure_location(PDO $pdo, array $data): array
{
    $stmt = $pdo->prepare('SELECT * FROM physical_locations WHERE building = :b AND room = :r LIMIT 1');
    $stmt->execute(['b' => $data['building'], 'r' => $data['room']]);
    $existing = $stmt->fetch();
    if ($existing) {
        return $existing;
    }
    $id = custodia_uuid();
    $insert = $pdo->prepare('INSERT INTO physical_locations (id, building, room, shelf, bin, location_type) VALUES (:id, :building, :room, :shelf, :bin, :location_type)');
    $insert->execute([
        'id' => $id,
        'building' => $data['building'],
        'room' => $data['room'],
        'shelf' => $data['shelf'] ?? null,
        'bin' => $data['bin'] ?? null,
        'location_type' => $data['location_type'],
    ]);
    $data['id'] = $id;
    return $data;
}

$roomA = seed_ensure_location($pdo, ['building' => 'Building A', 'room' => '204', 'shelf' => '12', 'location_type' => 'ACTIVE_SHELF']);
$offsite = seed_ensure_location($pdo, ['building' => 'Iron Mountain — Facility 3', 'room' => '-', 'location_type' => 'OFFSITE_FACILITY']);
seed_ensure_location($pdo, ['building' => 'Archive Room B', 'room' => 'B', 'shelf' => '4', 'location_type' => 'ARCHIVE_ROOM']);

$northgate = seed_ensure_matter($pdo, [
    'matter_number' => 'M-2024-0187', 'client_name' => 'Northgate Logistics, Inc.', 'practice_area' => 'Corporate',
    'managing_partner_id' => $daniel['id'], 'status' => 'ACTIVE', 'confidentiality' => 'RESTRICTED', 'open_date' => '2024-03-14',
    'team' => [
        ['userId' => $daniel['id'], 'roleOnMatter' => 'Incharge'],
        ['userId' => $elena['id'], 'roleOnMatter' => 'Associate'],
        ['userId' => $marcus['id'], 'roleOnMatter' => 'Paralegal'],
    ],
]);

$bellweather = seed_ensure_matter($pdo, [
    'matter_number' => 'M-2024-0142', 'client_name' => 'Bellweather Foods', 'practice_area' => 'Litigation',
    'managing_partner_id' => $priya['id'], 'status' => 'ACTIVE', 'confidentiality' => 'STANDARD', 'open_date' => '2024-01-22',
    'team' => [['userId' => $priya['id'], 'roleOnMatter' => 'Incharge']],
]);

seed_ensure_matter($pdo, [
    'matter_number' => 'M-2024-0201', 'client_name' => 'Arden Biotech', 'practice_area' => 'Corporate',
    'managing_partner_id' => $daniel['id'], 'status' => 'ACTIVE', 'confidentiality' => 'PRIVILEGED', 'open_date' => '2024-05-02',
    'team' => [['userId' => $daniel['id'], 'roleOnMatter' => 'Incharge']],
]);

seed_ensure_matter($pdo, [
    'matter_number' => 'M-2023-0098', 'client_name' => 'Estate of R. Whitfield', 'practice_area' => 'Trusts & Estates',
    'managing_partner_id' => $priya['id'], 'status' => 'CLOSED', 'confidentiality' => 'STANDARD',
    'open_date' => '2023-02-10', 'close_date' => '2025-06-01',
]);

if ($northgate && $bellweather) {
    // Ethical wall: Marcus is screened from Bellweather's opposing-interest matter.
    $pdo->prepare('INSERT INTO ethical_walls (id, matter_id, user_id, reason, created_by) VALUES (:id, :matter_id, :user_id, :reason, :created_by)')
        ->execute([
            'id' => custodia_uuid(), 'matter_id' => $bellweather['id'], 'user_id' => $marcus['id'],
            'reason' => 'Marcus previously worked on Sutton Group matters at a prior firm.', 'created_by' => $rita['id'],
        ]);

    $pf1Id = custodia_uuid();
    $pdo->prepare('INSERT INTO physical_files (id, matter_id, barcode, jacket_label, status, current_location_id) VALUES (:id, :matter_id, :barcode, :jacket_label, "IN_REGISTRY", :loc)')
        ->execute(['id' => $pf1Id, 'matter_id' => $northgate['id'], 'barcode' => 'PF-000482912', 'jacket_label' => 'Vol. 1 — Pleadings', 'loc' => $roomA['id']]);

    $pf2Id = custodia_uuid();
    $pdo->prepare('INSERT INTO physical_files (id, matter_id, barcode, jacket_label, status, current_custodian_id) VALUES (:id, :matter_id, :barcode, :jacket_label, "CHECKED_OUT", :cust)')
        ->execute(['id' => $pf2Id, 'matter_id' => $northgate['id'], 'barcode' => 'PF-000482913', 'jacket_label' => 'Vol. 2 — Discovery', 'cust' => $priya['id']]);

    $overdueDate = (new DateTimeImmutable('-4 days'))->format('Y-m-d H:i:s.u');
    $pdo->prepare(
        'INSERT INTO custody_movements (id, physical_file_id, movement_type, to_user_id, requested_by_id, approved_by_id, reason, due_back_at, status, completed_at)
         VALUES (:id, :fid, "CHECK_OUT", :to, :req, :app, :reason, :due, "COMPLETED", NOW(6))'
    )->execute([
        'id' => custodia_uuid(), 'fid' => $pf2Id, 'to' => $priya['id'], 'req' => $priya['id'], 'app' => $priya['id'],
        'reason' => 'Reviewing discovery volume ahead of production deadline.', 'due' => $overdueDate,
    ]);

    $pdo->prepare('INSERT INTO physical_files (id, matter_id, barcode, jacket_label, status, current_location_id) VALUES (:id, :matter_id, :barcode, :jacket_label, "OFFSITE_ARCHIVE", :loc)')
        ->execute(['id' => custodia_uuid(), 'matter_id' => $northgate['id'], 'barcode' => 'PF-000479821', 'jacket_label' => 'Corporate Records — Binder A', 'loc' => $offsite['id']]);

    $docId = custodia_uuid();
    $pdo->prepare(
        'INSERT INTO digital_documents (id, matter_id, linked_physical_file_id, title, description, doc_type, confidentiality, current_version_no, author_id, created_by_id)
         VALUES (:id, :matter_id, :linked, :title, :desc, :doc_type, "RESTRICTED", 2, :author, :creator)'
    )->execute([
        'id' => $docId, 'matter_id' => $northgate['id'], 'linked' => $pf2Id, 'title' => 'Asset Purchase Agreement — Execution Draft',
        'desc' => 'Execution draft of the asset purchase agreement for the Northgate transaction, including the seller\'s title representations.',
        'doc_type' => 'Agreement', 'author' => $daniel['id'], 'creator' => $marcus['id'],
    ]);

    // v1 and v2 differ in a couple of clauses — demo data for the version-comparison feature.
    $apaTextV1 = "ASSET PURCHASE AGREEMENT\n\n"
        . "This Asset Purchase Agreement is entered into by and between Northgate Holdings, LLC, a Delaware limited liability company (\"Seller\"), and the Buyer named herein.\n\n"
        . "Seller represents and warrants that it has good and marketable title to all of the Purchased Assets, free and clear of all liens, claims, and encumbrances of any kind.\n\n"
        . "The Purchase Price shall be Twelve Million Dollars ($12,000,000), payable in cash at the Closing.\n\n"
        . "This Agreement shall be governed by and construed in accordance with the laws of the State of Delaware.";
    $apaTextV2 = "ASSET PURCHASE AGREEMENT\n\n"
        . "This Asset Purchase Agreement is entered into by and between Northgate Holdings, LLC, a Delaware limited liability company (\"Seller\"), and the Buyer named herein.\n\n"
        . "Seller represents and warrants that it has good and marketable title to all of the Purchased Assets, free and clear of all liens, claims, and encumbrances of any kind, except for Permitted Encumbrances set forth on Schedule 3.2.\n\n"
        . "The Purchase Price shall be Thirteen Million Five Hundred Thousand Dollars ($13,500,000), payable in cash at the Closing.\n\n"
        . "This Agreement shall be governed by and construed in accordance with the laws of the State of Delaware.";

    $pdo->prepare(
        'INSERT INTO document_versions (id, document_id, version_number, storage_key, sha256_hash, file_size_bytes, original_filename, mime_type, ocr_status, ocr_text, extracted_text, extraction_status, uploaded_by_id)
         VALUES (:id, :doc, 1, :key, :hash, 245000, :orig_name, "application/pdf", "DONE", :ocr, :text, "DONE", :uploaded_by)'
    )->execute([
        'id' => custodia_uuid(), 'doc' => $docId, 'key' => 'northgate/apa-v1.pdf', 'hash' => str_repeat('0', 64),
        'orig_name' => 'Asset Purchase Agreement - Execution Draft v1.pdf',
        'ocr' => $apaTextV1, 'text' => $apaTextV1, 'uploaded_by' => $marcus['id'],
    ]);
    $pdo->prepare(
        'INSERT INTO document_versions (id, document_id, version_number, storage_key, sha256_hash, file_size_bytes, original_filename, mime_type, ocr_status, ocr_text, extracted_text, extraction_status, uploaded_by_id)
         VALUES (:id, :doc, 2, :key, :hash, 246500, :orig_name, "application/pdf", "DONE", :ocr, :text, "DONE", :uploaded_by)'
    )->execute([
        'id' => custodia_uuid(), 'doc' => $docId, 'key' => 'northgate/apa-v2.pdf', 'hash' => str_repeat('1', 64),
        'orig_name' => 'Asset Purchase Agreement - Execution Draft v2.pdf',
        'ocr' => $apaTextV2, 'text' => $apaTextV2, 'uploaded_by' => $daniel['id'],
    ]);
}

// A couple of practice-group-specific retention policies, plus the
// firm-wide default (security review 2026-09-03, finding 2.1) — so a
// fresh install demonstrates the exact gap that finding described:
// Corporate and Litigation get their own rule, while the third seeded
// practice group, Trusts & Estates, has none and falls back to the
// default (jobs/overdue_sweep.php).
$rpStmt = $pdo->query('SELECT COUNT(*) AS n FROM retention_policies')->fetch();
if ((int) $rpStmt['n'] === 0) {
    $pdo->prepare('INSERT INTO retention_policies (id, practice_area, retention_years, action, trigger_event) VALUES (:id, :pa, :yrs, :action, :trigger)')
        ->execute(['id' => custodia_uuid(), 'pa' => 'Corporate', 'yrs' => 7, 'action' => 'ARCHIVE', 'trigger' => 'MATTER_CLOSE']);
    $pdo->prepare('INSERT INTO retention_policies (id, practice_area, retention_years, action, trigger_event) VALUES (:id, :pa, :yrs, :action, :trigger)')
        ->execute(['id' => custodia_uuid(), 'pa' => 'Litigation', 'yrs' => 10, 'action' => 'REVIEW', 'trigger' => 'MATTER_CLOSE']);
    $pdo->prepare('INSERT INTO retention_policies (id, practice_area, retention_years, action, trigger_event) VALUES (:id, :pa, :yrs, :action, :trigger)')
        ->execute(['id' => custodia_uuid(), 'pa' => CUSTODIA_RETENTION_DEFAULT_PRACTICE_AREA, 'yrs' => 7, 'action' => 'REVIEW', 'trigger' => 'MATTER_CLOSE']);
}

// The practice group catalog (Admin → Practice Groups) — seed_assign_practice_groups()
// above already creates one per demo user's group name as a side effect, so this
// only backfills any that no user happens to belong to yet.
$pgStmt = $pdo->query("SELECT COUNT(*) AS n FROM practice_groups WHERE name IN ('Corporate', 'Litigation', 'Trusts & Estates')")->fetch();
if ((int) $pgStmt['n'] < 3) {
    $insertPg = $pdo->prepare('INSERT IGNORE INTO practice_groups (id, name) VALUES (:id, :name)');
    foreach (['Corporate', 'Litigation', 'Trusts & Estates'] as $name) {
        $insertPg->execute(['id' => custodia_uuid(), 'name' => $name]);
    }
}

// Seed the six built-in roles (role_permissions FK-references this table,
// so it has to happen first).
$roleStmt = $pdo->query('SELECT COUNT(*) AS n FROM roles')->fetch();
if ((int) $roleStmt['n'] === 0) {
    $insertRole = $pdo->prepare('INSERT INTO roles (role_key, label, is_builtin) VALUES (:key, :label, 1)');
    foreach (CUSTODIA_BUILTIN_ROLES as $key => $label) {
        $insertRole->execute(['key' => $key, 'label' => $label]);
    }
}

// Seed the role → permission matrix with defaults that exactly match this
// app's original hardcoded role checks, so installing this feature changes
// no behavior until an admin actually edits Admin → Permissions.
$rpermStmt = $pdo->query('SELECT COUNT(*) AS n FROM role_permissions')->fetch();
if ((int) $rpermStmt['n'] === 0) {
    $insertPerm = $pdo->prepare('INSERT INTO role_permissions (role, permission_key) VALUES (:role, :key)');
    foreach (CUSTODIA_DEFAULT_ROLE_PERMISSIONS as $role => $keys) {
        foreach ($keys as $key) {
            $insertPerm->execute(['role' => $role, 'key' => $key]);
        }
    }
}

echo "Seed complete.\n";
echo "Demo users (password: " . DEMO_PASSWORD . "):\n";
foreach ([$admin, $rita, $daniel, $priya, $elena, $marcus] as $u) {
    echo "  {$u['email']}  —  {$u['role']}\n";
}
echo "Matters: M-2024-0187, M-2024-0142, M-2024-0201, M-2023-0098\n";
echo "Physical files: PF-000482912, PF-000482913, PF-000479821\n";
echo "Digital document: Asset Purchase Agreement — Execution Draft\n";
