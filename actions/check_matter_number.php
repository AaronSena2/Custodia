<?php
/**
 * Instant duplicate-matter-number check — polled (debounced) by the "New
 * Matter" fields as the user types, so a collision is flagged before they
 * ever hit submit rather than surfacing only as a rejected form. Read-only,
 * GET-only (no CSRF needed — see includes/action_bootstrap.php, which only
 * enforces the token on POST), gated on nothing beyond being signed in:
 * matter numbers are already visible to any non-Guest/Auditor user via the
 * matters list itself, so confirming one exists here isn't a new disclosure.
 */
require __DIR__ . '/../includes/action_bootstrap.php';

custodia_run_action(function (PDO $pdo, array $user) {
    $matterNumber = trim($_GET['matterNumber'] ?? '');
    if ($matterNumber === '') {
        return ['exists' => false];
    }

    $stmt = $pdo->prepare('SELECT id FROM matters WHERE matter_number = :num');
    $stmt->execute(['num' => $matterNumber]);
    return ['exists' => (bool) $stmt->fetch()];
});
