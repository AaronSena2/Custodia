-- Custodia — upgrade script 014: Practice Groups.
--
-- Converts the "Practice Areas" admin catalog (sql/upgrade_013_practice_areas.sql)
-- into real Practice Groups with membership, plus two new admin-driven
-- matter-access grant mechanisms:
--   - a whole Practice Group can be granted access to a matter
--     (practice_group_matter_grants)
--   - an individual user can be granted direct access to a matter without a
--     self-service request (reuses access_requests — see
--     custodia_admin_grant_matter_access() in includes/access_requests.php)
--
-- matters.practice_area and retention_policies.practice_area stay exactly as
-- they were — plain text, matched by name, not a foreign key to this catalog
-- (see includes/practice_groups.php's docblock). The only thing that moves
-- off free text is USER membership: users.practice_areas (a JSON array) is
-- replaced by the practice_group_members join table below, because "which
-- users are in this group" is exactly the relationship an admin now manages
-- from the group's own page (practice_group.php) instead of a text field on
-- the user form.
--
-- Run this ONCE against an existing `custodia` database that predates this
-- table, IN THIS ORDER:
--   1. Run every statement in this file EXCEPT the final "DROP COLUMN"
--      (leave users.practice_areas in place for now).
--   2. Run `php migrate_practice_group_membership.php` once — it reads
--      users.practice_areas and populates practice_group_members from it.
--   3. Verify: SUM(JSON_LENGTH(practice_areas)) over users beforehand should
--      equal COUNT(*) of practice_group_members afterward.
--   4. Only then run the final ALTER TABLE ... DROP COLUMN statement at the
--      bottom of this file.
--
-- A brand-new install does NOT need this file — sql/schema.sql, seed.php,
-- and includes/permissions.php's CUSTODIA_DEFAULT_ROLE_PERMISSIONS already
-- cover the end state directly.
--
-- How to run: phpMyAdmin → select the `custodia` database → SQL tab →
-- paste (steps 1 and 4 separately, per above) → Go.

SET NAMES utf8mb4;

-- Same columns as practice_areas (id, name, created_at) — this table has no
-- FK dependents today, so the rename is a pure identity change.
RENAME TABLE practice_areas TO practice_groups;

CREATE TABLE IF NOT EXISTS practice_group_members (
  id                CHAR(36)    NOT NULL PRIMARY KEY,
  practice_group_id CHAR(36)    NOT NULL,
  user_id           CHAR(36)    NOT NULL,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_pgm_group FOREIGN KEY (practice_group_id) REFERENCES practice_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_pgm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_pgm_group_user (practice_group_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- Lets an admin-granted individual access be pulled later while keeping full
-- history — same "update status in place" convention custodia_decide_access_request()
-- already uses, rather than deleting the row.
ALTER TABLE access_requests MODIFY COLUMN status ENUM('PENDING','APPROVED','DENIED','REVOKED') NOT NULL DEFAULT 'PENDING';

UPDATE role_permissions SET permission_key = 'manage_practice_groups' WHERE permission_key = 'manage_practice_areas';

-- ── Step 4 — run ONLY after migrate_practice_group_membership.php has been
-- run and verified (see instructions above). ──────────────────────────────
-- ALTER TABLE users DROP COLUMN practice_areas;
