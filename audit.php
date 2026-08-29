<?php
/**
 * Audit Log now lives as a tab within Admin (admin.php?tab=audit) rather
 * than its own top-level page — see includes/permissions.php's
 * view_audit_log entry. This redirect preserves old bookmarks/links and
 * carries the filter query string over unchanged.
 */
$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: admin.php?tab=audit' . ($query !== '' ? '&' . $query : ''));
exit;
