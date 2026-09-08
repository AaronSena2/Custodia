-- Custodia — Legal File Registry & Movement Tracking System
-- MySQL / MariaDB schema (PHP/SQL/Bootstrap rebuild).
-- Mirrors the data model of the original NestJS/Prisma version 1:1 in shape;
-- column names are snake_case per SQL convention. UUIDs are generated in
-- PHP (bin2hex(random_bytes) formatted as a UUIDv4 string), not DB-side,
-- so application code always knows the id of a row it just created.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── roles ──────────────────────────────────────────────────────────
-- The role catalog — admin-extensible (Admin → Permissions → "+ New Role")
-- via includes/roles.php. The six original roles are seeded with
-- is_builtin = 1; that flag exists purely as a marker for anything that
-- ever needs to distinguish "one of the roles this app was designed
-- around" from "an admin-added one" — nothing currently enforces different
-- behavior based on it. A newly created role starts with zero rows in
-- role_permissions (every capability unchecked) and, deliberately, no
-- special standing in the deeper per-matter RBAC in matter_access.php —
-- it sees only matters it's explicitly assigned to, the same as Associate/
-- Paralegal today. See includes/roles.php's docblock for the full reasoning.
CREATE TABLE IF NOT EXISTS roles (
  role_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
  label      VARCHAR(100) NOT NULL,
  is_builtin TINYINT(1)   NOT NULL DEFAULT 0,
  created_at DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── users ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  employee_id     VARCHAR(50)  NOT NULL UNIQUE,
  full_name       VARCHAR(255) NOT NULL,
  email           VARCHAR(255) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NULL,        -- demo local auth; swap point for OIDC (see includes/auth.php)
  sso_subject_id  VARCHAR(255) NULL UNIQUE,
  role            VARCHAR(64)  NOT NULL,    -- FK to roles.role_key — was a fixed ENUM before admin-extensible roles existed
  bar_number      VARCHAR(50)  NULL,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  mfa_enabled     TINYINT(1)   NOT NULL DEFAULT 0,
  must_reset_password TINYINT(1) NOT NULL DEFAULT 0, -- forces change_password.php before any other page (security review 2026-09-03, finding 1.1)
  failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, -- consecutive failed sign-ins since the last success/lock (security review 2026-09-03, finding 1.6)
  locked_until         DATETIME(6)  NULL, -- set once failed_login_attempts hits the configured threshold; NULL means not locked
  created_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  deactivated_at  DATETIME(6)  NULL,
  CONSTRAINT fk_users_role FOREIGN KEY (role) REFERENCES roles(role_key),
  INDEX idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── role_permissions ───────────────────────────────────────────────
-- Admin-configurable role capability matrix (Admin → Permissions). One row
-- per granted (role, permission) pair; a role with no row for a given
-- permission_key simply doesn't have it. permission_key values come from
-- the CUSTODIA_PERMISSIONS catalog in includes/permissions.php (a fixed
-- list in code, not a DB table, since the set of capabilities the app
-- understands is part of the codebase, not admin-editable data).
CREATE TABLE IF NOT EXISTS role_permissions (
  role           VARCHAR(64) NOT NULL, -- FK to roles.role_key
  permission_key VARCHAR(64) NOT NULL,
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role) REFERENCES roles(role_key),
  PRIMARY KEY (role, permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── clients ────────────────────────────────────────────────────────
-- Top of the Client → Matter → File hierarchy, with its own profile page
-- (client.php) showing bio data + the client's matters. Matters are opened
-- against an existing client picked from a dropdown (see matters.php/
-- matter.php) rather than free-text — custodia_find_or_create_client()
-- still exists for seed.php's convenience, but the matter create/edit forms
-- no longer call it.
CREATE TABLE IF NOT EXISTS clients (
  id             CHAR(36)     NOT NULL PRIMARY KEY,
  name           VARCHAR(255) NOT NULL UNIQUE,
  email          VARCHAR(255) NULL,
  phone          VARCHAR(50)  NULL,
  address        TEXT         NULL,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  deactivated_at DATETIME(6)  NULL,
  created_at     DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── matters ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS matters (
  id                  CHAR(36)     NOT NULL PRIMARY KEY,
  matter_number       VARCHAR(100) NOT NULL UNIQUE,
  client_id           CHAR(36)     NOT NULL,
  practice_area       VARCHAR(100) NOT NULL,
  managing_partner_id CHAR(36)     NOT NULL,
  status              ENUM('ACTIVE','ON_HOLD','CLOSED','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
  confidentiality     ENUM('STANDARD','RESTRICTED','PRIVILEGED') NOT NULL DEFAULT 'STANDARD',
  open_date           DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  close_date          DATETIME(6)  NULL,
  CONSTRAINT fk_matters_client FOREIGN KEY (client_id) REFERENCES clients(id),
  CONSTRAINT fk_matters_managing_partner FOREIGN KEY (managing_partner_id) REFERENCES users(id),
  INDEX idx_matters_client (client_id),
  INDEX idx_matters_status (status),
  INDEX idx_matters_confidentiality (confidentiality),
  INDEX idx_matters_practice_area (practice_area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── matter_team_members ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS matter_team_members (
  id             CHAR(36)     NOT NULL PRIMARY KEY,
  matter_id      CHAR(36)     NOT NULL,
  user_id        CHAR(36)     NOT NULL,
  role_on_matter VARCHAR(100) NOT NULL, -- "Managing Partner" | "Associate" | "Paralegal" ...
  added_at       DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_mtm_matter FOREIGN KEY (matter_id) REFERENCES matters(id) ON DELETE CASCADE,
  CONSTRAINT fk_mtm_user FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uq_mtm_matter_user (matter_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── ethical_walls ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ethical_walls (
  id         CHAR(36)    NOT NULL PRIMARY KEY,
  matter_id  CHAR(36)    NOT NULL,
  user_id    CHAR(36)    NOT NULL,
  reason     TEXT        NOT NULL,
  created_by CHAR(36)    NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_ew_matter FOREIGN KEY (matter_id) REFERENCES matters(id) ON DELETE CASCADE,
  CONSTRAINT fk_ew_user FOREIGN KEY (user_id) REFERENCES users(id),
  UNIQUE KEY uq_ew_matter_user (matter_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── physical_locations ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS physical_locations (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  building      VARCHAR(255) NOT NULL,
  room          VARCHAR(100) NOT NULL,
  shelf         VARCHAR(50)  NULL,
  bin           VARCHAR(50)  NULL,
  location_type VARCHAR(50)  NOT NULL -- ACTIVE_SHELF | ARCHIVE_ROOM | OFFSITE_FACILITY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── physical_files ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS physical_files (
  id                   CHAR(36)     NOT NULL PRIMARY KEY,
  matter_id            CHAR(36)     NOT NULL,
  barcode              VARCHAR(100) NOT NULL UNIQUE,
  jacket_label         VARCHAR(255) NOT NULL,
  current_location_id  CHAR(36)     NULL,
  current_custodian_id CHAR(36)     NULL,
  -- Custody status (labeled "Custody" in the UI) — where the file physically
  -- is / who has it. Independent of lifecycle_status below: a file can be
  -- e.g. CHECKED_OUT + CLOSED, or IN_REGISTRY + OPEN.
  status               ENUM('IN_REGISTRY','CHECKED_OUT','IN_TRANSIT','OFFSITE_ARCHIVE','PENDING_DESTRUCTION','DESTROYED') NOT NULL DEFAULT 'IN_REGISTRY',
  -- Lifecycle status (labeled "Status" in the UI) — whether this is still an
  -- open working file or has been closed out, separate from custody.
  lifecycle_status     ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  created_at           DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pf_matter FOREIGN KEY (matter_id) REFERENCES matters(id),
  CONSTRAINT fk_pf_location FOREIGN KEY (current_location_id) REFERENCES physical_locations(id),
  CONSTRAINT fk_pf_custodian FOREIGN KEY (current_custodian_id) REFERENCES users(id),
  INDEX idx_pf_matter (matter_id),
  INDEX idx_pf_status (status),
  INDEX idx_pf_lifecycle_status (lifecycle_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── custody_movements ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS custody_movements (
  id               CHAR(36)    NOT NULL PRIMARY KEY,
  physical_file_id CHAR(36)    NOT NULL,
  movement_type    ENUM('CHECK_OUT','CHECK_IN','TRANSFER','ARCHIVE','RECALL') NOT NULL,
  from_user_id     CHAR(36)    NULL,
  to_user_id       CHAR(36)    NULL,
  from_location_id CHAR(36)    NULL,
  to_location_id   CHAR(36)    NULL,
  requested_by_id  CHAR(36)    NOT NULL,
  approved_by_id   CHAR(36)    NULL,
  reason           TEXT        NOT NULL,
  due_back_at      DATETIME(6) NULL,
  status           ENUM('PENDING_APPROVAL','PENDING_CONFIRMATION','APPROVED','REJECTED','COMPLETED','OVERDUE') NOT NULL DEFAULT 'PENDING_APPROVAL',
  is_override      TINYINT(1)  NOT NULL DEFAULT 0,
  requested_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  completed_at     DATETIME(6) NULL,
  CONSTRAINT fk_cm_file FOREIGN KEY (physical_file_id) REFERENCES physical_files(id),
  CONSTRAINT fk_cm_to_user FOREIGN KEY (to_user_id) REFERENCES users(id),
  CONSTRAINT fk_cm_requested_by FOREIGN KEY (requested_by_id) REFERENCES users(id),
  CONSTRAINT fk_cm_approved_by FOREIGN KEY (approved_by_id) REFERENCES users(id),
  INDEX idx_cm_file (physical_file_id),
  INDEX idx_cm_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── digital_documents ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS digital_documents (
  id                      CHAR(36)     NOT NULL PRIMARY KEY,
  -- Firm-wide sequential number, independent of the UUID primary key —
  -- this is what makes the iManage-style "CUS-000142.3" document label
  -- possible. A secondary AUTO_INCREMENT key (not the PK) is a standard,
  -- supported MySQL/MariaDB pattern as long as it has its own key.
  doc_number              INT          NOT NULL AUTO_INCREMENT,
  matter_id               CHAR(36)     NOT NULL,
  linked_physical_file_id CHAR(36)     NULL,
  title                   VARCHAR(255) NOT NULL,
  description             TEXT         NULL,
  doc_type                VARCHAR(100) NOT NULL,
  confidentiality         ENUM('STANDARD','RESTRICTED','PRIVILEGED') NOT NULL DEFAULT 'STANDARD',
  -- Opt-in per-document lock: viewing requires its own access_requests grant
  -- (entity_type DIGITAL_DOCUMENT) on top of ordinary matter access, and
  -- downloading is disabled outright regardless of that grant — see
  -- custodia_assert_document_view_access()/custodia_download_document_version().
  is_protected            TINYINT(1)   NOT NULL DEFAULT 0,
  retention_flag          TINYINT(1)   NOT NULL DEFAULT 0,
  retention_date          DATETIME(6)  NULL,
  current_version_no      INT          NOT NULL DEFAULT 0,
  -- "Author" is an editable profile field (who wrote/owns the document,
  -- iManage-style) distinct from "created_by", which is immutable —
  -- whoever actually created the document record.
  author_id               CHAR(36)     NULL,
  created_by_id           CHAR(36)     NULL,
  created_at              DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_dd_matter FOREIGN KEY (matter_id) REFERENCES matters(id),
  CONSTRAINT fk_dd_author FOREIGN KEY (author_id) REFERENCES users(id),
  CONSTRAINT fk_dd_created_by FOREIGN KEY (created_by_id) REFERENCES users(id),
  UNIQUE KEY uq_dd_doc_number (doc_number),
  INDEX idx_dd_matter (matter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── document_versions ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS document_versions (
  id                 CHAR(36)     NOT NULL PRIMARY KEY,
  document_id        CHAR(36)     NOT NULL,
  version_number     INT          NOT NULL,
  storage_key        VARCHAR(500) NOT NULL, -- filesystem path under storage/ in this scaffold; swap point for S3
  sha256_hash        CHAR(64)     NOT NULL,
  file_size_bytes    BIGINT       NOT NULL,
  -- Captured at upload time via finfo (not trusted from the client) so
  -- actions/download_document.php can serve correct headers without having
  -- to sniff the file or guess from storage_key — see includes/storage.php.
  original_filename  VARCHAR(255) NULL,
  mime_type          VARCHAR(127) NULL,
  -- Audio/video only, captured at upload time — see includes/media_metadata.php.
  duration_seconds   DECIMAL(10,2) NULL,
  ocr_text           LONGTEXT     NULL,
  ocr_status         VARCHAR(20)  NOT NULL DEFAULT 'PENDING', -- PENDING | PROCESSING | DONE | FAILED — scanned/image content; real OCR provider is a documented swap point
  -- Plain-text content pulled directly out of the uploaded file (DOCX/PDF/TXT)
  -- at upload time, real and working (not a stub) — see includes/text_extract.php.
  -- Powers full-text search via the FULLTEXT index below and feeds version comparison.
  extracted_text     LONGTEXT     NULL,
  extraction_status  VARCHAR(20)  NOT NULL DEFAULT 'PENDING', -- PENDING | DONE | UNSUPPORTED | FAILED
  uploaded_by_id     CHAR(36)     NOT NULL,
  uploaded_at        DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_dv_document FOREIGN KEY (document_id) REFERENCES digital_documents(id),
  CONSTRAINT fk_dv_uploaded_by FOREIGN KEY (uploaded_by_id) REFERENCES users(id),
  UNIQUE KEY uq_dv_document_version (document_id, version_number),
  FULLTEXT INDEX ftx_dv_extracted_text (extracted_text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── document_checkouts (edit lock) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS document_checkouts (
  id          CHAR(36)    NOT NULL PRIMARY KEY,
  document_id CHAR(36)    NOT NULL,
  user_id     CHAR(36)    NOT NULL,
  locked_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  expires_at  DATETIME(6) NOT NULL,
  released_at DATETIME(6) NULL,
  CONSTRAINT fk_dc_document FOREIGN KEY (document_id) REFERENCES digital_documents(id),
  CONSTRAINT fk_dc_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_dc_document (document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── share_links ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS share_links (
  id              CHAR(36)     NOT NULL PRIMARY KEY,
  document_id     CHAR(36)     NOT NULL,
  token           VARCHAR(255) NOT NULL UNIQUE,
  created_by_id   CHAR(36)     NOT NULL,
  permission      VARCHAR(20)  NOT NULL, -- VIEW | DOWNLOAD
  recipient_email VARCHAR(255) NULL,
  expires_at      DATETIME(6)  NOT NULL,
  access_count    INT          NOT NULL DEFAULT 0,
  revoked         TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_sl_document FOREIGN KEY (document_id) REFERENCES digital_documents(id),
  CONSTRAINT fk_sl_created_by FOREIGN KEY (created_by_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── access_requests ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS access_requests (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  requester_id  CHAR(36)     NOT NULL,
  entity_type   VARCHAR(30)  NOT NULL, -- MATTER | PHYSICAL_FILE | DIGITAL_DOCUMENT
  entity_id     CHAR(36)     NOT NULL,
  request_type  VARCHAR(30)  NOT NULL, -- VIEW_CONFIDENTIAL | CHECKOUT | TRANSFER
  reason        TEXT         NOT NULL,
  status        ENUM('PENDING','APPROVED','DENIED','REVOKED') NOT NULL DEFAULT 'PENDING',
  approver_id   CHAR(36)     NULL,
  decided_at    DATETIME(6)  NULL,
  -- NULL = grant never expires; otherwise the grant lapses at this instant
  -- even though status stays APPROVED (see custodia_user_has_active_document_view_grant()).
  expires_at    DATETIME(6)  NULL,
  created_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_ar_requester FOREIGN KEY (requester_id) REFERENCES users(id),
  CONSTRAINT fk_ar_approver FOREIGN KEY (approver_id) REFERENCES users(id),
  INDEX idx_ar_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── audit_log ──────────────────────────────────────────────────────
-- Hash-chained, append-only. metadata_json stores the EXACT string that was
-- hashed (see includes/audit.php) so verifyChainIntegrity() never has to
-- re-serialize JSON and risk a byte-for-byte mismatch from key reordering.
CREATE TABLE IF NOT EXISTS audit_log (
  id            CHAR(36)     NOT NULL PRIMARY KEY,
  actor_id      CHAR(36)     NOT NULL,
  action_type   VARCHAR(50)  NOT NULL,
  entity_type   VARCHAR(50)  NOT NULL,
  entity_id     CHAR(36)     NOT NULL,
  reason        TEXT         NULL,
  ip_address    VARCHAR(64)  NOT NULL,
  geo_location  VARCHAR(255) NULL,
  metadata_json TEXT         NOT NULL DEFAULT '{}',
  -- NULL on both means this row is deliberately outside the hash chain —
  -- see custodia_audit_record()'s 'chained' => false path (security review
  -- 2026-09-03, finding 4.3: routine VIEW events skip the chain to avoid
  -- serializing on audit_chain_state at high volume). Every other row still
  -- has both set, and custodia_audit_verify_chain() only ever walks those.
  prev_hash     CHAR(64)     NULL,
  entry_hash    CHAR(64)     NULL,
  created_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_al_actor FOREIGN KEY (actor_id) REFERENCES users(id),
  INDEX idx_al_entity (entity_type, entity_id),
  INDEX idx_al_actor (actor_id),
  INDEX idx_al_created (created_at),
  INDEX idx_al_action_type (action_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Singleton row whose sole job is to be SELECT ... FOR UPDATE'd at the start
-- of every audit_log write, serializing "read latest hash, then append" the
-- same way the original Postgres advisory-lock did — see includes/audit.php.
CREATE TABLE IF NOT EXISTS audit_chain_state (
  id        TINYINT     NOT NULL PRIMARY KEY DEFAULT 1,
  last_hash CHAR(64)    NOT NULL,
  CONSTRAINT chk_acs_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── retention_policies ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS retention_policies (
  id              CHAR(36)    NOT NULL PRIMARY KEY,
  practice_area   VARCHAR(100) NOT NULL,
  retention_years INT         NOT NULL,
  action          ENUM('REVIEW','ARCHIVE','DESTROY') NOT NULL,
  trigger_event   VARCHAR(100) NOT NULL -- e.g. "MATTER_CLOSE"
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── system_reports ─────────────────────────────────────────────────
-- Automated weekly operations digest for System Admin/Records Manager —
-- see jobs/weekly_report.php and includes/reports.php. content_json is a
-- flat snapshot (new matters/clients, overdue files, pending approvals,
-- activity counts), same "structured JSON in a TEXT column" pattern
-- audit_log.metadata_json already uses.
CREATE TABLE IF NOT EXISTS system_reports (
  id               CHAR(36)     NOT NULL PRIMARY KEY,
  report_type      VARCHAR(30)  NOT NULL DEFAULT 'WEEKLY_OPERATIONS',
  period_start     DATETIME(6)  NOT NULL,
  period_end       DATETIME(6)  NOT NULL,
  content_json     LONGTEXT     NOT NULL,
  generated_via    ENUM('SCHEDULED','MANUAL') NOT NULL DEFAULT 'SCHEDULED',
  generated_by_id  CHAR(36)     NOT NULL,
  created_at       DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_sr_generated_by FOREIGN KEY (generated_by_id) REFERENCES users(id),
  INDEX idx_sr_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── practice_groups ────────────────────────────────────────────────
-- Admin-managed catalog + real membership (Admin → Practice Groups,
-- manage_practice_groups permission). matters.practice_area and
-- retention_policies.practice_area stay plain text, not FKs to this table —
-- same "denormalized text, catalog is the reference list" relationship
-- clients had before it grew bio fields. Renaming a group here cascades to
-- update those free-text columns so they don't silently drift from the
-- catalog — see custodia_update_practice_group(). User membership, by
-- contrast, IS a real relation (practice_group_members below) — see
-- includes/practice_groups.php's docblock.
CREATE TABLE IF NOT EXISTS practice_groups (
  id         CHAR(36)     NOT NULL PRIMARY KEY,
  name       VARCHAR(100) NOT NULL UNIQUE,
  created_at DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS practice_group_members (
  id                CHAR(36)    NOT NULL PRIMARY KEY,
  practice_group_id CHAR(36)    NOT NULL,
  user_id           CHAR(36)    NOT NULL,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pgm_group FOREIGN KEY (practice_group_id) REFERENCES practice_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_pgm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_pgm_group_user (practice_group_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A whole group granted access to a matter — same standing as an
-- individually-approved access_requests row, checked alongside it in
-- custodia_assert_matter_access() and custodia_matters_for_user().
CREATE TABLE IF NOT EXISTS practice_group_matter_grants (
  id                CHAR(36)    NOT NULL PRIMARY KEY,
  practice_group_id CHAR(36)    NOT NULL,
  matter_id         CHAR(36)    NOT NULL,
  granted_by_id     CHAR(36)    NOT NULL,
  reason            TEXT        NULL,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pgmg_group FOREIGN KEY (practice_group_id) REFERENCES practice_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_pgmg_matter FOREIGN KEY (matter_id) REFERENCES matters(id) ON DELETE CASCADE,
  CONSTRAINT fk_pgmg_granted_by FOREIGN KEY (granted_by_id) REFERENCES users(id),
  UNIQUE KEY uq_pgmg_group_matter (practice_group_id, matter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── notifications ──────────────────────────────────────────────────
-- Persisted per-user inbox, fed by the access-grant flows in
-- includes/access_requests.php and includes/practice_groups.php. Distinct
-- from audit_log — mutable (read_at updates in place), not hash-chained.
CREATE TABLE IF NOT EXISTS notifications (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  user_id           CHAR(36)     NOT NULL,
  notification_type VARCHAR(50)  NOT NULL,
  title             VARCHAR(255) NOT NULL,
  body              TEXT         NULL,
  entity_type       VARCHAR(30)  NULL, -- MATTER | DIGITAL_DOCUMENT
  entity_id         CHAR(36)     NULL,
  read_at           DATETIME(6)  NULL,
  created_at        DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_notifications_user_unread (user_id, read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
