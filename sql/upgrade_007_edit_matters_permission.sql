-- Custodia — upgrade script 007: "Edit Matter Details" permission.
--
-- Run this ONCE against an existing `custodia` database that predates this
-- permission key. Adds edit_matters to role_permissions for SYSTEM_ADMIN
-- only, matching the newly-enforced check in includes/matters.php
-- (custodia_update_matter). Other roles can be granted this from Admin →
-- Permissions afterward.
--
-- A brand-new install does NOT need this file — sql/schema.sql,
-- includes/permissions.php's CUSTODIA_DEFAULT_ROLE_PERMISSIONS, and seed.php
-- already cover it.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
  ('SYSTEM_ADMIN', 'edit_matters');
