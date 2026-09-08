-- Custodia — upgrade script 013: Practice Areas admin catalog.
--
-- Run this ONCE against an existing `custodia` database that predates this
-- table. Creates practice_areas and backfills it with every distinct value
-- currently used in matters.practice_area, so Admin → Practice Areas isn't
-- empty on first load. matters.practice_area, users.practice_areas (JSON),
-- and retention_policies.practice_area stay plain text/JSON — this table is
-- the admin-managed reference list, not a foreign key target (see
-- includes/practice_areas.php's docblock for how a rename here cascades to
-- those free-text columns instead). Also grants the new
-- manage_practice_areas permission to SYSTEM_ADMIN.
--
-- A brand-new install does NOT need this file — sql/schema.sql, seed.php,
-- and includes/permissions.php's CUSTODIA_DEFAULT_ROLE_PERMISSIONS already
-- cover it.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS practice_areas (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  name       VARCHAR(100) NOT NULL UNIQUE,
  created_at DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO practice_areas (id, name)
SELECT UUID(), t.name FROM (SELECT DISTINCT practice_area AS name FROM matters) t
WHERE NOT EXISTS (SELECT 1 FROM practice_areas p WHERE p.name = t.name);

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
  ('SYSTEM_ADMIN', 'manage_practice_areas');
