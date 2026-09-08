-- Custodia — upgrade script 006: admin-extensible roles.
--
-- Run this ONCE against an existing `custodia` database that predates this.
-- Converts users.role and role_permissions.role from a fixed ENUM to a
-- VARCHAR referencing a new `roles` table, so a System Administrator can
-- create new roles from Admin → Permissions instead of being limited to the
-- six original ones. Existing role values are preserved exactly — this only
-- changes the column's type, not any user's actual role — and the six
-- original roles are seeded into `roles` with is_builtin = 1 before the FK
-- constraints are added, so nothing is left dangling.
--
-- A brand-new install does NOT need this file — sql/schema.sql and seed.php
-- already include everything below.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
  role_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
  label      VARCHAR(100) NOT NULL,
  is_builtin TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO roles (role_key, label, is_builtin) VALUES
  ('SYSTEM_ADMIN', 'System Administrator', 1),
  ('RECORDS_MANAGER', 'Records Manager', 1),
  ('PARTNER', 'Partner', 1),
  ('ASSOCIATE', 'Associate', 1),
  ('PARALEGAL', 'Paralegal', 1),
  ('GUEST_AUDITOR', 'Guest / Auditor', 1);

ALTER TABLE users MODIFY COLUMN role VARCHAR(64) NOT NULL;
ALTER TABLE users ADD CONSTRAINT fk_users_role FOREIGN KEY (role) REFERENCES roles(role_key);

ALTER TABLE role_permissions MODIFY COLUMN role VARCHAR(64) NOT NULL;
ALTER TABLE role_permissions ADD CONSTRAINT fk_role_permissions_role FOREIGN KEY (role) REFERENCES roles(role_key);
