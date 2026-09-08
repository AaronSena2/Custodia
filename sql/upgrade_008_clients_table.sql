-- Custodia — upgrade script 008: Client → Matter → File hierarchy (clients table).
--
-- Run this ONCE against an existing `custodia` database that predates this
-- table. Adds `clients`, backfills one client row per distinct existing
-- matters.client_name value, points matters.client_id at it, then drops the
-- old free-text column. New/edited matters resolve their client via
-- custodia_find_or_create_client() (includes/clients.php) — see that file's
-- docblock for how typing an existing vs. new client name behaves.
--
-- A brand-new install does NOT need this file — sql/schema.sql and seed.php
-- already include everything below.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS clients (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  name       VARCHAR(255) NOT NULL UNIQUE,
  created_at DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO clients (id, name)
SELECT UUID(), t.name FROM (SELECT DISTINCT client_name AS name FROM matters) t
WHERE NOT EXISTS (SELECT 1 FROM clients c WHERE c.name = t.name);

ALTER TABLE matters ADD COLUMN client_id CHAR(36) NULL AFTER client_name;

UPDATE matters m JOIN clients c ON c.name = m.client_name SET m.client_id = c.id;

ALTER TABLE matters MODIFY COLUMN client_id CHAR(36) NOT NULL;
ALTER TABLE matters ADD CONSTRAINT fk_matters_client FOREIGN KEY (client_id) REFERENCES clients(id);
ALTER TABLE matters ADD INDEX idx_matters_client (client_id);
ALTER TABLE matters DROP COLUMN client_name;
