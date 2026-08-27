<?php
/**
 * Downloadable CSV template for the User Accounts Bulk Import modal — a
 * plain GET (not the JSON action_bootstrap convention), same pattern as
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
if (!custodia_user_has_permission($pdo, $user, 'manage_users')) {
    http_response_code(403);
    exit("You don't have the \"Manage User Accounts\" permission.");
}

$lines = [
    'employee id,full name,email,role,bar number',
    '"EMP-1010","Jordan Lee","jordan.lee@example.com","Associate","NY-2021-4432"',
];

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="custodia-user-import-template.csv"');
echo implode("\n", $lines) . "\n";
