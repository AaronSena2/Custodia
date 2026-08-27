<?php
/**
 * Downloadable CSV template for the Clients Bulk Import modal — a plain GET
 * (not the JSON action_bootstrap convention), same pattern as
 * audit_export.php. Static content, not real data, so it isn't audit-logged.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/permissions.php';

$user = custodia_current_user();
if (!$user) {
    http_response_code(401);
    exit('Sign in required.');
}

$pdo = custodia_db();
if (!custodia_user_has_permission($pdo, $user, 'create_clients')) {
    http_response_code(403);
    exit("You don't have the \"Create Clients\" permission.");
}

$lines = [
    'name,email,phone,address',
    '"Acme Corp","contact@acme.example","555-0100","123 Main St, Springfield"',
];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="custodia-client-import-template.csv"');
echo implode("\n", $lines) . "\n";
