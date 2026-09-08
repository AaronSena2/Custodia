-- Custodia — upgrade script 019: allow unchained (VIEW) audit_log rows.
--
-- Security review 2026-09-03, finding 4.3: every VIEW action (opening a
-- matter, opening a digital document) went through the same FOR UPDATE-
-- locked, hash-chained write path as custody/security-relevant actions —
-- at current volume that's 89,000+ writes/30 days all serialized through
-- the single audit_chain_state row, a real contention point.
--
-- includes/audit.php's custodia_audit_record() now accepts a 'chained'
-- flag; VIEW call sites (includes/matters.php, includes/digital_documents.php)
-- pass 'chained' => false and skip the lock and the hash entirely. Those
-- rows still get written — audit_log stays append-only and every VIEW is
-- still logged, filterable, and exportable — they're just excluded from
-- the cryptographic chain, and custodia_audit_verify_chain() now only
-- walks rows where entry_hash IS NOT NULL.
--
-- That requires prev_hash/entry_hash to allow NULL, which the original
-- schema didn't. Run this ONCE against an existing `custodia` database
-- that predates this fix — a brand-new install doesn't need it
-- (sql/schema.sql already has both columns nullable).
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go. (On ~90k existing rows this ALTER is a
-- fast metadata-only change for InnoDB — it doesn't rewrite existing
-- prev_hash/entry_hash values, only relaxes the constraint for new rows.)

ALTER TABLE audit_log
  MODIFY COLUMN prev_hash  CHAR(64) NULL,
  MODIFY COLUMN entry_hash CHAR(64) NULL;
