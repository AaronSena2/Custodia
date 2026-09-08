-- Custodia — upgrade script 018: automated weekly operations report.
--
-- Adds the system_reports table backing the new "Reports" page and the
-- scheduled jobs/weekly_report.php job — a firm-wide digest (new matters/
-- clients, overdue files, pending approvals, activity) delivered to System
-- Admin/Records Manager via the existing in-app notification system.
--
-- Run this ONCE against an existing `custodia` database that predates this
-- feature — a brand-new install doesn't need it (sql/schema.sql already
-- includes the table).
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

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
