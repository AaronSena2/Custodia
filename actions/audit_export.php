<?php
/**
 * CSV export — a plain GET (not the JSON action_bootstrap convention) since
 * the browser needs to navigate/download rather than receive a fetch()
 * response. custodia_audit_export_csv() still writes its own AUDIT_EXPORT
 * audit entry, same as every other state-changing call in this app.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/audit_query.php';

$user = custodia_current_user();
if (!$user) {
    http_response_code(401);
    exit('Sign in required.');
}

$pdo = custodia_db();
$query = [
    'actorId' => $_GET['actorId'] ?? null,
    'entityType' => $_GET['entityType'] ?? null,
    'actionType' => $_GET['actionType'] ?? null,
    'matterId' => $_GET['matterId'] ?? null,
    'q' => $_GET['q'] ?? null,
    'from' => $_GET['from'] ?? null,
    'to' => $_GET['to'] ?? null,
];

try {
    $csv = custodia_audit_export_csv($pdo, $user, $query, custodia_client_ip());
} catch (CustodiaHttpException $e) {
    http_response_code($e->status);
    exit($e->getMessage());
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="custodia-audit-export.csv"');
echo $csv;
