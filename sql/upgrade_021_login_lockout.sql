-- Upgrade 021 — account lockout + idle-session timeout support
-- Security review 2026-09-03, finding 1.6 (HIGH): no failed-login
-- throttling and no idle-session timeout, on an app whose admin password
-- had just been found published in plain text (finding 1.1) — credential
-- attacks against login.php were free, and a signed-in session on an
-- unattended/shared workstation stayed valid indefinitely.
--
-- Idle-session timeout needs no schema change (tracked in the PHP session
-- itself — see includes/auth.php's custodia_current_user()). This script
-- only adds the two columns account lockout needs.
--
-- Run this file the same way as every prior upgrade script (phpMyAdmin's
-- SQL tab against the `custodia` database, or:
--   mysql -u custodia -p custodia < sql/upgrade_021_login_lockout.sql

ALTER TABLE users
  ADD COLUMN failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0
    AFTER must_reset_password,
  ADD COLUMN locked_until DATETIME(6) NULL
    AFTER failed_login_attempts;
