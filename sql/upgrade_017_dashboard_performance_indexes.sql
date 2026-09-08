-- Custodia — upgrade script 017: dashboard performance indexes.
--
-- audit_log and matters have grown large enough (bulk grants, imports) that
-- two dashboard analytics queries were doing full table scans:
--
--   - "Activity by Type" (GROUP BY audit_log.action_type) — measured at
--     1.3-1.5s on a table with ~90k rows, EXPLAIN showed
--     `type: ALL, Using temporary; Using filesort`.
--   - "Group / Team Comparison" (matters JOIN'd to practice_groups by
--     practice_area name) — EXPLAIN showed a full scan of ~8k matter rows
--     per practice group.
--
-- These indexes let both use an index scan instead. audit_log.action_type
-- also speeds up any other action_type-filtered audit query, e.g.
-- custodia_build_audit_where()'s actionType filter.
--
-- Run this ONCE against an existing `custodia` database that predates this
-- fix — a brand-new install doesn't need it (sql/schema.sql already
-- includes both indexes).
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste this whole file → Go.

CREATE INDEX idx_al_action_type ON audit_log(action_type);
CREATE INDEX idx_matters_practice_area ON matters(practice_area);
