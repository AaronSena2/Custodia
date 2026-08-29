<?php
require __DIR__ . '/../includes/action_bootstrap.php';
require_once __DIR__ . '/../includes/chatbot.php';

// Read-only guidance lookup, no state change — no custodia_audit_record()
// call, same as actions/mark_notification_read.php.
custodia_run_action(function (PDO $pdo, array $user) {
    $message = custodia_required_post('message');
    return custodia_chatbot_find_answer($message);
});
