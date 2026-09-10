# Custodia — PHP / MySQL / Bootstrap Edition

A from-scratch rebuild of the Custodia Legal File Registry & Movement
Tracking System in plain PHP, MySQL, and Bootstrap 5 — no framework, no
build step, no Node.js. This exists as a deliberately simpler alternative to
the earlier NestJS/Next.js/Prisma version: same feature set, same RBAC and
audit-log guarantees, dramatically fewer moving parts to install and run.

Every page is server-rendered PHP; interactive bits (modals, the Scan
Station, Approvals actions) use plain `fetch()` against small JSON
endpoints in `actions/`. Styling is Bootstrap 5 loaded from its CDN — no
CSS build, no `npm install` anywhere in this project.

## Why this is easier to run than the Node version

The whole point of this rebuild was to avoid the multi-tool install pain
(Node.js, npm, PostgreSQL, Prisma's engine binaries, PATH issues) that came
up getting the earlier version running on Windows. This version needs
exactly one thing: **XAMPP** (or WAMP/MAMP), which bundles Apache, MySQL,
and PHP in a single installer. No separate database engine to install, no
PATH configuration, no separate runtime.

## Setup (XAMPP on Windows)

1. Install [XAMPP](https://www.apachefriends.org/) if you don't already
   have it — the default install with Apache + MySQL + PHP is all you need.
2. Copy this whole `custodia-php` folder into `C:\xampp\htdocs\` (so the
   final path is `C:\xampp\htdocs\custodia-php`).
3. Open the **XAMPP Control Panel** and click **Start** next to both
   **Apache** and **MySQL**.
4. Open **phpMyAdmin** (the XAMPP Control Panel has an "Admin" button next
   to MySQL, or go to `http://localhost/phpmyadmin`). Click the **SQL** tab
   and run this once, to create the database user this app expects out of
   the box:
   ```sql
   CREATE USER IF NOT EXISTS 'custodia'@'localhost' IDENTIFIED BY 'custodia';
   CREATE DATABASE IF NOT EXISTS custodia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   GRANT ALL PRIVILEGES ON custodia.* TO 'custodia'@'localhost';
   FLUSH PRIVILEGES;
   ```
5. Still in phpMyAdmin: click the **custodia** database in the left sidebar,
   go to the **Import** tab, choose `sql/schema.sql` from this project, and
   click **Go**. This creates every table.
6. Seed demo data. Open a Command Prompt and run:
   ```
   cd C:\xampp\htdocs\custodia-php
   C:\xampp\php\php.exe seed.php
   ```
   (If `php.exe` isn't found, XAMPP's PHP lives at that path by default —
   adjust if you installed XAMPP somewhere else.)
7. Open **`http://localhost/custodia-php/login.php`** in your browser.

That's it — no `.env` file to create, no PATH to fix, no Prisma-style
binary downloads. The defaults in `includes/config.php` match the SQL
above exactly.

### If you're not using XAMPP (any PHP + MySQL host)

The app reads its DB connection from environment variables, with defaults
matching the setup above:

| Variable | Default |
|---|---|
| `CUSTODIA_DB_HOST` | `127.0.0.1` |
| `CUSTODIA_DB_PORT` | `3306` |
| `CUSTODIA_DB_NAME` | `custodia` |
| `CUSTODIA_DB_USER` | `custodia` |
| `CUSTODIA_DB_PASSWORD` | `custodia` |
| `CUSTODIA_STORAGE_PATH` | `./storage` (uploaded document files) |

Point the web server's document root at this folder, import `sql/schema.sql`
into a MySQL/MariaDB 10.4+ database, run `php seed.php` once, and visit
`login.php`.

### Local quick-start without a web server at all

For a fast local check, PHP's built-in server works fine (this is exactly
how every page in this app was tested while it was being built):

```
php -S localhost:8080
```

Then visit `http://localhost:8080/login.php`.

## Demo login

Seeded by `seed.php`. Password for every account: **`ChangeMe123!`**

This list used to also be rendered on the public login page itself —
removed as of the 2026-09-03 security review (finding 1.1, CRITICAL): it
let anyone who could reach the app, unauthenticated, sign in as the System
Administrator with no guessing required. It's fine to keep this table here
in the README for local/dev use, since the README isn't served by the app;
see `sql/upgrade_020_force_password_reset.sql` and
`jobs/rotate_all_passwords.php` for rotating this password on an install
that has real (non-demo) data.

| Email | Role |
|---|---|
| sam.okafor@custodia.demo | System Administrator |
| rita.alvarez@custodia.demo | Records Manager |
| daniel.reyes@custodia.demo | Partner |
| priya.nair@custodia.demo | Partner |
| elena.cho@custodia.demo | Associate |
| marcus.webb@custodia.demo | Paralegal |

Sign in as different roles to see the RBAC matrix in action: only Sam/Rita
can configure ethical walls or retention policies; Priya (a Partner outside
Corporate) is denied on the RESTRICTED Northgate matter until an access
request is approved; Marcus is ethically walled from Bellweather Foods.

## Pages

- `dashboard.php` — overdue returns, pending approvals, destruction review queue, matters by practice area, and a live analytics section (see "Dashboard analytics" below)
- `matters.php`, `matter.php?id=...` — matter list and tabbed detail (Overview, Physical Files, Digital Documents, Team & Access, Audit Trail)
- `scan.php` — Registry Scan Station: barcode lookup and check-out / check-in / transfer / override
- `documents.php` — top-level digital document library (matter picker + the same document table as the matter detail tab)
- `search.php` — full-text document search across every matter you can access (see "Document management" below)
- `document_compare.php` — side-by-side redline comparison between any two versions of a document
- `approvals.php` — incoming transfer confirmations, custody movements pending approval, and access requests
- `audit.php` — filterable, paginated audit log explorer with CSV export and hash-chain integrity verification
- `admin.php` — retention policy configuration (gated by the `manage_retention_policies` permission, System Admin + Records Manager by default); User Accounts and Permissions tabs (gated by the `manage_users` permission, System Admin by default) for creating accounts, editing profiles/roles, deactivating/reactivating, resetting passwords, creating new roles, and configuring which role has which capability — the page itself is reachable by any role holding at least one admin-area permission, not just the two built-ins (security review 2026-09-03, finding 2.2)
- `help.php` — in-app user manual, open to every role, with a personalized "what can I do" panel and an admin-only live role reference (see "User Manual" below)

## Document management (iManage-style)

Beyond version upload/edit-lock/share-links (the original blueprint's Section 4.5–4.7, still there), the Digital Documents area also has three iManage-inspired capabilities:

- **Document profiles + permanent numbering.** Every document gets a firm-wide sequential number the moment it's created, formatted as `CUS-000142.3` (document 142, version 3) via `custodia_doc_label()` — this stays stable for the document's whole life, the way an iManage DOCID does, so it's safe to reference in an email or a pleading. Alongside the number, each document carries an editable profile: title, description, document type, confidentiality, and an **Author** field (who owns/wrote it — separate from **Creator**, which is fixed at whoever created the record). Edit a profile from the "Edit Profile" button on any document row.
- **Full-text search** (`search.php`). Real text is pulled out of every uploaded file **at upload time** — not a stub — and indexed with a MySQL `FULLTEXT` index on the current version's content, plus title/description/type/doc-number. See `includes/text_extract.php`: `.docx` extraction is reliable (a docx is just a zip of XML, parsed structurally with PHP's built-in `ZipArchive`); `.pdf` extraction is a dependency-free best-effort content-stream parser (reads `Tj`/`TJ` text-showing operators after inflating `FlateDecode` streams with PHP's built-in zlib) that works well on typical born-digital PDFs but can miss text in PDFs with heavily subsetted/custom fonts, and finds nothing in scanned/image-only PDFs (those need real OCR — see "Known gaps"); `.txt`/`.md`/`.csv` are read directly. Search uses MySQL's `BOOLEAN MODE` full-text matching (all query words required, not just any one of them) so results stay precise. Same three-layer RBAC as everywhere else — you only ever see hits inside matters you can already access.
- **Version comparison / redline** (`document_compare.php`). A dependency-free two-level diff (`includes/text_diff.php`): paragraphs are compared first (keeps the LCS diff table small and fast even for a long contract), then any paragraph-for-paragraph edit gets refined to a word-level diff for a proper redline view — additions in green, removals in red-strikethrough. Only available between versions that both extracted cleanly (an image-only PDF version, for instance, has nothing to diff against).
- **Download and in-page preview for any file type**, including PDF, images, audio, and video — `actions/download_document.php` serves the stored file (or a specific version via `?versionId=`) under the same RBAC as every other read, with the real MIME type and original filename captured at upload time (`document_versions.mime_type`/`original_filename`), and supports HTTP Range requests so `<audio>`/`<video>` can seek instead of downloading the whole file first. Click "Preview" on a document row for an in-modal viewer (native PDF embed, `<img>`, `<audio>`, or `<video>`); "Download" always saves the file regardless of type. Uploads are validated against `includes/upload_policy.php` before they're accepted — see "Known gaps" for the size limits that also require a php.ini change.
- **Audio/video duration**, shown next to the Preview/Download buttons and in the preview modal title (e.g. "Deposition Clip — 12:04"). Computed at upload time by `includes/media_metadata.php` — a small dependency-free parser (RIFF chunks for `.wav`, the ISO-BMFF `moov`/`mvhd` box for `.mp4`/`.m4a`/`.mov`, and best-effort MPEG frame-header math with a Xing/VBRI fast path for `.mp3`) rather than a vendored library, matching this project's no-Composer/no-build-step approach elsewhere. `.webm`/`.ogg`/`.avi`/`.mkv` aren't parsed and simply show no duration, rather than a guess.
- **Real OCR for standalone images** (`.jpg`/`.png`/`.gif`/`.webp`/`.bmp`/`.tif`) via a locally installed Tesseract binary, and — as of 2026-09-06 — **scanned/image-only PDFs too**, via a scheduled sweep (`jobs/ocr_scanned_pdfs.php`) rather than at upload time; see "Known gaps" for exactly how that's split and why.

Existing install upgrading from an earlier copy of this project: run `sql/upgrade_002_document_management.sql`, `sql/upgrade_003_file_metadata.sql`, and `sql/upgrade_004_media_metadata.sql` once each via phpMyAdmin's SQL tab against your `custodia` database — they add the new columns/index and backfill values for documents you already have, without touching your existing data. A brand-new install doesn't need any of them; `sql/schema.sql` already includes everything.

## Roles and permissions

Roles are admin-extensible, not fixed — System Admin (the `manage_users` permission) can create new roles from Admin → Permissions ("+ New Role": a key and a label), on top of the six the app ships with (System Admin, Records Manager, Partner, Associate, Paralegal, Guest/Auditor). What each role is *allowed to do* is a separate, admin-editable matrix, also on that screen: a role × capability grid covering 12 flat "may this role do X" gates — managing users, retention policies, creating matters, registering physical files, approving custody movements, auto-approved checkout, overriding a check-in or a document lock, initiating a transfer on someone else's behalf, deciding access requests, exporting the audit log, and verifying the audit chain. Toggle a checkbox and save — every page and every `actions/*.php` endpoint that gates on that capability re-checks the database on the next request, nothing needs restarting. `includes/roles.php` owns the role catalog; `includes/permissions.php` owns the capability matrix.

**What this deliberately does NOT touch**, and why: the deeper three-layer per-matter RBAC in `includes/matter_access.php` (ethical wall → confidentiality tier → team assignment), a matter's managing-partner-specific overrides (whoever is a matter's own Incharge — `matters.managing_partner_id`, settable to any active user regardless of role from Edit Matter Details — can always approve custody requests or decide access requests on that matter, regardless of what the matrix says: that's a fact about the matter's data, not a role privilege), and baseline role-tier rules like "Guest/Auditor can't create documents." Folding those into an admin-editable matrix would be a much bigger, riskier redesign of this app's core security model than a configurable capability list calls for — see `includes/permissions.php`'s and `includes/roles.php`'s docblocks for the full reasoning and `CUSTODIA_PERMISSIONS`/`CUSTODIA_DEFAULT_ROLE_PERMISSIONS`/`CUSTODIA_BUILTIN_ROLES` for the exact catalogs and defaults (the defaults reproduce the app's original hardcoded behavior exactly, so installing either feature changes nothing until an admin edits the matrix or adds a role).

**A newly created role is deliberately narrow.** It gets a key and a label — nothing else — and starts with every permission in the matrix unchecked. It can never become "firm-wide" (see every matter, the way System Admin/Records Manager can) the way a built-in role can; it always sees only matters it's explicitly assigned to, the same tier Associate/Paralegal are in today. Making a role's matter-visibility tier itself configurable was considered and deliberately deferred — it would mean extending `custodia_firm_wide_roles()`-style logic (currently hardcoded to those two roles) across `matters.php`, `audit_query.php`, `physical_files.php`, and `access_requests.php` to be table-driven, a materially bigger change than adding a role key. Renaming a role isn't supported yet.

**Security review 2026-09-03, finding 2.2 — a growing role list (this install has grown well past the original six) surfaced three real gaps between "granted in the matrix" and "actually works," all now fixed:**
- `admin.php` itself used to gate the whole page on a hardcoded `SYSTEM_ADMIN`/`RECORDS_MANAGER` role check, ahead of every tab's own permission check. That made every admin-area matrix permission (`manage_users`, `manage_retention_policies`, `manage_practice_groups`, `manage_physical_locations`, `view_audit_log`, `export_audit_log`, `verify_audit_chain`) dead weight for any other role — an admin could grant a custom role `manage_retention_policies`, but that role would still 403 on `admin.php` before ever reaching the Retention tab. The page gate is now permission-based (any role holding at least one admin-area permission can reach it); each tab's own check is unchanged. The Retention tab itself — the catch-all default for `?tab=` — picked up an explicit `manage_retention_policies` check it never had before (previously implicit only via the page-level role check just removed).
- `includes/audit_query.php`'s RBAC scoping used to be `if (ASSOCIATE/PARALEGAL) {narrow} elseif (PARTNER) {managed matters}` with SYSTEM_ADMIN/RECORDS_MANAGER relying on falling through both branches to get unscoped access. Any other role — including a custom role granted `view_audit_log`/`export_audit_log` and meant to see only its own actions, the way Associate/Paralegal do — fell through the same way and silently got full firm-wide audit visibility instead. Firm-wide reach is now an explicit check against `custodia_firm_wide_roles()` (the same safe pattern `matters.php`/`physical_files.php` already used), and anything else defaults to the narrow "own actions only" floor.
- The managing-partner "always act on your own matter" override in `includes/custody.php` (`custodia_approve_movement()`) and `includes/access_requests.php` (`custodia_can_decide_matter_access()`) used to require `role === 'PARTNER'` literally. Since Edit Matter Details' Incharge dropdown accepts any active user regardless of role, a matter incharge who isn't literally a Partner (a "Senior Associate" or "Principal Associate" managing their own matters, say) had no floor at all — they needed the firm-wide `approve_custody_movements`/`decide_access_requests` permission just to act on matters they personally run, which also handed them approval power over every other matter. Both checks now key off the data fact (`$matter['managing_partner_id'] === $user['id']`) instead of the role label; the existing rule that a literal Partner's firm-wide grant of these two permissions doesn't extend beyond their own matters is unchanged.

**Deleting a role** (the "Delete" link under a role's column header on Admin → Permissions — only shown for non-built-in roles) is possible but guarded on both sides: `custodia_delete_role()` refuses to delete any of the six built-ins outright (they're load-bearing — `matter_access.php`, `custody.php`, and others special-case those exact role strings), and refuses to delete a role that any user currently holds ("N users still assigned to it — reassign them first"), so no user's `role` foreign key is ever left dangling. It's the app's one hard-delete action (deactivating a user, by contrast, is reversible), so the UI backs it with a plain `confirm()` rather than the custom-modal pattern used elsewhere — a deliberate, minimal exception for the one place undo genuinely isn't possible.

**System Admin can never lose the `manage_users` permission** — `custodia_update_role_permissions()` refuses to save a matrix that removes it, and its checkbox is rendered disabled+checked for that role, because `manage_users` also gates both the User Accounts screen and this Permissions screen itself; removing it would lock every admin out of ever fixing either again. Every other cell is fully editable, including granting `manage_users` itself to another role (e.g. letting Records Manager also manage accounts, permissions, and roles).

Existing install upgrading from an earlier copy: run `sql/upgrade_005_role_permissions.sql` and then `sql/upgrade_006_custom_roles.sql` once each via phpMyAdmin's SQL tab, in that order — the first creates the permission matrix, the second converts `users.role`/`role_permissions.role` from a fixed database ENUM to a table-backed reference (preserving every existing user's role exactly) and seeds the six built-in roles. A brand-new install doesn't need either; `sql/schema.sql` and `seed.php` already include everything.

## User Manual (`help.php`)

An in-app manual, reachable from the sidebar by every logged-in role — documentation isn't sensitive, so viewing it isn't permission-gated the way the features it describes are. Two things make it self-maintaining instead of static prose that drifts out of date the next time a permission changes:

- **"Your Access at a Glance"** — a personalized panel at the top computed live from the current user's actual role: their matter-visibility tier (firm-wide / practice-area+assigned / assigned-only / none — `custodia_manual_matter_tier()`, mirroring the exact logic in `includes/matter_access.php`/`includes/matters.php`) and the real list of permissions their role currently holds, queried straight from `role_permissions`. Change a role's permissions in Admin → Permissions, and everyone with that role sees the update here immediately — no doc to remember to edit.
- **"Role Reference"** — gated behind the `manage_users` permission (same gate as Admin → User Accounts/Permissions), this lists every role in the system, built-in or admin-created, with the same live tier + permission data as above. It's the same underlying data `custodia_list_roles()` and `role_permissions` already hold; this is a read-only view of it for reference/training rather than new state.

Everything else on the page (Getting Started, Matters & Access Control, Physical File Custody, Digital Documents, Approvals, Audit Log, Admin) is hand-written prose explaining how each workflow works and which permission each action needs — visible to everyone regardless of whether they hold that permission, on the theory that understanding *why* you can't do something (or what a teammate's role can do) is more useful than hiding the explanation. Verified for two roles at opposite ends of the permission spectrum: System Admin sees all 12 permissions and the full Role Reference roster; a seeded Associate sees just their one actual permission, no "Admin" nav link, and no Role Reference section at all.

## Dashboard analytics

`dashboard.php` has a four-chart analytics section (Chart.js, loaded from a CDN — the one external JS dependency in the app, matching how Bootstrap itself is already loaded) built on `includes/analytics.php`:

- **Activity Over Time** — a dual-line chart of documents uploaded vs. audit events per day, last 30 days.
- **File Types** — a doughnut of current-version documents by category (PDF / Image / Audio / Video / Word-Text / Other), bucketed from `mime_type`.
- **Most Accessed Documents** — a horizontal bar of the most-downloaded documents, counted from `DOWNLOAD` entries in the audit trail.
- **Activity by Type** — a horizontal bar of audit action-type volume, bar colors matching that action's badge color everywhere else in the app (`CUSTODIA_ACTION_BADGE_COLORS` in `includes/helpers.php`).

**RBAC-scoped using the app's existing rules, not a parallel set.** Document-derived charts (uploads, file types) are scoped to `custodia_list_matters_for_user()`'s matter set — the same list that drives `matters.php`. Audit-derived charts (activity, action types, most-accessed) reuse `custodia_build_audit_where()` from `includes/audit_query.php` — the exact WHERE clause `audit.php` itself renders with: Guest/Auditor sees nothing, Associate/Paralegal see only their own actions, Partner sees matters they manage, Admin/Records Manager see everything. Verified directly: a System Admin's numbers and a seeded Associate's numbers differ correctly, and an Associate's empty "Most Accessed Documents" panel falls back to a plain "No downloads recorded yet." message instead of an empty chart.

**"Live"** means exactly one thing here, deliberately: `actions/dashboard_analytics.php` re-runs the same queries and returns fresh JSON, and the page polls it every 45 seconds and redraws all four charts (`custodiaRenderCharts()` in `dashboard.php`) — no page reload, no websocket, matching the plain-`fetch()` approach every other action in this app already uses. The initial render uses PHP-computed data embedded directly in the page (`CUSTODIA_INITIAL_ANALYTICS`), so charts appear immediately without waiting on that first poll.

**Server-side cached, per user, for 45 seconds** (`custodia_dashboard_analytics_cached()` in `includes/analytics.php` — security review 2026-09-03, finding 4.1). With the dashboard routinely left open across a shift, both the page-load path and the 45s poll used to recompute all 9 underlying queries from scratch every single time; a review session measured 10+ of those round trips in one sitting. The cache is keyed per user (results are RBAC-scoped, so two viewers can legitimately see different numbers) and stored as plain files under `storage/.cache/` — no APCu/Redis dependency, works unmodified on a bare XAMPP install. A failed cache write (read-only storage, disk hiccup) just falls back to computing fresh, same as before this existed.

## Structure

```
sql/schema.sql               MySQL/MariaDB schema — the full data model
sql/upgrade_002_...sql       One-time upgrade script for an existing install (see above)
sql/upgrade_003_...sql       One-time upgrade script: mime_type/original_filename columns (see above)
sql/upgrade_004_...sql       One-time upgrade script: duration_seconds column (see above)
sql/upgrade_005_...sql       One-time upgrade script: role_permissions table (see "Roles and permissions")
sql/upgrade_006_...sql       One-time upgrade script: admin-extensible roles table (see "Roles and permissions")
seed.php                     Demo users, matters, physical files, a document with 2 versions
tests/smoke.php              Real-database test suite (see "Tests" below)
includes/
  config.php, db.php         Configuration + PDO connection
  auth.php                   Session login, CSRF, RBAC role gate
  audit.php                  Hash-chained append-only audit log
  matter_access.php          Three-layer RBAC (ethical wall → confidentiality → assignment)
  custody.php                 Check-out / check-in / transfer / override state machine
  matters.php, physical_files.php, digital_documents.php,
  access_requests.php, audit_query.php, retention_policies.php, users.php
                              One module per domain area — the PHP equivalent
                              of the earlier Node version's NestJS services
  permissions.php              Admin-configurable role → capability matrix (see "Roles and permissions")
  roles.php                     Admin-extensible role catalog — create new roles (see "Roles and permissions")
  analytics.php                 Dashboard chart data, RBAC-scoped (see "Dashboard analytics")
  text_extract.php            Real (not stubbed) text extraction at upload time — DOCX/PDF/plain text/OCR'd images
  text_diff.php                Dependency-free paragraph + word-level version-comparison diff engine
  upload_policy.php            Upload allow-list/blocklist, size caps, content-vs-extension MIME check
  media_metadata.php           Dependency-free audio/video duration extraction (WAV/MP4/M4A/MOV/MP3)
  layout_header.php/footer.php, helpers.php
                              Shared Bootstrap page shell + formatting helpers
  custody_modals.php, document_modals.php
                              Shared modal markup + JS, reused across pages
actions/*.php                 JSON endpoints the frontend JS POSTs to
actions/download_document.php Plain-GET file download/inline-preview endpoint (RBAC + Range support)
actions/dashboard_analytics.php JSON endpoint the dashboard polls for its "live" chart refresh
assets/css/app.css, assets/js/app.js
                              All custom CSS/JS — no build step
storage/                      Uploaded document files land here; storage/.htaccess blocks direct web
                              access — see "Known gaps"
```

## Tests

```
php tests/smoke.php
```

This is a from-scratch, dependency-free test script — no PHPUnit, no
Composer install required — that runs 33 checks **against a real MySQL/
MariaDB database**, not mocks or stubs:

- the audit hash-chain: correct linking between entries, and
  `custodia_audit_verify_chain()` catching a tampered field
- the three-layer RBAC check: ethical wall denial, confidentiality-tier
  denial, an approved access request satisfying both the confidentiality
  and assignment gates
- the full custody state machine: a Paralegal's checkout landing
  `PENDING_APPROVAL`, a Records Manager's approval completing it, self
  check-in, an auto-approved Partner checkout, and a full dual-confirmation
  transfer requiring Partner sign-off on a RESTRICTED matter
- document management: doc-number assignment and label formatting, real
  text extraction from plain-text/DOCX/unsupported files, full-text search
  (matching by content, by bare doc number, correctly scoped to the current
  version only, and correctly RBAC-scoped to what the searching user can
  access), and the version-comparison diff engine (paragraph-level equal/
  replace detection, word-level highlighting within a changed paragraph)

Run it against a throwaway database (it writes real rows) — `php seed.php`
first, then `php tests/smoke.php`, then re-import `schema.sql` if you want
a clean slate again afterward.

Beyond this script, every page and every AJAX action in this app was
exercised over real HTTP during development — actual `curl` requests
carrying real session cookies and CSRF tokens against a running PHP server
and a live MariaDB instance, not just unit-level checks. That includes
role-based page denials (e.g. a Partner outside a matter's practice area
getting a real 403), the full checkout → approve → check-in cycle, a
complete dual-confirmation transfer, and — for this update — a real file
upload that gets extracted and becomes searchable within the same request,
a version comparison rendering an actual redline, a profile edit, and the
`sql/upgrade_002_...sql` script run against a simulated pre-upgrade database
to confirm it backfills correctly without touching existing rows. This is a
meaningfully more thorough verification than the earlier Node/Prisma version
could get in its sandbox, which was blocked from reaching a real database
at all.

An automated browser (E2E) test suite now also exists — `tests/browser/`,
built with Playwright, a dev/CI-only tool kept outside this app's own
dependency-free design (see "Known gaps" below). It drives the app the
way a real user's browser would: real login sessions, real CSRF tokens,
real navigation. See `tests/browser/README.md` for coverage and how to
run it.

## Email notifications (Microsoft Graph)

Notifications have always appeared in the app's own bell inbox. As of
2026-09-09 they can also be emailed, through the **Microsoft Graph API**
rather than SMTP — no third-party library, so the app stays dependency-free,
and it sidesteps Microsoft 365's ongoing retirement of basic SMTP AUTH.

**Everything is configured from Admin → Email Settings** (new
`manage_email_settings` permission, seeded to System Administrator only), so
the credentials can be rotated by an administrator without touching code or
env vars. Email is **off until switched on there**; with it off, notifications
behave exactly as they always have.

### One-time setup

1. **Run the migration.** `sql/upgrade_022_email_notifications.sql` — creates
   `app_settings`, `email_outbox`, two per-user preference columns, and the
   new permission. Safe to re-run.

2. **Set an encryption key.** The Microsoft client secret is encrypted before
   it is stored, under a key held *outside* the database — so a leaked
   database, backup or phpMyAdmin session doesn't yield the credential.

   The easiest route is the **Generate a key now** button on Admin → Email
   Settings: it writes a key to `custodia_secret.key` in the folder *above*
   the application directory (outside the web root, so it can't be fetched
   over HTTP) and needs no shell access. It refuses to overwrite an existing
   key, since that would make everything stored under the old one
   permanently unreadable.

   Alternatively set the **`CUSTODIA_SECRET_KEY`** environment variable to a
   32-byte base64 value; it takes precedence over the file. If you go this
   route, set it as a **SYSTEM** environment variable, not a user one — both
   the web server *and* the scheduled task that sends the queue have to read
   it. (A variable that is set but malformed — stray quotes are easy to
   introduce in the Windows dialog — falls through to the key file rather
   than disabling email.)

   **Back the key up either way.** If it is lost the stored secret can't be
   decrypted and has to be re-entered on the settings screen.

   **Cipher.** libsodium is used when the `sodium` extension is enabled, and
   OpenSSL AES-256-GCM otherwise — both authenticated, so both detect
   tampering. A stock Windows XAMPP build ships with `extension=sodium`
   commented out in `php.ini`, which is why the fallback exists; the settings
   screen reports which one is in use. Stored values carry a prefix naming
   their cipher, so a machine that later gains or loses the extension can
   still read what the other wrote.

3. **Register the Azure app** (Entra ID → App registrations):
   - Add **Mail.Send** as an **Application** permission (not delegated), then
     **Grant admin consent**.
   - Create a client secret and copy its **Value** (Azure shows it once).
   - Note the Directory (tenant) ID and Application (client) ID.

4. **Lock the app to one mailbox.** ⚠ `Mail.Send` as an application permission
   is **tenant-wide by default** — as granted, the app can send as *any*
   mailbox in the firm, including a partner's. Restrict it in Exchange Online
   PowerShell:

   ```powershell
   New-ApplicationAccessPolicy -AppId <application-client-id> `
     -PolicyScopeGroupId registry-noreply@yourfirm.com `
     -AccessRight RestrictAccess `
     -Description "Custodia registry notifications only"
   ```

   For a legal registry this is not optional hardening.

5. **Fill in Admin → Email Settings**: tenant ID, client ID, secret, the
   sending mailbox (a dedicated shared mailbox is ideal — no licence needed),
   the secret's expiry date, and the **Application URL** that email links
   point at. That URL must be reachable from the recipient's own machine — the
   `php -S localhost:8080` quick-start binds loopback only, so links would
   work on the server and nowhere else. Use **Send a test** to confirm the
   credentials before switching anything on.

6. **Register the queue job** with Task Scheduler, every 5 minutes (exact
   command in `jobs/send_email_queue.php`'s header). Until it is registered,
   mail queues in `email_outbox` and never leaves.

**Roll it out with the redirect on.** Setting "Redirect all mail to" sends
every notification to one address instead of to real recipients — worth using
for the first run against the live database.

### How it works

Sending is **store-and-forward**, never inline. `custodia_notify_user()` — the
one function every notification in the app goes through — queues an
`email_outbox` row *inside the caller's existing transaction*, so "the custody
movement happened" and "the person was queued to be told" commit or roll back
together. `jobs/send_email_queue.php` talks to Microsoft afterwards, outside
any transaction. A mail outage can therefore never roll back a custody
transfer, and the enqueue path is written so that even a missing table (the
migration not yet run) degrades to "no email" rather than failing the movement.

The queue job re-checks at send time what may have changed in the intervening
minutes: the matter's confidentiality tier, ethical walls and access grants
(via the app's own `custodia_assert_matter_access()`), the recipient's
preferences, the hourly cap, and address deliverability. A row that no longer
passes is marked `SKIPPED` with a reason rather than sent.

### What email says about a confidential matter

An in-app notification lives inside the audit perimeter; an email does not.
So the body is rendered against the matter's tier:

| Tier | Email contains |
|---|---|
| `STANDARD` | Matter number, client, the notification's own title and body |
| `RESTRICTED` / `PRIVILEGED` | Matter number and a sign-in link only — no client name, no case title, and no free-text reason (reasons routinely quote case detail) |

Links always point at a page requiring sign-in. There is deliberately **no
tokenised one-click approve/reject** — that would be a second, weaker
authentication path into exactly the decisions the audit trail exists to
defend.

### Which events send email

Every notification in the app now also emails, and the custody and access
flows gained the notifications they were missing (see below). Security alerts
— `AUDIT_CHAIN_BROKEN`, `THREAT_ALERT`, `ACCOUNT_LOCKED` — **ignore
per-user preferences and the hourly cap**: an alarm someone can switch off is
not an alarm.

Two guards worth knowing about:

- **Undeliverable addresses are skipped.** The 2026-09-05 imports assigned
  effectively every matter to a placeholder incharge at
  `unassigned-partner-v2@placeholder.local`. Without this, any "notify the
  matter's incharge" rule would hard-bounce on nearly every matter in the
  system. Recipient resolution falls through to Records Managers instead. This
  is a guard, not a fix — the placeholders still want cleaning up.
- **Approval notifications go to the matter's incharge, not to every
  firm-wide approver.** `approve_custody_movements` and
  `decide_access_requests` are firm-wide permissions; pinging every holder
  about every one of ~4,273 matters would be unusable. Nobody loses authority
  — the Approvals inbox still shows everyone the full queue.

### Custody and access notifications added at the same time

Before this, `includes/custody.php` contained **no notifications at all**:
five blocking handoffs, and the person who had to act was never told. A
transfer request in particular can only be approved by the file's *current
custodian*, and they found out by happening to open the Approvals page.
Added, each inside the same transaction as its existing audit entry:

- check-out requested → the matter's incharge (or Records Managers)
- check-out approved / rejected → the requester, with the reason on a rejection
- transfer requested → **the current custodian**
- transfer approved / rejected → the requester
- override check-in → the custodian whose file was returned on their behalf
- access request submitted → the matter's incharge (the decision was already
  notified back to the requester; the request itself was not)
- account locked → the account owner *and* Admin/Records Manager

These are improvements to the in-app inbox in their own right; email is what
they additionally become.

## Known gaps for a production deployment

- **Deployment topology, backups, log rotation, and process supervision**
  are covered separately in [`DEPLOYMENT.md`](DEPLOYMENT.md) — written in
  response to the security review (2026-09-03, finding 4.6), it's a
  checklist rather than a swap point, since there's no code change that
  fixes "this runs on an unsupervised Windows workstation with no backups."
- **Auth** is session + `password_hash()`/`password_verify()` for demo
  purposes. Swap point for real SSO/OIDC: `includes/auth.php`'s
  `custodia_attempt_login()` — replace the password check with a token
  verification call against the firm's IdP (Azure AD / Okta / Keycloak),
  then populate `$_SESSION['user_id']` the same way afterward.
- **Browser security headers are set on every response** — security review
  2026-09-03, finding 1.5: no `Content-Security-Policy`, `X-Frame-Options`,
  `X-Content-Type-Options`, or `Referrer-Policy` header was present on any
  page before this. `includes/security_headers.php`'s
  `custodia_send_security_headers()` is called once per request from
  `custodia_start_session()` (`includes/auth.php`) — the one chokepoint
  every PHP entry point passes through before any output, so every page,
  every `actions/*.php` endpoint, and `actions/download_document.php` all
  get `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: same-origin`, and a CSP scoped to what the app actually
  loads (`'self'` plus the jsDelivr CDN for Bootstrap/Chart.js). The CSP
  keeps `'unsafe-inline'` for `script-src`/`style-src` — the app relies
  extensively on inline `<script>` blocks, `onclick`/`onchange` handlers,
  and inline `style=""` attributes throughout (vanilla JS, no build step),
  and moving all of that to a strict nonce/hash-based CSP would mean
  reworking markup on every page, out of scope for this fix; it still
  blocks the more realistic risk — a future XSS or a compromised CDN
  loading/exfiltrating to a domain the app doesn't already trust. The root
  `.htaccess` covers the same two headers (`X-Content-Type-Options`,
  `Referrer-Policy`) for static files under `assets/`, which are served
  directly by Apache and never touch PHP. The same chokepoint also strips
  `X-Powered-By` (security review 2026-09-03, finding 1.4 — every response
  handed out the exact PHP version) via `header_remove()`, since
  `expose_php = Off` is `php.ini`-only and out of the app's own reach; the
  other half of that finding — Apache's own `Server: Apache/...` header —
  is `httpd.conf` configuration with no code equivalent, so it's in
  [`DEPLOYMENT.md`](DEPLOYMENT.md) instead, alongside confirming
  `expose_php = Off` in `php.ini` as belt-and-suspenders.
- **File storage** (`includes/storage.php`) writes uploaded document
  versions to local disk. Swap point for S3-compatible storage: replace
  `custodia_storage_save()`/`custodia_storage_path()` — nothing else in the
  app needs to change, since callers only see `storageKey`/`sha256Hash`/
  `fileSizeBytes`/`originalFilename`/`mimeType`. **`storage/` must never be
  directly web-servable** — `storage/.htaccess` blocks that (required, since
  this folder sits inside the app's own document root once deployed per the
  Setup section above); every real read goes through
  `actions/download_document.php` instead, which enforces the same RBAC as
  the rest of the app. Uploads are additionally checked against an
  extension allow-list/blocklist and a content-vs-extension MIME sanity
  check before they're ever written to disk — see `includes/upload_policy.php`.
- **Uploads larger than ~40MB need a php.ini change.** Custodia's own upload
  policy allows video files up to 1GB and large audio up to 200MB (see
  `includes/upload_policy.php`), but a stock XAMPP install caps
  `upload_max_filesize`/`post_max_size` at 40M regardless — raise both in
  `C:\xampp\php\php.ini` (search for those two keys), then restart Apache
  from the XAMPP Control Panel, to actually accept video-sized files.
- **OCR for standalone images is real, not a stub** — `includes/text_extract.php`
  shells out to a locally installed Tesseract binary (`custodia_ocr_image_text()`,
  checked first at `C:\Program Files\Tesseract-OCR\tesseract.exe`, falling back
  to a PATH lookup) for `.jpg`/`.png`/`.gif`/`.webp`/`.bmp`/`.tif` uploads, and
  the result feeds `extracted_text`/`extraction_status` the same as DOCX/PDF —
  so an OCR'd image is fully searchable and shows up in `search.php` like any
  other document. The legacy `ocr_text`/`ocr_status` columns are unrelated to
  this and remain unused (see schema comments).
- **Scanned/image-only PDFs are OCR'd too, as of 2026-09-06 — but by a
  scheduled sweep, not inline at upload.** Rasterizing a multi-page scan to
  images before Tesseract can even look at it is easily tens of seconds to
  minutes, which has no business blocking an upload HTTP request the way
  near-instant standalone-image OCR does. So a PDF with no text layer still
  comes back `NO_TEXT_LAYER` the instant it's uploaded (unchanged), and
  **`jobs/ocr_scanned_pdfs.php`**, run hourly via Task Scheduler/cron, sweeps
  every `NO_TEXT_LAYER` version — new uploads and the pre-existing backlog
  alike — rasterizing each page with **Ghostscript** (`custodia_ghostscript_binary()`,
  checked first at `C:\Program Files\gs\gs*\bin\gswin64c.exe`, falling back to
  a PATH lookup) and OCRing each page image with the same Tesseract wrapper
  standalone images use, up to `CUSTODIA_OCR_PDF_MAX_PAGES` (40) pages per
  document. A document is flipped to `DONE`/searchable the moment the sweep
  finds any text; if Ghostscript genuinely finds nothing (or isn't installed
  yet), the version simply stays `NO_TEXT_LAYER` rather than being guessed at
  or marked broken. This is the one place in the app that needs a second
  binary installed beyond Tesseract — see the job's own header comment for
  the exact `schtasks`/cron registration command.
- **A scanned PDF or an un-OCR'able image (Tesseract not installed, or OCR
  genuinely found nothing) is labeled distinctly, not lumped in with other
  extraction failures.** Security review 2026-09-03, finding 4.4: the
  Documents list/Matter Documents tab used to show every non-indexed
  document as the same plain muted "No extractable text", indistinguishable
  from a corrupt file, an empty file, or an unsupported type — silently
  reading as "search is broken" rather than "this needs OCR." PDFs and
  images that parsed successfully but yielded zero text now get their own
  `NO_TEXT_LAYER` status (`custodia_extract_text_for_upload()` in
  `includes/text_extract.php`), rendered as a visible amber badge — "Scanned
  — not searchable (needs OCR)", with a tooltip explaining why and what to
  do — instead of the same gray text every other status used. Text-native
  types (txt/md/csv/docx) that come back empty still get plain `FAILED`:
  for those, empty really does mean broken/empty, not "needs OCR."
- **The overdue-return sweep and retention-review job** described in the
  blueprint (a nightly/hourly pass flagging overdue files and matters past
  their retention window) now exists as `jobs/overdue_sweep.php` (security
  review 2026-09-03, finding 4.5) — `dashboard.php` still computes
  "overdue" live for the page itself (still correct, and needed for the
  live view), but the scheduled job additionally notifies each overdue
  file's custodian directly, and — previously true gap — actually
  generates the `DESTRUCTION_REVIEW` access requests the dashboard's
  "pending destruction review" tile and the Approvals page already knew
  how to show but that nothing ever created. Register it with Task
  Scheduler/cron per the command in the file's own header comment; safe to
  re-run, since both checks are deduped against already-flagged rows.
- **Firm-wide default retention policy** (security review 2026-09-03,
  finding 2.1): on the live S&L Advocates instance, only 2 of 14 real
  practice groups had a retention policy, so `jobs/overdue_sweep.php`'s
  exact-match lookup silently never fired for matters in the other 12.
  Admin → Retention Policies now has a dedicated "Firm-wide default"
  card, separate from the per-practice-group table, that the sweep job
  falls back to for any practice group with no policy of its own —
  including ones added later — and the same screen lists exactly which
  groups are currently relying on it (or, if no default is set, are
  completely uncovered). Backed by a sentinel `practice_area` value
  (`CUSTODIA_RETENTION_DEFAULT_PRACTICE_AREA` in
  `includes/retention_policies.php`), not a schema change — no upgrade
  script needed.
- **Audit hash-chain integrity verification** was click-only (the Audit
  Explorer's "Verify Chain Integrity" button) with no scheduled equivalent
  — security review 2026-09-03, finding 4.2. `jobs/verify_audit_chain.php`
  now runs the same check on a schedule, records the result either way, and
  notifies every active System Admin/Records Manager only if the chain is
  actually found broken. Also see finding 4.3 below: routine `VIEW` events
  are now excluded from the chain entirely (still logged, just not hashed),
  so both this job and the button itself have materially less to check at
  current audit-log volume.
- **No self-service "forgot password" flow.** A System Administrator can
  create accounts, edit profiles/roles, and reset any user's password from
  Admin → User Accounts (`includes/users.php`) — but that's admin-driven
  (the admin sets the value and shares it with the user directly), not
  self-service. The last active System Administrator can't be deactivated
  or demoted, so the account screen can't lock everyone out. As of the
  2026-09-03 security review (finding 1.1), every admin-set password — new
  account, admin-driven reset, or bulk CSV import — forces the account
  through `change_password.php` on its next sign-in (`must_reset_password`
  in `includes/auth.php`'s `custodia_require_login()`) before it can reach
  any other page, so an admin-known password never stays the account's real
  password.
- **Account lockout and idle-session timeout** (security review 2026-09-03,
  finding 1.6). `custodia_attempt_login()` in `includes/auth.php` locks an
  account for `security.lockout_minutes` (default 15) after
  `security.max_failed_logins` (default 5) consecutive failed sign-ins —
  configurable via `CUSTODIA_MAX_FAILED_LOGINS`/`CUSTODIA_LOCKOUT_MINUTES`
  env vars (see `includes/config.php`); while locked, the password is never
  even checked, and login.php shows a distinct "temporarily locked"
  message. An admin-issued password reset (Admin → User Accounts) also
  lifts a lock immediately, without waiting out the timer. Separately,
  `custodia_current_user()` treats any session with no recorded activity
  for longer than `security.idle_timeout_minutes` (default 20,
  `CUSTODIA_IDLE_TIMEOUT_MINUTES`) as signed out — the next request bounces
  to `login.php` with a "signed out after a period of inactivity" notice.
  This is a hard sign-out, not a lighter lock-screen-that-keeps-your-place;
  MFA is still not implemented (the original blueprint's stated posture for
  System Admin/Records Manager/Partner roles) — flagged as a follow-up, not
  built here.
- **Automated browser (E2E) test suite** — `tests/browser/` (Playwright),
  a dev/CI-only tool kept outside the deployed app so the app itself stays
  dependency-free (no build step, no Composer, no npm at runtime). Covers
  authentication (login, lockout, forced password reset), RBAC across
  built-in and custom admin-configured roles including audit-log scoping,
  security headers, and the physical-file custody workflow (auto-approved
  vs. pending-approval checkout, and the data-driven managing-partner
  override). `tests/smoke.php` still covers the business logic layer
  directly; this suite exercises the same logic through real HTTP
  requests and a real browser session. See `tests/browser/README.md` for
  how to run it and its one known limitation (the Bootstrap modal itself
  isn't click-tested — its CDN dependency isn't reachable from every
  environment — so the suite drives the same action endpoints the modal
  calls directly instead).
- **PDF text extraction is best-effort, not a full PDF parser.** It handles
  typical born-digital PDFs (Word/PDF exports — the common case for legal
  documents) well, but can miss or garble text in PDFs using heavily
  subsetted/custom-encoded fonts, and finds nothing at upload time in
  scanned/image-only PDFs — those are picked up shortly after by the OCR
  sweep instead (see the OCR bullets above). `.doc`/`.xls`/`.ppt` (legacy
  binary Office formats) are not extracted at all (flagged `UNSUPPORTED`,
  not silently guessed at); standalone images are OCR'd inline (see above).
- **Full-text search on short/numeric tokens is limited by MySQL's own
  FULLTEXT defaults** (`innodb_ft_min_token_size`, default 3 characters) —
  a purely numeric dollar amount like `$12,000,000` tokenizes into pieces
  too short to index well. Searching by title, description, or the bare doc
  number (e.g. `142`) always works regardless, since those go through exact
  matching, not the FULLTEXT index.
- **Version comparison pairs up equal-length runs of changed paragraphs**
  (see `includes/text_diff.php`) rather than doing a fully general reflow-
  aware diff — if an edit inserts or removes whole paragraphs so the counts
  on each side of a changed block don't match, those paragraphs show as
  plain block deletions/insertions instead of a paired word-level redline.
  Still correct, just less finely highlighted for that specific case.

## Design notes worth knowing

- **Every internal link, form action, redirect, and fetch() URL is relative, never root-absolute** (`documents.php`, `actions/download_document.php`, not `/documents.php`, `/actions/download_document.php`) — deliberately, so the app works correctly whether it's mounted at the web root (the "Local quick-start" `php -S` setup) or under a subfolder (the Setup section's documented XAMPP layout, `http://localhost/custodia-php/...`). This works without computing a base path anywhere because every page lives flat in the project root (no subdirectory routing), so relative resolution against "the current page's own directory" always lands in the right place. Verified by actually deploying under a `custodia-php` subfolder (a directory junction to this project, so there's still only one copy of the code) and clicking all the way through it — login, every nav link, tab, preview, download, and an actual AJAX form submission — not just curling individual endpoints with full URLs, which doesn't exercise this at all. If you ever reintroduce a root-absolute `/whatever.php` anywhere, this breaks again.
- **`assets/js/app.js` loads in `<head>`, not at the end of `<body>` before `</html>`** (where Bootstrap's own JS bundle still loads). Every page's inline `<script>` block calls `custodiaWireActionForm()` etc. at parse time, not inside an event handler — if app.js loaded after those inline scripts (as it originally did, alongside Bootstrap's bundle), every one of those calls throws `custodiaWireActionForm is not defined` in a real browser, silently leaving every "Create"/"Add" modal's submit button doing a bare page reload instead of the intended AJAX submit. `tests/smoke.php` and curl-based endpoint testing both stayed green through this the entire time because neither executes JavaScript — it only showed up once something actually loaded the pages in a browser and submitted a form. app.js only defines functions (no top-level DOM access), so loading it this early is safe.
- **`custodiaFlash()` (the success/error banner shown after an AJAX action) used to reference a `<main>` element that doesn't exist anywhere in this app's markup** (`includes/layout_header.php` wraps page content in `<div class="app-content">`, not `<main>`), so it threw `Cannot read properties of null (reading 'prepend')` on every successful action — upload, lock/unlock, user account management, all of it — right after the action had already completed. The action itself always succeeded; only the confirmation banner crashed, which also meant the page never got the `window.location.reload()` that was supposed to follow it. Now targets `.app-content` with `main`/`document.body` fallbacks so a future layout change can't silently reintroduce this.
- **Local assets (`assets/css/app.css`, `assets/js/app.js`) are served with a `?v=<mtime>` query string** (`custodia_asset_version()` in `includes/helpers.php`), because Apache sends no `Cache-Control` header for static files here — without a version bump, a browser that already loaded a page can keep serving a stale cached copy of app.js/app.css indefinitely after a fix ships, which looks exactly like "the fix didn't work" (this is literally how the `custodiaFlash()` bug above first appeared to persist after being fixed, until this was added). The version value is the file's own mtime, so every edit gets a new URL automatically — no manual bumping, no build step.
- **The hash-chain's concurrency control differs from the Node version by
  necessity.** Postgres has a transaction-scoped advisory lock
  (`pg_advisory_xact_lock`) that the original `AuditService` used to
  serialize "read the latest hash, then append." MySQL/MariaDB has no
  direct equivalent, so this port uses a well-known MySQL pattern instead:
  a singleton `audit_chain_state` row that every writer does
  `SELECT ... FOR UPDATE` on before reading the chain tip. InnoDB holds
  that row lock until commit, which serializes writers the same way the
  advisory lock did.
- **Not every audit_log row is chain-hashed.** Security review 2026-09-03,
  finding 4.3: at current volume, routine `VIEW` actions (opening a matter,
  opening a digital document) were themselves generating 89,000+
  chain-locked writes in 30 days — a real contention point on that one
  `audit_chain_state` row. `custodia_audit_record()` now takes a `chained`
  flag; VIEW call sites pass `chained => false` and skip the lock and the
  hash entirely (a plain, actual `DOWNLOAD` of a document still goes
  through the full chain — only the inline-view case is exempt). Those rows
  still get written — `audit_log` stays append-only and every VIEW is still
  logged, filterable, and exportable — they're just not part of the
  cryptographic chain, and `custodia_audit_verify_chain()` only ever walks
  rows where `entry_hash IS NOT NULL`. Existing installs need
  `sql/upgrade_019_unchained_view_events.sql` (makes `prev_hash`/
  `entry_hash` nullable) — a fresh install's `sql/schema.sql` already has it.
- **UUIDs are generated in PHP**, not by MySQL, matching the earlier
  version's approach — application code always knows the id of a row it
  just created, and it keeps the schema portable.
- **Tabs on the matter detail page reload the page** (`?tab=documents`
  etc.) rather than switching client-side. This is a deliberate simplicity
  trade-off consistent with "plain PHP, no framework" — a SPA-style
  client-side router was exactly the kind of complexity this rebuild was
  meant to avoid.
