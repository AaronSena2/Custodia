<?php
/**
 * A dependency-free smoke test run against a REAL MySQL/MariaDB instance
 * (not a mock/stub) — exercising the audit hash-chain, the three-layer RBAC
 * check, and the custody state machine end-to-end. Run after `php seed.php`
 * against a throwaway database:
 *
 *   php tests/smoke.php
 *
 * This is deliberately plain assert-and-print rather than a PHPUnit suite,
 * matching the "simpler stack" goal driving this rebuild — no Composer
 * dependency required to get real confidence the core logic works.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/matter_access.php';
require_once __DIR__ . '/../includes/practice_groups.php';
require_once __DIR__ . '/../includes/custody.php';
require_once __DIR__ . '/../includes/digital_documents.php';
require_once __DIR__ . '/../includes/text_extract.php';
require_once __DIR__ . '/../includes/text_diff.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = custodia_db();
$pass = 0;
$fail = 0;

function check(string $label, bool $condition): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}\n";
    }
}

function fetch_user(PDO $pdo, string $email): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();
    $row['practice_group_names'] = custodia_list_user_group_names($pdo, $row['id']);
    return $row;
}

function fetch_matter_by_number(PDO $pdo, string $number): array
{
    $stmt = $pdo->prepare('SELECT * FROM matters WHERE matter_number = :n');
    $stmt->execute(['n' => $number]);
    return $stmt->fetch();
}

function fetch_file_by_barcode(PDO $pdo, string $barcode): array
{
    $stmt = $pdo->prepare('SELECT * FROM physical_files WHERE barcode = :b');
    $stmt->execute(['b' => $barcode]);
    return $stmt->fetch();
}

$sam = fetch_user($pdo, 'sam.okafor@custodia.demo');       // SYSTEM_ADMIN
$rita = fetch_user($pdo, 'rita.alvarez@custodia.demo');    // RECORDS_MANAGER
$daniel = fetch_user($pdo, 'daniel.reyes@custodia.demo');  // PARTNER, managing partner of Northgate
$priya = fetch_user($pdo, 'priya.nair@custodia.demo');     // PARTNER, Litigation
$elena = fetch_user($pdo, 'elena.cho@custodia.demo');      // ASSOCIATE, on Northgate team
$marcus = fetch_user($pdo, 'marcus.webb@custodia.demo');   // PARALEGAL, on Northgate team, walled from Bellweather

$northgate = fetch_matter_by_number($pdo, 'M-2024-0187'); // RESTRICTED
$bellweather = fetch_matter_by_number($pdo, 'M-2024-0142'); // STANDARD, Marcus walled

echo "== Audit hash-chain ==\n";
$pdo->beginTransaction();
$entry1 = custodia_audit_record($pdo, ['actorId' => $sam['id'], 'actionType' => 'TEST_EVENT_1', 'entityType' => 'TEST', 'entityId' => 'x', 'ipAddress' => '127.0.0.1']);
$entry2 = custodia_audit_record($pdo, ['actorId' => $sam['id'], 'actionType' => 'TEST_EVENT_2', 'entityType' => 'TEST', 'entityId' => 'x', 'ipAddress' => '127.0.0.1', 'reason' => 'because', 'metadata' => ['foo' => 'bar']]);
$pdo->commit();

check('second entry chains to first entry\'s hash', $entry2['prevHash'] === $entry1['entryHash']);

$verify = custodia_audit_verify_chain($pdo);
check('chain verifies as valid after two clean writes', $verify['valid'] === true);

// Tamper with a row directly and confirm verifyChainIntegrity() catches it.
$pdo->prepare('UPDATE audit_log SET reason = :r WHERE id = :id')->execute(['r' => 'TAMPERED', 'id' => $entry2['id']]);
$verifyTampered = custodia_audit_verify_chain($pdo);
check('chain verification detects a tampered reason field', $verifyTampered['valid'] === false && $verifyTampered['brokenAtId'] === $entry2['id']);
// Restore it so later assertions on this table aren't affected.
$pdo->prepare('UPDATE audit_log SET reason = :r WHERE id = :id')->execute(['r' => 'because', 'id' => $entry2['id']]);
custodia_audit_verify_chain($pdo); // sanity: doesn't throw

echo "\n== Three-layer RBAC (matter access) ==\n";
try {
    custodia_assert_matter_access($pdo, $marcus, $bellweather['id']);
    check('ethical wall denies Marcus on Bellweather', false);
} catch (CustodiaHttpException $e) {
    check('ethical wall denies Marcus on Bellweather', $e->status === 403 && str_contains($e->getMessage(), 'walled'));
}

try {
    custodia_assert_matter_access($pdo, $priya, $northgate['id']);
    check('non-team Partner denied on RESTRICTED matter outside their practice area', false);
} catch (CustodiaHttpException $e) {
    check('non-team Partner denied on RESTRICTED matter outside their practice area', $e->status === 403);
}

$elenaAccess = custodia_assert_matter_access($pdo, $elena, $northgate['id']);
check('team member (Elena) allowed on RESTRICTED matter despite non-STANDARD confidentiality', is_array($elenaAccess));

$samAccess = custodia_assert_matter_access($pdo, $sam, $northgate['id']);
check('firm-wide role (System Admin) allowed on any matter', is_array($samAccess));

// Document search must respect the same RBAC — checked here, before the access
// request below is approved and legitimately opens Northgate to Priya.
$hitsForPriyaBeforeApproval = custodia_search_documents($pdo, $priya, 'Purchase Price');
check('search respects three-layer RBAC — a Partner outside the matter sees nothing', count($hitsForPriyaBeforeApproval) === 0);

// Approve an access request for Priya on Northgate, then confirm both gates open.
$arId = custodia_uuid();
$pdo->prepare("INSERT INTO access_requests (id, requester_id, entity_type, entity_id, request_type, reason, status, approver_id, decided_at) VALUES (:id, :req, 'MATTER', :mid, 'VIEW_CONFIDENTIAL', 'test', 'APPROVED', :app, NOW(6))")
    ->execute(['id' => $arId, 'req' => $priya['id'], 'mid' => $northgate['id'], 'app' => $daniel['id']]);
$priyaAfterApproval = custodia_assert_matter_access($pdo, $priya, $northgate['id']);
check('an APPROVED access request satisfies both the confidentiality and assignment gates', is_array($priyaAfterApproval));

echo "\n== Custody state machine ==\n";
$pf1 = fetch_file_by_barcode($pdo, 'PF-000482912'); // IN_REGISTRY, Northgate
check('seeded file starts IN_REGISTRY', $pf1['status'] === 'IN_REGISTRY');

// Marcus (Paralegal — not in auto-approved roles) checks it out: should land PENDING_APPROVAL, file untouched.
$dueBack = (new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
$movement = custodia_checkout($pdo, $marcus, $pf1['id'], 'Reviewing pleadings.', $dueBack, '127.0.0.1');
check('Paralegal checkout is not auto-approved', $movement['status'] === 'PENDING_APPROVAL');

$pf1AfterRequest = fetch_file_by_barcode($pdo, 'PF-000482912');
check('file stays IN_REGISTRY while checkout is pending approval', $pf1AfterRequest['status'] === 'IN_REGISTRY');

// Rita (Records Manager) approves it.
custodia_approve_movement($pdo, $rita, $movement['id'], '127.0.0.1');
$pf1AfterApproval = fetch_file_by_barcode($pdo, 'PF-000482912');
check('file becomes CHECKED_OUT after approval', $pf1AfterApproval['status'] === 'CHECKED_OUT' && $pf1AfterApproval['current_custodian_id'] === $marcus['id']);

// Marcus checks it back in.
$roomLocation = $pdo->query("SELECT id FROM physical_locations WHERE building = 'Building A' AND room = '204' LIMIT 1")->fetch();
custodia_checkin($pdo, $marcus, $pf1['id'], $roomLocation['id'], null, '127.0.0.1');
$pf1AfterCheckin = fetch_file_by_barcode($pdo, 'PF-000482912');
check('file returns to IN_REGISTRY after self check-in', $pf1AfterCheckin['status'] === 'IN_REGISTRY' && $pf1AfterCheckin['current_custodian_id'] === null);

// Daniel (auto-approved Partner) checks the same file out directly.
$movement2 = custodia_checkout($pdo, $daniel, $pf1['id'], 'Client call prep.', $dueBack, '127.0.0.1');
check('Partner checkout auto-approves immediately', $movement2['status'] === 'COMPLETED');
$pf1AfterAutoCheckout = fetch_file_by_barcode($pdo, 'PF-000482912');
check('file is CHECKED_OUT immediately for an auto-approved role', $pf1AfterAutoCheckout['status'] === 'CHECKED_OUT');

// Elena (not the custodian) requests the file Daniel currently holds.
$transfer = custodia_request_transfer($pdo, $elena, $pf1['id'], 'Handing off for drafting.', '127.0.0.1');
check('transfer request lands PENDING_APPROVAL', $transfer['status'] === 'PENDING_APPROVAL');

$pf1WhilePending = fetch_file_by_barcode($pdo, 'PF-000482912');
check('file stays with its current custodian while the transfer request is pending', $pf1WhilePending['status'] === 'CHECKED_OUT' && $pf1WhilePending['current_custodian_id'] === $daniel['id']);

// Marcus has matter access but isn't the custodian — he may not approve it, even as a team member.
try {
    custodia_approve_movement($pdo, $marcus, $transfer['id'], '127.0.0.1');
    check('only the current custodian may approve a transfer request', false);
} catch (CustodiaHttpException $e) {
    check('only the current custodian may approve a transfer request', $e->status === 403);
}

// Daniel, the current custodian, approves — this completes the transfer immediately, no separate confirmation step.
custodia_approve_movement($pdo, $daniel, $transfer['id'], '127.0.0.1');
$pf1AfterTransfer = fetch_file_by_barcode($pdo, 'PF-000482912');
check('custodian approval makes the requester the new custodian', $pf1AfterTransfer['status'] === 'CHECKED_OUT' && $pf1AfterTransfer['current_custodian_id'] === $elena['id']);

echo "\n== Document management (iManage-style: numbering, search, comparison) ==\n";

function fetch_document_by_title(PDO $pdo, string $title): array
{
    $stmt = $pdo->prepare('SELECT * FROM digital_documents WHERE title = :t');
    $stmt->execute(['t' => $title]);
    return $stmt->fetch();
}

$apaDoc = fetch_document_by_title($pdo, 'Asset Purchase Agreement — Execution Draft');
check('seeded document has a positive, unique doc_number', (int) $apaDoc['doc_number'] > 0);
check('custodia_doc_label formats as CUS-NNNNNN.V', custodia_doc_label((int) $apaDoc['doc_number'], 2) === 'CUS-' . str_pad((string) $apaDoc['doc_number'], 6, '0', STR_PAD_LEFT) . '.2');
check('doc profile carries author/creator from seed', $apaDoc['author_id'] === $daniel['id'] && $apaDoc['created_by_id'] === $marcus['id']);

// -- Text extraction: plain text (real path used by .txt/.md uploads) --
$tmpTxt = tempnam(sys_get_temp_dir(), 'custodia_test_') . '.txt';
file_put_contents($tmpTxt, "Confidentiality Clause\n\nThe parties agree to keep the terms of this Agreement strictly confidential.");
$txtResult = custodia_extract_text_for_upload($tmpTxt, 'confidentiality-clause.txt');
check('plain-text extraction succeeds and preserves content', $txtResult['status'] === 'DONE' && str_contains($txtResult['text'], 'strictly confidential'));
unlink($tmpTxt);

// -- Text extraction: unsupported binary type --
$tmpBin = tempnam(sys_get_temp_dir(), 'custodia_test_') . '.exe';
file_put_contents($tmpBin, "\x4D\x5A\x00\x00binary-garbage");
$binResult = custodia_extract_text_for_upload($tmpBin, 'installer.exe');
check('unsupported file type is flagged UNSUPPORTED, not silently guessed at', $binResult['status'] === 'UNSUPPORTED' && $binResult['text'] === null);
unlink($tmpBin);

// -- Text extraction: DOCX (real zip+XML structure, same as Word produces) --
$tmpDocx = tempnam(sys_get_temp_dir(), 'custodia_test_') . '.docx';
$zip = new ZipArchive();
$zip->open($tmpDocx, ZipArchive::CREATE);
$zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
$zip->addFromString('word/document.xml',
    '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
    . '<w:p><w:r><w:t>Governing Law</w:t></w:r></w:p>'
    . '<w:p><w:r><w:t>This Agreement is governed by Delaware law.</w:t></w:r></w:p>'
    . '</w:body></w:document>');
$zip->close();
$docxResult = custodia_extract_text_for_upload($tmpDocx, 'governing-law.docx');
check('DOCX extraction reads real paragraph text out of word/document.xml', $docxResult['status'] === 'DONE'
    && str_contains($docxResult['text'], 'Governing Law') && str_contains($docxResult['text'], 'Delaware law'));
unlink($tmpDocx);

// -- Full-text search, RBAC-scoped --
$hitsForMarcus = custodia_search_documents($pdo, $marcus, 'Thirteen Million Five Hundred');
check('search finds the seeded document by its current version\'s extracted text', count($hitsForMarcus) === 1 && $hitsForMarcus[0]['id'] === $apaDoc['id']);

$hitsForOldAmount = custodia_search_documents($pdo, $marcus, 'Twelve Million');
check('search does not match text only present in an older, non-current version', count($hitsForOldAmount) === 0);

$hitsByDocNumber = custodia_search_documents($pdo, $marcus, (string) $apaDoc['doc_number']);
check('search matches by bare doc number', count($hitsByDocNumber) === 1 && $hitsByDocNumber[0]['id'] === $apaDoc['id']);

// -- Version comparison / redline diff --
$compareResult = custodia_compare_document_versions($pdo, $daniel, $apaDoc['id'], 1, 2);
check('version comparison succeeds when both versions have extracted text', $compareResult['comparable'] === true);

$hasReplaceOp = false;
$hasDollarChange = false;
foreach ($compareResult['diff']['ops'] ?? [] as $op) {
    if ($op['type'] === 'replace') {
        $hasReplaceOp = true;
        $rendered = implode(' ', array_column($op['words'], 'value'));
        if (str_contains($rendered, '12,000,000') && str_contains($rendered, '13,500,000')) {
            $hasDollarChange = true;
        }
    }
}
check('diff produces a word-level replace op for the edited paragraph', $hasReplaceOp);
check('diff surfaces the actual changed purchase price on both sides', $hasDollarChange);

// A quick, isolated diff-engine check independent of seed data.
$isolatedDiff = custodia_diff_versions("Paragraph one.\n\nThe fee is $100.", "Paragraph one.\n\nThe fee is $200.");
$isolatedHasReplace = false;
foreach ($isolatedDiff['ops'] as $op) {
    if ($op['type'] === 'replace') {
        $isolatedHasReplace = true;
    }
}
check('diff engine correctly treats an unchanged first paragraph as equal', $isolatedDiff['ops'][0]['type'] === 'equal');
check('diff engine detects a one-word change in the second paragraph as a replace', $isolatedHasReplace);

echo "\n" . str_repeat('=', 40) . "\n";
echo "Results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
