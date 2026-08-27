<?php
/**
 * Common wrapper for actions/*.php — the AJAX endpoints the Bootstrap/JS
 * frontend POSTs to. Every action file is just:
 *
 *   require __DIR__ . '/../includes/action_bootstrap.php';
 *   custodia_run_action(function (PDO $pdo, array $user) {
 *       // ... call into includes/custody.php, includes/matter_access.php, etc.
 *       return ['id' => $result['id']];
 *   });
 *
 * This centralizes: session start, login requirement (401 JSON if absent),
 * CSRF check on POST, and turning CustodiaHttpException into the right HTTP
 * status + {"error": "..."} body so the frontend JS has one response shape
 * to handle everywhere.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/helpers.php';

function custodia_run_action(callable $fn): void
{
    header('Content-Type: application/json');
    custodia_start_session();

    $user = custodia_current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'You must be signed in.']);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        custodia_require_csrf(); // exits with 419 JSON itself on failure
    }

    try {
        $result = $fn(custodia_db(), $user);
        echo json_encode(['ok' => true, 'data' => $result]);
    } catch (CustodiaHttpException $e) {
        http_response_code($e->status);
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('[custodia] unhandled action error: ' . $e->getMessage());
        echo json_encode(['error' => 'Unexpected server error. Please try again.']);
    }
}

/** Reads a required string field from $_POST, throwing a 400 if missing/blank. */
function custodia_required_post(string $key): string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    if ($value === '') {
        throw custodia_bad_request("Missing required field: {$key}");
    }
    return $value;
}
