-- Custodia — upgrade script 016: Notifications + Shared With Me.
--
-- Adds a real persisted notification inbox (read/unread), fed by the
-- existing access-grant flows (custodia_decide_access_request(),
-- custodia_admin_grant_matter_access()/custodia_admin_grant_document_access()
-- in includes/access_requests.php, and custodia_grant_group_matter_access()
-- in includes/practice_groups.php). "Shared With Me" itself needs no new
-- schema — it reads the existing access_requests table.
--
-- Run this ONCE against an existing `custodia` database that predates this
-- feature — a brand-new install doesn't need it (sql/schema.sql already
-- includes the table).
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

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
