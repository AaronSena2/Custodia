-- Custodia — upgrade script 005: admin-configurable role permission matrix.
--
-- Run this ONCE against an existing `custodia` database that predates this
-- table. Creates role_permissions and seeds it with defaults that exactly
-- match this app's original hardcoded role checks (create a matter, approve
-- a movement, export the audit log, etc. — see includes/permissions.php's
-- docblock for the full list and what it deliberately does NOT cover) — so
-- installing this changes no behavior until an admin visits Admin →
-- Permissions and edits the matrix.
--
-- A brand-new install does NOT need this file — sql/schema.sql and seed.php
-- already include everything below.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
  role           ENUM('SYSTEM_ADMIN','RECORDS_MANAGER','PARTNER','ASSOCIATE','PARALEGAL','GUEST_AUDITOR') NOT NULL,
  permission_key VARCHAR(64) NOT NULL,
  PRIMARY KEY (role, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
  ('SYSTEM_ADMIN', 'manage_users'),
  ('SYSTEM_ADMIN', 'manage_retention_policies'),
  ('SYSTEM_ADMIN', 'create_matters'),
  ('SYSTEM_ADMIN', 'register_physical_files'),
  ('SYSTEM_ADMIN', 'approve_custody_movements'),
  ('SYSTEM_ADMIN', 'auto_approve_checkout'),
  ('SYSTEM_ADMIN', 'override_custody'),
  ('SYSTEM_ADMIN', 'override_document_locks'),
  ('SYSTEM_ADMIN', 'transfer_on_behalf'),
  ('SYSTEM_ADMIN', 'decide_access_requests'),
  ('SYSTEM_ADMIN', 'export_audit_log'),
  ('SYSTEM_ADMIN', 'verify_audit_chain'),
  ('RECORDS_MANAGER', 'manage_retention_policies'),
  ('RECORDS_MANAGER', 'create_matters'),
  ('RECORDS_MANAGER', 'register_physical_files'),
  ('RECORDS_MANAGER', 'approve_custody_movements'),
  ('RECORDS_MANAGER', 'auto_approve_checkout'),
  ('RECORDS_MANAGER', 'override_custody'),
  ('RECORDS_MANAGER', 'override_document_locks'),
  ('RECORDS_MANAGER', 'transfer_on_behalf'),
  ('RECORDS_MANAGER', 'decide_access_requests'),
  ('RECORDS_MANAGER', 'export_audit_log'),
  ('PARTNER', 'create_matters'),
  ('PARTNER', 'approve_custody_movements'),
  ('PARTNER', 'auto_approve_checkout'),
  ('PARTNER', 'decide_access_requests'),
  ('PARTNER', 'export_audit_log'),
  ('ASSOCIATE', 'auto_approve_checkout');
