-- Custodia — upgrade script 009: "Create Clients" permission.
--
-- Run this ONCE against an existing `custodia` database that predates this
-- permission key. Adds create_clients to role_permissions for the same
-- roles that get create_matters by default (SYSTEM_ADMIN, RECORDS_MANAGER,
-- PARTNER), matching the newly-enforced check in includes/clients.php
-- (custodia_create_client). Other roles can be granted it from Admin →
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
  ('SYSTEM_ADMIN', 'create_clients'),
  ('RECORDS_MANAGER', 'create_clients'),
  ('PARTNER', 'create_clients');
