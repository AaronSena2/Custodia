-- Upgrade 020 — forced-password-reset infrastructure
-- Security review 2026-09-03, finding 1.1 (CRITICAL): the shared demo
-- password "ChangeMe123!" was published in plain text, together with
-- every account's email (including the System Administrator's), on the
-- public sign-in page. login.php no longer prints that list, but the
-- password itself must be treated as compromised and is very likely
-- still active on most real accounts.
--
-- This script adds the schema support for forcing a reset; it does NOT
-- rotate any passwords by itself. After running this against the live
-- database:
--   1. From the app's directory, run:
--        php jobs/rotate_all_passwords.php
--      This generates a fresh, unique password per active user and
--      flags every account for a forced reset. The temporary passwords
--      print to the console only — nothing is written to disk or logged.
--   2. Share each temporary password with its user ONE AT A TIME, out of
--      band (phone, in person, a direct message) — never by re-posting
--      the whole list anywhere shared.
--   3. Each user is redirected to change_password.php the next time they
--      sign in and cannot reach any other page until they set their own
--      password (see includes/auth.php's custodia_require_login()).
--
-- Run this file the same way as every prior upgrade script (phpMyAdmin's
-- SQL tab against the `custodia` database, or:
--   mysql -u custodia -p custodia < sql/upgrade_020_force_password_reset.sql

ALTER TABLE users
  ADD COLUMN must_reset_password TINYINT(1) NOT NULL DEFAULT 0
    AFTER mfa_enabled;

-- Every currently active account is known (or very plausibly still set)
-- to the shared "ChangeMe123!" password and must set its own on next
-- sign-in, even before jobs/rotate_all_passwords.php has been run.
UPDATE users SET must_reset_password = 1 WHERE is_active = 1;
