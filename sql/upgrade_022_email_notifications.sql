-- ─────────────────────────────────────────────────────────────────────
-- upgrade_022 — email notifications via Microsoft Graph
--
-- Run this against the live `custodia` database (phpMyAdmin → SQL tab, or
-- the mysql CLI) BEFORE deploying the PHP files for this feature. Safe to
-- re-run: every statement is IF NOT EXISTS / INSERT IGNORE.
--
--   mysql -u custodia -p custodia < sql/upgrade_022_email_notifications.sql
--
-- Nothing sends mail until an admin fills in Admin → Email Settings and
-- ticks "Send email notifications" there — this migration only creates the
-- places for that configuration and the outbound queue to live.
-- ─────────────────────────────────────────────────────────────────────

-- ─── app_settings ───────────────────────────────────────────────────
-- Generic admin-editable key/value configuration, for the settings that
-- must be changeable at runtime rather than fixed in includes/config.php's
-- env vars (which need a shell and a restart to change). Currently used
-- only by the email feature, but deliberately not named email_* so a
-- future admin-editable setting has an obvious home.
--
-- is_secret = 1 means setting_value holds a libsodium-encrypted blob, not
-- plain text — see custodia_setting_get()/custodia_setting_set() in
-- includes/app_settings.php. A secret value is NEVER read back into a form
-- field, returned by any JSON endpoint, or written into audit metadata.
CREATE TABLE IF NOT EXISTS app_settings (
  setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
  setting_value MEDIUMTEXT   NULL,
  is_secret     TINYINT(1)   NOT NULL DEFAULT 0,
  updated_by_id CHAR(36)     NULL,
  updated_at    DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_app_settings_user FOREIGN KEY (updated_by_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── email_outbox ───────────────────────────────────────────────────
-- Store-and-forward queue. A row is inserted in the SAME transaction as the
-- notification and audit entry that caused it (see custodia_notify_user()),
-- so "the custody movement happened" and "the person was queued to be told"
-- commit or roll back together — exactly the guarantee blueprint §4.1 asks
-- for between a state change and its audit record. Actually talking to
-- Microsoft happens later, in jobs/send_email_queue.php, so a mail-service
-- outage can never roll back a custody transfer.
--
-- This table doubles as the delivery record: "was this person told, and
-- when" is a query, which matters for a system whose whole point is
-- provable process.
CREATE TABLE IF NOT EXISTS email_outbox (
  id                CHAR(36)     NOT NULL PRIMARY KEY,
  user_id           CHAR(36)     NULL,       -- NULL for a non-user recipient
  to_email          VARCHAR(255) NOT NULL,
  subject           VARCHAR(255) NOT NULL,
  body_text         MEDIUMTEXT   NOT NULL,
  body_html         MEDIUMTEXT   NULL,
  notification_type VARCHAR(50)  NOT NULL,
  entity_type       VARCHAR(30)  NULL,
  entity_id         CHAR(36)     NULL,
  matter_id         CHAR(36)     NULL,       -- denormalised: the send-time ethical-wall / confidentiality re-check needs it without re-resolving entity_type
  status            ENUM('PENDING','SENT','FAILED','SKIPPED') NOT NULL DEFAULT 'PENDING',
  skip_reason       VARCHAR(255) NULL,       -- why a SKIPPED row was not sent (wall raised, undeliverable address, prefs off, rate limit)
  attempts          INT UNSIGNED NOT NULL DEFAULT 0,
  last_error        TEXT         NULL,
  next_attempt_at   DATETIME(6)  NULL,       -- exponential backoff after a failure; NULL means "eligible now"
  created_at        DATETIME(6)  NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  sent_at           DATETIME(6)  NULL,
  CONSTRAINT fk_email_outbox_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_email_outbox_pending (status, next_attempt_at),
  INDEX idx_email_outbox_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── per-user email preferences ─────────────────────────────────────
-- Deliberately two columns on users rather than a preferences table: with
-- no real traffic yet there's nothing to base per-notification-type
-- defaults on, and guessing at them would just produce settings nobody
-- tuned. Security alerts ignore both of these for Admin/Records Manager
-- recipients — see custodia_email_pref_allows() in includes/email_prefs.php.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_notifications_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER mfa_enabled;
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_digest_only TINYINT(1) NOT NULL DEFAULT 0 AFTER email_notifications_enabled;

-- ─── permission ─────────────────────────────────────────────────────
-- New manage_email_settings capability, seeded to SYSTEM_ADMIN only. Like
-- every other row in role_permissions this is admin-editable afterwards
-- from Admin → Permissions; seeding it here just means the feature is
-- reachable by an administrator the moment the files are deployed.
INSERT IGNORE INTO role_permissions (role, permission_key)
  SELECT 'SYSTEM_ADMIN', 'manage_email_settings'
  WHERE EXISTS (SELECT 1 FROM roles WHERE role_key = 'SYSTEM_ADMIN');
