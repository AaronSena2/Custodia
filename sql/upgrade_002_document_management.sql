-- Custodia — upgrade script 002: iManage-style document management.
--
-- Run this ONCE against an existing `custodia` database that was created
-- from an earlier copy of schema.sql (i.e. you already have data — matters,
-- documents, seeded users — that you don't want to lose). It adds the new
-- columns and indexes that document profiles/numbering, full-text search,
-- and version comparison need, and backfills sensible values for any rows
-- that already exist.
--
-- A brand-new install does NOT need this file — sql/schema.sql already
-- includes everything below. Only run this against a database you already
-- imported the OLD schema.sql into.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

SET NAMES utf8mb4;

-- ── digital_documents: profile fields + iManage-style numbering ───────
ALTER TABLE digital_documents
  ADD COLUMN doc_number    INT  NULL AFTER id,
  ADD COLUMN description   TEXT NULL AFTER title,
  ADD COLUMN author_id     CHAR(36) NULL AFTER linked_physical_file_id,
  ADD COLUMN created_by_id CHAR(36) NULL AFTER author_id;

-- Assign sequential doc_number values to any existing documents, oldest first.
SET @rownum := 0;
UPDATE digital_documents
   SET doc_number = (@rownum := @rownum + 1)
 WHERE doc_number IS NULL
 ORDER BY created_at ASC;

ALTER TABLE digital_documents
  MODIFY COLUMN doc_number INT NOT NULL AUTO_INCREMENT,
  ADD UNIQUE KEY uq_dd_doc_number (doc_number),
  ADD CONSTRAINT fk_dd_author FOREIGN KEY (author_id) REFERENCES users(id),
  ADD CONSTRAINT fk_dd_created_by FOREIGN KEY (created_by_id) REFERENCES users(id);

-- Best-effort backfill: whoever uploaded a document's first version becomes
-- its Author and Creator of record. Documents with no version yet are left
-- NULL — the app displays those as "Unknown" and treats the fields as
-- editable from that point on.
UPDATE digital_documents dd
  JOIN (
    SELECT dv1.document_id, dv1.uploaded_by_id
    FROM document_versions dv1
    WHERE dv1.version_number = (
      SELECT MIN(dv2.version_number) FROM document_versions dv2 WHERE dv2.document_id = dv1.document_id
    )
  ) firstv ON firstv.document_id = dd.id
SET dd.author_id = firstv.uploaded_by_id,
    dd.created_by_id = firstv.uploaded_by_id
WHERE dd.author_id IS NULL;

-- ── document_versions: full-text extraction + search index ────────────
ALTER TABLE document_versions
  ADD COLUMN extracted_text    LONGTEXT    NULL AFTER ocr_status,
  ADD COLUMN extraction_status VARCHAR(20) NOT NULL DEFAULT 'PENDING' AFTER extracted_text,
  ADD FULLTEXT INDEX ftx_dv_extracted_text (extracted_text);

-- Existing uploaded versions were stored before extraction existed, so their
-- extracted_text is empty and extraction_status stays 'PENDING' — they simply
-- won't surface in full-text search results until a new version is uploaded
-- for them. Nothing to fix here; this is expected and harmless.
