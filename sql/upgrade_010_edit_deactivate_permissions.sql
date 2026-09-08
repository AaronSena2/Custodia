-- Custodia — upgrade script 010: edit/deactivate permissions for Clients and Matters.
--
-- Run this ONCE against an existing `custodia` database that predates these.
-- Adds is_active/deactivated_at to clients (mirroring users), and grants the
-- four new permission keys (deactivate_matters, edit_clients,
-- deactivate_clients — edit_matters/create_clients already exist from
-- earlier upgrades) to SYSTEM_ADMIN, matching the newly-enforced checks in
-- includes/matters.php and includes/clients.php. Other roles can be granted
-- these from Admin → Permissions afterward.
--
-- A brand-new install does NOT need this file — sql/schema.sql,
-- includes/permissions.php's CUSTODIA_DEFAULT_ROLE_PERMISSIONS, and seed.php
-- already cover it.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

ALTER TABLE clients
  ADD COLUMN is_active      TINYINT(1)  NOT NULL DEFAULT 1 AFTER name,
  ADD COLUMN deactivated_at DATETIME(6) NULL AFTER is_active;

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
  ('SYSTEM_ADMIN', 'deactivate_matters'),
  ('SYSTEM_ADMIN', 'edit_clients'),
  ('SYSTEM_ADMIN', 'deactivate_clients');
