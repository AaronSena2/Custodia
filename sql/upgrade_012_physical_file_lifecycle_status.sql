-- Custodia — upgrade script 012: physical file lifecycle status (Open/Closed).
--
-- Run this ONCE against an existing `custodia` database that predates this
-- column. Adds physical_files.lifecycle_status, independent of the existing
-- `status` column (which tracks custody — In Registry/Checked Out/etc. —
-- and is now labeled "Custody" in the UI). A file can be, for example,
-- CHECKED_OUT + CLOSED, or IN_REGISTRY + OPEN — the two track different
-- things. Also grants the new close_physical_files permission to
-- SYSTEM_ADMIN, matching the newly-enforced check in
-- includes/physical_files.php.
--
-- A brand-new install does NOT need this file — sql/schema.sql,
-- includes/permissions.php's CUSTODIA_DEFAULT_ROLE_PERMISSIONS, and seed.php
-- already cover it.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

ALTER TABLE physical_files
  ADD COLUMN lifecycle_status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN' AFTER status,
  ADD INDEX idx_pf_lifecycle_status (lifecycle_status);

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
  ('SYSTEM_ADMIN', 'close_physical_files');
