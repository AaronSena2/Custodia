-- Custodia — upgrade script 003: stored file metadata (mime_type/original_filename).
--
-- Run this ONCE against an existing `custodia` database that predates these
-- columns. Adds original_filename/mime_type to document_versions so
-- actions/download_document.php can serve correct headers without sniffing
-- the file or guessing from storage_key, and backfills best-effort values
-- for versions uploaded before this column existed.
--
-- A brand-new install does NOT need this file — sql/schema.sql already
-- includes everything below.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

ALTER TABLE document_versions
  ADD COLUMN original_filename VARCHAR(255) NULL AFTER file_size_bytes,
  ADD COLUMN mime_type         VARCHAR(127) NULL AFTER original_filename;

-- Backfill original_filename from storage_key (matterId/uniqid-originalName;
-- seeded/legacy rows without the uniqid prefix just keep their basename).
UPDATE document_versions
SET original_filename = CASE
    WHEN LOCATE('-', SUBSTRING_INDEX(storage_key, '/', -1)) > 0
      THEN SUBSTRING(
             SUBSTRING_INDEX(storage_key, '/', -1),
             LOCATE('-', SUBSTRING_INDEX(storage_key, '/', -1)) + 1
           )
    ELSE SUBSTRING_INDEX(storage_key, '/', -1)
  END
WHERE original_filename IS NULL;

-- Backfill mime_type by extension — best-effort only, since the original
-- bytes for old/demo rows may no longer be on disk to sniff with finfo the
-- way new uploads are. Anything not recognized falls back to a generic
-- binary type; it still downloads correctly, just without a specific
-- Content-Type or inline-preview eligibility.
UPDATE document_versions
SET mime_type = CASE LOWER(SUBSTRING_INDEX(original_filename, '.', -1))
    WHEN 'pdf'  THEN 'application/pdf'
    WHEN 'docx' THEN 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    WHEN 'doc'  THEN 'application/msword'
    WHEN 'txt'  THEN 'text/plain'
    WHEN 'md'   THEN 'text/plain'
    WHEN 'csv'  THEN 'text/csv'
    WHEN 'log'  THEN 'text/plain'
    WHEN 'png'  THEN 'image/png'
    WHEN 'jpg'  THEN 'image/jpeg'
    WHEN 'jpeg' THEN 'image/jpeg'
    WHEN 'gif'  THEN 'image/gif'
    WHEN 'webp' THEN 'image/webp'
    WHEN 'bmp'  THEN 'image/bmp'
    WHEN 'tif'  THEN 'image/tiff'
    WHEN 'tiff' THEN 'image/tiff'
    WHEN 'mp3'  THEN 'audio/mpeg'
    WHEN 'wav'  THEN 'audio/wav'
    WHEN 'ogg'  THEN 'audio/ogg'
    WHEN 'm4a'  THEN 'audio/mp4'
    WHEN 'mp4'  THEN 'video/mp4'
    WHEN 'webm' THEN 'video/webm'
    WHEN 'mov'  THEN 'video/quicktime'
    ELSE 'application/octet-stream'
  END
WHERE mime_type IS NULL;
