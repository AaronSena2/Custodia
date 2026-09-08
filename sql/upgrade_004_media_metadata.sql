-- Custodia — upgrade script 004: audio/video duration metadata.
--
-- Run this ONCE against an existing `custodia` database that predates this
-- column. Adds duration_seconds to document_versions, captured at upload
-- time by includes/media_metadata.php for audio/video files. No backfill —
-- versions uploaded before this existed simply show no duration, which is
-- honest (their exact duration was never computed) rather than guessed.
--
-- A brand-new install does NOT need this file — sql/schema.sql already
-- includes everything below.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

ALTER TABLE document_versions
  ADD COLUMN duration_seconds DECIMAL(10,2) NULL AFTER mime_type;
