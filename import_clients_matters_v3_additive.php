<?php
/**
 * One-off, admin-only, browser-run ADDITIVE import of the rows in
 * clients_matters_new_only.csv — the genuinely new content from a second
 * spreadsheet ("client and files second.xlsx"), after diffing it against
 * the first spreadsheet already imported by import_clients_matters_v2.php.
 *
 * Unlike v2, this script deletes NOTHING. It assumes the first import
 * already ran (its own preview below shows current counts so that can be
 * confirmed before proceeding) and adds only what wasn't there before:
 *  - The second spreadsheet turned out to be the first one plus more: all
 *    42 original client sheets were byte-identical except one (Church of
 *    Jesus Christ LDS, which had 9 new rows appended), plus 55 entirely
 *    new client sheets. clients_matters_new_only.csv is the result of
 *    diffing every row (client, title, physical_file, volume_no,
 *    open_date, loc_raw) against the first spreadsheet's own parsed rows
 *    — 1,872 exact matches were dropped, leaving 2,401 genuinely new rows
 *    across 56 clients (55 new + Church of Jesus Christ LDS's 9 new rows).
 *
 * Because this only adds rows, it has to look up and reuse whatever
 * already exists rather than creating fresh (client name, physical
 * location by shelf code, the placeholder managing partner, and — the
 * part that needs care — matter_number/barcode disambiguation, which now
 * has to continue from whatever suffix numbers the live database already
 * has for a repeated physical-file code, not start over from 1).
 *
 * GET shows a confirmation page with current vs. incoming counts; nothing
 * is touched until that form is POSTed (CSRF-protected). A lighter backup
 * (clients/matters/physical_files/physical_locations only — the tables
 * this script touches) is still taken first, purely as insurance.
 *
 * DELETE THIS FILE (and clients_matters_new_only.csv) once you've
 * confirmed the import looks right — re-running it is harmless (it will
 * find zero new rows and add nothing) but there's no reason to leave a
 * data-import tool sitting in the app long-term.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/helpers.php';

$user = custodia_require_login();
custodia_require_role($user, ['SYSTEM_ADMIN']);

$csvPath = __DIR__ . '/clients_matters_new_only.csv';

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><title>Import: additional clients &amp; matters</title>';
echo '<style>body{font-family:system-ui,sans-serif;background:#0b1220;color:#e6edf3;padding:2rem;max-width:760px;margin:0 auto}
.ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}.card{background:#111a2e;border:1px solid #24314f;border-radius:8px;padding:1.5rem;margin-top:1rem}
a{color:#2dd4bf}code{background:#1a2540;padding:0.15rem 0.4rem;border-radius:4px}ul{margin:0.5rem 0 0;padding-left:1.25rem}li{margin:0.35rem 0}
table{border-collapse:collapse;width:100%;margin-top:0.75rem}td,th{border:1px solid #24314f;padding:0.4rem 0.6rem;text-align:left}
button{background:#0d9488;color:#fff;border:none;border-radius:6px;padding:0.7rem 1.4rem;font-size:1rem;cursor:pointer;margin-top:1rem}
button:hover{background:#0f766e}</style></head><body>';
echo '<h1>Add new clients &amp; matters</h1>';

if (!is_file($csvPath)) {
    echo '<div class="card"><p class="err"><strong>Cannot find <code>clients_matters_new_only.csv</code></strong> next to this script.</p></div></body></html>';
    exit;
}

function custodia_addimport_table_counts(PDO $pdo): array
{
    $tables = ['clients', 'matters', 'physical_files', 'physical_locations'];
    $counts = [];
    foreach ($tables as $t) {
        $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    }
    return $counts;
}

function custodia_addimport_csv_preview(string $csvPath): array
{
    $fh = fopen($csvPath, 'r');
    $header = fgetcsv($fh);
    $rows = 0;
    $clients = [];
    $missingFile = 0;
    while (($row = fgetcsv($fh)) !== false) {
        $r = array_combine($header, $row);
        $rows++;
        $clients[$r['client']] = true;
        if (trim($r['physical_file']) === '') {
            $missingFile++;
        }
    }
    fclose($fh);
    return ['rows' => $rows, 'clients' => count($clients), 'missingFile' => $missingFile];
}

/** Backs up the tables this script can modify, before any INSERT runs. */
function custodia_addimport_backup(PDO $pdo, string $backupDir, string $backupsRoot): void
{
    if (!mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
        throw new RuntimeException("Could not create backup directory: {$backupDir}");
    }
    $htaccess = $backupsRoot . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }
    foreach (['clients', 'matters', 'physical_files', 'physical_locations'] as $table) {
        $stmt = $pdo->query("SELECT * FROM {$table}");
        $out = fopen($backupDir . '/' . $table . '.csv', 'w');
        $first = true;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($first) {
                fputcsv($out, array_keys($row));
                $first = false;
            }
            fputcsv($out, $row);
        }
        if ($first) {
            $cols = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
            fputcsv($out, $cols);
        }
        fclose($out);
    }
}

/**
 * Guards against accidentally running this twice: since matter_number
 * disambiguation always finds a fresh " (n)" suffix, a second run would
 * NOT hit a duplicate-key error — it would silently insert a second full
 * copy of every row under new numbers. This checks the audit log for this
 * script's own past run instead of relying on that constraint.
 */
function custodia_addimport_already_ran(PDO $pdo): ?string
{
    $stmt = $pdo->prepare(
        "SELECT created_at FROM audit_log
         WHERE action_type = 'MATTERS_BULK_IMPORTED' AND entity_id = 'client-files-second-additive-import'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute();
    $ts = $stmt->fetchColumn();
    return $ts !== false ? (string) $ts : null;
}

/**
 * Parses "BASE" or "BASE (N)" back into [baseUppercase, N] (N=1 for the
 * bare form), so existing disambiguated matter_numbers/barcodes can seed
 * the same counter this batch continues from.
 */
function custodia_addimport_parse_suffix(string $value): array
{
    if (preg_match('/^(.*) \((\d+)\)$/', $value, $m)) {
        return [mb_strtoupper($m[1]), (int) $m[2]];
    }
    return [mb_strtoupper($value), 1];
}

function custodia_run_additive_import(PDO $pdo, string $csvPath, array $admin): array
{
    $pdo->beginTransaction();
    try {
        // Seed the disambiguation counter from every matter_number already
        // in the database (not just this batch) — a repeated physical-file
        // code in the new rows has to continue past whatever the first
        // import already used, e.g. "SL/81/11/A (18)" already existing
        // means the next one here must be "(19)", not "(2)".
        $matterNumberSeen = [];
        foreach ($pdo->query('SELECT matter_number FROM matters')->fetchAll(PDO::FETCH_COLUMN) as $existing) {
            [$base, $n] = custodia_addimport_parse_suffix($existing);
            $matterNumberSeen[$base] = max($matterNumberSeen[$base] ?? 0, $n);
        }

        // Same placeholder used by the destructive v2 import — reuse it
        // rather than creating a second one.
        $dummyStmt = $pdo->prepare("SELECT id FROM users WHERE email = 'unassigned-partner-v2@placeholder.local'");
        $dummyStmt->execute();
        $dummyId = $dummyStmt->fetchColumn();
        if ($dummyId === false) {
            $dummyId = custodia_uuid();
            $pdo->prepare(
                'INSERT INTO users (id, employee_id, full_name, email, password_hash, role, is_active)
                 VALUES (:id, :emp, :name, :email, NULL, "PARTNER", 1)'
            )->execute([
                'id' => $dummyId,
                'emp' => 'EMP-0000-V2',
                'name' => 'UNASSIGNED PARTNER (bulk import placeholder)',
                'email' => 'unassigned-partner-v2@placeholder.local',
            ]);
        }

        $findClient = $pdo->prepare('SELECT id FROM clients WHERE name = :name');
        $insertClient = $pdo->prepare('INSERT INTO clients (id, name) VALUES (:id, :name)');
        $findLocation = $pdo->prepare(
            "SELECT id FROM physical_locations WHERE building = 'Main Registry' AND room = 'Unspecified' AND shelf = :shelf"
        );
        $insertLocation = $pdo->prepare(
            'INSERT INTO physical_locations (id, building, room, shelf, bin, location_type)
             VALUES (:id, :building, :room, :shelf, NULL, "ACTIVE_SHELF")'
        );
        $insertMatter = $pdo->prepare(
            'INSERT INTO matters (id, matter_number, client_id, practice_area, managing_partner_id, confidentiality, open_date)
             VALUES (:id, :num, :client, "General", :mp, "STANDARD", COALESCE(:open_date, CURRENT_TIMESTAMP(6)))'
        );
        $insertTeamMember = $pdo->prepare(
            'INSERT INTO matter_team_members (id, matter_id, user_id, role_on_matter) VALUES (:id, :mid, :uid, "Incharge")'
        );
        $insertFile = $pdo->prepare(
            'INSERT INTO physical_files (id, matter_id, barcode, jacket_label, current_location_id, status, lifecycle_status)
             VALUES (:id, :mid, :barcode, :label, :loc, "IN_REGISTRY", "OPEN")'
        );

        $clientCache = [];
        $locationCache = [];

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
            $title = trim($r['title']);
            $physicalFile = trim($r['physical_file']);
            $volumeNo = trim($r['volume_no']);
            $openDate = trim($r['open_date']);
            $locRaw = trim($r['loc_raw']);
            $rowsProcessed++;

            if ($clientName === '') {
                $clientName = 'UNKNOWN CLIENT (bulk import)';
            }
            $clientKey = mb_strtoupper($clientName);
            if (!isset($clientCache[$clientKey])) {
                $findClient->execute(['name' => $clientName]);
                $existingClientId = $findClient->fetchColumn();
                if ($existingClientId !== false) {
                    $clientCache[$clientKey] = $existingClientId;
                } else {
                    $clientCache[$clientKey] = custodia_uuid();
                    $insertClient->execute(['id' => $clientCache[$clientKey], 'name' => $clientName]);
                    $clientCount++;
                }
            }
            $clientId = $clientCache[$clientKey];

            if ($physicalFile === '') {
                $matterNumber = 'UNFILED-' . str_pad((string) $rowsProcessed, 6, '0', STR_PAD_LEFT) . '-B2';
            } else {
                $matterKey = mb_strtoupper($physicalFile);
                if (isset($matterNumberSeen[$matterKey])) {
                    $matterNumberSeen[$matterKey]++;
                    $duplicateNumbers++;
                    $matterNumber = $physicalFile . ' (' . $matterNumberSeen[$matterKey] . ')';
                } else {
                    $matterNumberSeen[$matterKey] = 1;
                    $matterNumber = $physicalFile;
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
                        $findLocation->execute(['shelf' => $locRaw]);
                        $existingLocId = $findLocation->fetchColumn();
                        if ($existingLocId !== false) {
                            $locationCache[$locRaw] = $existingLocId;
                        } else {
                            $locId = custodia_uuid();
                            $insertLocation->execute([
                                'id' => $locId,
                                'building' => 'Main Registry',
                                'room' => 'Unspecified',
                                'shelf' => $locRaw,
                            ]);
                            $locationCache[$locRaw] = $locId;
                            $locationCount++;
                        }
                    }
                    $locationId = $locationCache[$locRaw];
                }
                $label = $title !== '' ? $title : ($clientName !== '' ? $clientName : 'Unknown file');
                if ($volumeNo !== '') {
                    $label .= " — Volume: {$volumeNo}";
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
            'entityId' => 'client-files-second-additive-import',
            'ipAddress' => custodia_client_ip(),
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

        return compact('clientCount', 'matterCount', 'fileCount', 'locationCount', 'duplicateNumbers', 'dummyId');
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

$pdo = custodia_db();
$confirmed = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === '1');
$alreadyRan = custodia_addimport_already_ran($pdo);

if ($confirmed && $alreadyRan !== null) {
    echo '<div class="card"><p class="err"><strong>This import already ran on ' . e($alreadyRan) . '.</strong></p>';
    echo '<p>Re-running would insert a second copy of every row under new matter numbers, since the disambiguation logic always finds a fresh number rather than detecting the exact duplicate. Nothing was changed just now.</p>';
    echo '<p>If you genuinely need to re-import (e.g. the first run was rolled back or partially undone some other way), remove this safeguard\'s check in the script, or ask for a version that re-diffs against the current database instead of a fixed CSV.</p></div></body></html>';
    exit;
}

if (!$confirmed) {
    $current = custodia_addimport_table_counts($pdo);
    $incoming = custodia_addimport_csv_preview($csvPath);

    if ($alreadyRan !== null) {
        echo '<div class="card"><p class="err"><strong>This import already ran on ' . e($alreadyRan) . '.</strong> Running it again would duplicate every row. The form below is disabled.</p></div>';
    }

    echo '<div class="card"><p class="warn"><strong>This ADDS the new rows below on top of what\'s already in the system — nothing existing is deleted or changed.</strong></p>';
    echo '<p>If the "Currently in the system" numbers below look empty or much smaller than expected, the earlier full replacement (import_clients_matters_v2.php) may not have been run yet — check that first before confirming this one, since this script assumes that data is already there and only adds what\'s new on top of it.</p>';
    echo '<p>A backup of clients/matters/physical files/locations is still written to disk first, purely as insurance.</p></div>';

    echo '<div class="card"><h3 style="margin-top:0">Currently in the system</h3><table>';
    echo '<tr><th>Clients</th><td>' . number_format($current['clients']) . '</td></tr>';
    echo '<tr><th>Matters</th><td>' . number_format($current['matters']) . '</td></tr>';
    echo '<tr><th>Physical files</th><td>' . number_format($current['physical_files']) . '</td></tr>';
    echo '<tr><th>Physical locations</th><td>' . number_format($current['physical_locations']) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="card"><h3 style="margin-top:0">New rows to add from clients_matters_new_only.csv</h3><table>';
    echo '<tr><th>Clients touched (new or existing)</th><td>' . number_format($incoming['clients']) . '</td></tr>';
    echo '<tr><th>Matters (rows) to add</th><td>' . number_format($incoming['rows']) . '</td></tr>';
    echo '<tr><th>Rows with no physical file (matter only, no file record)</th><td>' . number_format($incoming['missingFile']) . '</td></tr>';
    echo '</table></div>';

    if ($alreadyRan === null) {
        echo '<form method="post">';
        echo custodia_csrf_field();
        echo '<input type="hidden" name="confirm" value="1">';
        echo '<button type="submit" onclick="return confirm(\'This adds the new spreadsheet rows on top of what\\\'s already there. A backup is taken first. Continue?\')">Yes — back up and add the new data</button>';
        echo '</form>';
    }
    echo '</body></html>';
    exit;
}

custodia_require_csrf();
set_time_limit(180);

try {
    $backupsRoot = __DIR__ . '/backups';
    $backupDir = $backupsRoot . '/pre_additive_import_' . date('Ymd_His');
    custodia_addimport_backup($pdo, $backupDir, $backupsRoot);

    $result = custodia_run_additive_import($pdo, $csvPath, $user);

    echo '<div class="card"><p class="ok"><strong>Done.</strong></p><ul>';
    echo '<li>Backup of the prior data: <code>' . e(str_replace(__DIR__ . '/', '', $backupDir)) . '</code></li>';
    echo '<li>New clients created: ' . number_format($result['clientCount']) . '</li>';
    echo '<li>Matters added: ' . number_format($result['matterCount']) . '</li>';
    echo '<li>Physical files added: ' . number_format($result['fileCount']) . '</li>';
    echo '<li>Locations created: ' . number_format($result['locationCount']) . '</li>';
    echo '<li>Duplicate file-number rows disambiguated (" (n)" suffix, continuing from what already existed): ' . number_format($result['duplicateNumbers']) . '</li>';
    echo '<li>Every new matter is assigned to the same placeholder managing partner used before, "UNASSIGNED PARTNER (bulk import placeholder)."</li>';
    echo '</ul></div>';
    echo '<p style="margin-top:1.5rem">You can now <a href="matters.php">view the matters list</a> or go <a href="admin.php">back to Admin</a>. Once you\'ve confirmed everything looks right, delete this file and <code>clients_matters_new_only.csv</code> from the app folder.</p>';
} catch (Throwable $e) {
    echo '<div class="card"><p class="err"><strong>Something went wrong — nothing was changed (the transaction was rolled back), and the backup above (if it completed) is still on disk:</strong></p>';
    echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre></div>';
}

echo '</body></html>';
