-- Custodia — upgrade script 015: Protected Digital Documents.
--
-- Adds an opt-in per-document "Protected" flag: viewing a protected document
-- requires its own explicit access grant (an access_requests row scoped to
-- that document, same mechanism as confidential-matter access), that grant
-- can expire, and downloading is disabled outright regardless of the grant
-- (view-only). See includes/digital_documents.php's
-- custodia_assert_document_view_access() and custodia_download_document_version().
--
-- Run this ONCE against an existing `custodia` database that predates this
-- feature — a brand-new install doesn't need it (sql/schema.sql already
-- includes both columns, and includes/permissions.php's
-- CUSTODIA_DEFAULT_ROLE_PERMISSIONS already grants the new permission).
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

ALTER TABLE digital_documents ADD COLUMN IF NOT EXISTS is_protected TINYINT(1) NOT NULL DEFAULT 0;

-- Nullable — every existing APPROVED access_requests row (matter or
-- document) keeps working with no expiry unless one is set going forward.
ALTER TABLE access_requests ADD COLUMN IF NOT EXISTS expires_at DATETIME(6) NULL;

INSERT IGNORE INTO role_permissions (role, permission_key) VALUES
  ('SYSTEM_ADMIN', 'override_document_protection'),
  ('RECORDS_MANAGER', 'override_document_protection');
