<?php
/**
 * One-off import: replaces every client, matter, and physical file with the
 * real data recovered from the firm's legacy physical-file register
 * ("client and files.xlsx" -> pre-cleaned by a companion Python pass into a
 * plain CSV: client, physical_file, volume_no, open_date, loc_raw, loc_room,
 * loc_shelf, loc_bin).
 *
 * Cleaning already applied upstream:
 *  - Blanked obvious placeholder junk ("FILE NAME", "NIL", etc.).
 *  - Rows with neither a client name nor a file number dropped entirely.
 *  - Dates normalized to YYYY-MM-DD (dd.mm.yyyy strings and Excel serials).
 *  - Location codes like "SA_A1_01" split into room/shelf/bin.
 *
 * What this script decides on its own:
 *  - A row with a client but no file number still gets a Matter (a matter
 *    doesn't require a physical file); no physical_files row is created for it.
 *  - A row with a file number but no client name is filed under one shared
 *    placeholder client, "UNKNOWN CLIENT (bulk import)".
 *  - Several hundred "file numbers" turned out to be old batch/section codes
 *    reused across hundreds of unrelated clients (not real per-file IDs) --
 *    each repeat gets " (n)" appended so matters.matter_number/physical_files.barcode
 *    stay unique without merging unrelated matters together.
 *  - Every matter gets practice_area = "General" and is assigned to one
 *    placeholder "dummy" managing partner created by this script -- both
 *    meant to be corrected by hand afterward.
 *
 * Run with: php import_legacy_clients_matters.php path/to/clients_matters_clean.csv
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$csvPath = $argv[1] ?? null;
if (!$csvPath || !is_file($csvPath)) {
    fwrite(STDERR, "Usage: php import_legacy_clients_matters.php path/to/clients_matters_clean.csv\n");
    exit(1);
}

$pdo = custodia_db();

$adminStmt = $pdo->prepare("SELECT * FROM users WHERE email = 'sam.okafor@custodia.demo'");
$adminStmt->execute();
$admin = $adminStmt->fetch();
if (!$admin) {
    fwrite(STDERR, "Seed admin account (sam.okafor@custodia.demo) not found -- run seed.php first.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    // 1. Clear existing matters/clients/physical data (children before parents;
    // matter_team_members/ethical_walls/practice_group_matter_grants cascade
    // automatically when their matter is deleted). audit_log is never touched.
    foreach ([
        'document_checkouts', 'share_links', 'document_versions', 'digital_documents',
        'custody_movements', 'physical_files',
        'access_requests',
        'matters', 'clients', 'physical_locations',
    ] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }

    // 2. The dummy managing partner every imported matter is assigned to.
    $dummyId = custodia_uuid();
    $pdo->prepare(
        'INSERT INTO users (id, employee_id, full_name, email, password_hash, role, is_active)
         VALUES (:id, :emp, :name, :email, NULL, "PARTNER", 1)'
    )->execute([
        'id' => $dummyId,
        'emp' => 'EMP-0000',
        'name' => 'UNASSIGNED PARTNER (bulk import placeholder)',
        'email' => 'unassigned-partner@placeholder.local',
    ]);

    $insertClient = $pdo->prepare('INSERT INTO clients (id, name) VALUES (:id, :name)');
    $insertLocation = $pdo->prepare(
        'INSERT INTO physical_locations (id, building, room, shelf, bin, location_type)
         VALUES (:id, :building, :room, :shelf, :bin, "ACTIVE_SHELF")'
    );
    $insertMatter = $pdo->prepare(
        'INSERT INTO matters (id, matter_number, client_id, practice_area, managing_partner_id, confidentiality, open_date)
         VALUES (:id, :num, :client, "General", :mp, "STANDARD", COALESCE(:open_date, CURRENT_TIMESTAMP(6)))'
    );
    $insertTeamMember = $pdo->prepare(
        'INSERT INTO matter_team_members (id, matter_id, user_id, role_on_matter) VALUES (:id, :mid, :uid, "Managing Partner")'
    );
    $insertFile = $pdo->prepare(
        'INSERT INTO physical_files (id, matter_id, barcode, jacket_label, current_location_id, status, lifecycle_status)
         VALUES (:id, :mid, :barcode, :label, :loc, "IN_REGISTRY", "OPEN")'
    );

    $clientCache = [];
    $locationCache = [];
    $matterNumberSeen = [];
    $unknownClientId = null;

    $clientCount = 0;
    $matterCount = 0;
    $fileCount = 0;
    $locationCount = 0;
    $duplicateNumbers = 0;
    $rowsProcessed = 0;

    $fh = fopen($csvPath, 'r');
    $header = fgetcsv($fh);
    while (($row = fgetcsv($fh)) !== false) {
        $r = array_combine($header, $row);
        $clientName = trim($r['client']);
        $physicalFile = trim($r['physical_file']);
        $volumeNo = trim($r['volume_no']);
        $openDate = trim($r['open_date']);
        $locRaw = trim($r['loc_raw']);
        $locRoom = trim($r['loc_room']);
        $locShelf = trim($r['loc_shelf']);
        $locBin = trim($r['loc_bin']);
        $rowsProcessed++;

        // clients.name and matters.matter_number are UNIQUE under MySQL's
        // default case-INsensitive collation, so "sl/4990" and "SL/4990"
        // collide even though they differ as PHP strings — every lookup/dedupe
        // key below is normalized to uppercase for that reason, while the
        // original casing is still what actually gets stored.
        if ($clientName === '') {
            if ($unknownClientId === null) {
                $unknownClientId = custodia_uuid();
                $insertClient->execute(['id' => $unknownClientId, 'name' => 'UNKNOWN CLIENT (bulk import)']);
                $clientCount++;
            }
            $clientId = $unknownClientId;
        } else {
            $clientKey = mb_strtoupper($clientName);
            if (!isset($clientCache[$clientKey])) {
                $clientCache[$clientKey] = custodia_uuid();
                $insertClient->execute(['id' => $clientCache[$clientKey], 'name' => $clientName]);
                $clientCount++;
            }
            $clientId = $clientCache[$clientKey];
        }

        if ($physicalFile === '') {
            $matterNumber = 'UNFILED-' . str_pad((string) $rowsProcessed, 6, '0', STR_PAD_LEFT);
        } else {
            $matterNumber = $physicalFile;
            $matterKey = mb_strtoupper($matterNumber);
            if (isset($matterNumberSeen[$matterKey])) {
                $matterNumberSeen[$matterKey]++;
                $duplicateNumbers++;
                $matterNumber = $physicalFile . ' (' . $matterNumberSeen[$matterKey] . ')';
            } else {
                $matterNumberSeen[$matterKey] = 1;
            }
        }

        $matterId = custodia_uuid();
        $insertMatter->execute([
            'id' => $matterId,
            'num' => $matterNumber,
            'client' => $clientId,
            'mp' => $dummyId,
            'open_date' => $openDate !== '' ? $openDate : null,
        ]);
        $insertTeamMember->execute(['id' => custodia_uuid(), 'mid' => $matterId, 'uid' => $dummyId]);
        $matterCount++;

        if ($physicalFile !== '') {
            $locationId = null;
            if ($locRaw !== '') {
                if (!isset($locationCache[$locRaw])) {
                    $locId = custodia_uuid();
                    $insertLocation->execute([
                        'id' => $locId,
                        'building' => 'Main Registry',
                        // physical_locations.room is NOT NULL; loc_room comes
                        // back blank when the raw code didn't split into the
                        // usual 3-part section/shelf/bin pattern.
                        'room' => $locRoom !== '' ? $locRoom : 'Unspecified',
                        'shelf' => $locShelf !== '' ? $locShelf : $locRaw,
                        'bin' => $locBin !== '' ? $locBin : null,
                    ]);
                    $locationCache[$locRaw] = $locId;
                    $locationCount++;
                }
                $locationId = $locationCache[$locRaw];
            }
            $label = $clientName !== '' ? $clientName : 'Unknown client file';
            if ($volumeNo !== '') {
                $label .= " — Vol {$volumeNo}";
            }
            $insertFile->execute([
                'id' => custodia_uuid(),
                'mid' => $matterId,
                'barcode' => $matterNumber,
                'label' => mb_substr($label, 0, 255),
                'loc' => $locationId,
            ]);
            $fileCount++;
        }
    }
    fclose($fh);

    custodia_audit_record($pdo, [
        'actorId' => $admin['id'],
        'actionType' => 'MATTERS_BULK_IMPORTED',
        'entityType' => 'MATTER',
        'entityId' => 'legacy-registry-import',
        'ipAddress' => '127.0.0.1',
        'metadata' => [
            'clientsCreated' => $clientCount,
            'mattersCreated' => $matterCount,
            'physicalFilesCreated' => $fileCount,
            'locationsCreated' => $locationCount,
            'duplicateFileNumbersDisambiguated' => $duplicateNumbers,
            'dummyPartnerId' => $dummyId,
        ],
    ]);

    $pdo->commit();

    echo "Done.\n";
    echo "Clients created: {$clientCount}\n";
    echo "Matters created: {$matterCount}\n";
    echo "Physical files created: {$fileCount}\n";
    echo "Physical locations created: {$locationCount}\n";
    echo "Duplicate file-number rows disambiguated: {$duplicateNumbers}\n";
    echo "Dummy managing partner id: {$dummyId} (UNASSIGNED PARTNER (bulk import placeholder), EMP-0000)\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
