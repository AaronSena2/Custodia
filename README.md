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
- `admin.php` — retention policy configuration (System Admin + Records Manager); User Accounts and Permissions tabs (gated by the `manage_users` permission, System Admin by default) for creating accounts, editing profiles/roles, deactivating/reactivating, resetting passwords, creating new roles, and configuring which role has which capability
- `help.php` — in-app user manual, open to every role, with a personalized "what can I do" panel and an admin-only live role reference (see "User Manual" below)

## Document management (iManage-style)

Beyond version upload/edit-lock/share-links (the original blueprint's Section 4.5–4.7, still there), the Digital Documents area also has three iManage-inspired capabilities:

- **Document profiles + permanent numbering.** Every document gets a firm-wide sequential number the moment it's created, formatted as `CUS-000142.3` (document 142, version 3) via `custodia_doc_label()` — this stays stable for the document's whole life, the way an iManage DOCID does, so it's safe to reference in an email or a pleading. Alongside the number, each document carries an editable profile: title, description, document type, confidentiality, and an **Author** field (who owns/wrote it — separate from **Creator**, which is fixed at whoever created the record). Edit a profile from the "Edit Profile" button on any document row.
- **Full-text search** (`search.php`). Real text is pulled out of every uploaded file **at upload time** — not a stub — and indexed with a MySQL `FULLTEXT` index on the current version's content, plus title/description/type/doc-number. See `includes/text_extract.php`: `.docx` extraction is reliable (a docx is just a zip of XML, parsed structurally with PHP's built-in `ZipArchive`); `.pdf` extraction is a dependency-free best-effort content-stream parser (reads `Tj`/`TJ` text-showing operators after inflating `FlateDecode` streams with PHP's built-in zlib) that works well on typical born-digital PDFs but can miss text in PDFs with heavily subsetted/custom fonts, and finds nothing in scanned/image-only PDFs (those need real OCR — see "Known gaps"); `.txt`/`.md`/`.csv` are read directly. Search uses MySQL's `BOOLEAN MODE` full-text matching (all query words required, not just any one of them) so results stay precise. Same three-layer RBAC as everywhere else — you only ever see hits inside matters you can already access.
- **Version comparison / redline** (`document_compare.php`). A dependency-free two-level diff (`includes/text_diff.php`): paragraphs are compared first (keeps the LCS diff table small and fast even for a long contract), then any paragraph-for-paragraph edit gets refined to a word-level diff for a proper redline view — additions in green, removals in red-strikethrough. Only available between versions that both extracted cleanly (an image-only PDF version, for instance, has nothing to diff against).
- **Download and in-page preview for any file type**, including PDF, images, audio, and video — `actions/download_document.php` serves the stored file (or a specific version via `?versionId=`) under the same RBAC as every other read, with the real MIME type and original filename captured at upload time (`document_versions.mime_type`/`original_filename`), and supports HTTP Range requests so `<audio>`/`<video>` can seek instead of downloading the whole file first. Click "Preview" on a document row for an in-modal viewer (native PDF embed, `<img>`, `<audio>`, or `<video>`); "Download" always saves the file regardless of type. Uploads are validated against `includes/upload_policy.php` before they're accepted — see "Known gaps" for the size limits that also require a php.ini change.
- **Audio/video duration**, shown next to the Preview/Download buttons and in the preview modal title (e.g. "Deposition Clip — 12:04"). Computed at upload time by `includes/media_metadata.php` — a small dependency-free parser (RIFF chunks for `.wav`, the ISO-BMFF `moov`/`mvhd` box for `.mp4`/`.m4a`/`.mov`, and best-effort MPEG frame-header math with a Xing/VBRI fast path for `.mp3`) rather than a vendored library, matching this project's no-Composer/no-build-step approach elsewhere. `.webm`/`.ogg`/`.avi`/`.mkv` aren't parsed and simply show no duration, rather than a guess.
- **Real OCR for standalone images** (`.jpg`/`.png`/`.gif`/`.webp`/`.bmp`/`.tif`) via a locally installed Tesseract binary — see "Known gaps" for exactly what's covered and what isn't (scanned PDFs aren't yet).

Existing install upgrading from an earlier copy of this project: run `sql/upgrade_002_document_management.sql`, `sql/upgrade_003_file_metadata.sql`, and `sql/upgrade_004_media_metadata.sql` once each via phpMyAdmin's SQL tab against your `custodia` database — they add the new columns/index and backfill values for documents you already have, without touching your existing data. A brand-new install doesn't need any of them; `sql/schema.sql` already includes everything.

## Roles and permissions

Roles are admin-extensible, not fixed — System Admin (the `manage_users` permission) can create new roles from Admin → Permissions ("+ New Role": a key and a label), on top of the six the app ships with (System Admin, Records Manager, Partner, Associate, Paralegal, Guest/Auditor). What each role is *allowed to do* is a separate, admin-editable matrix, also on that screen: a role × capability grid covering 12 flat "may this role do X" gates — managing users, retention policies, creating matters, registering physical files, approving custody movements, auto-approved checkout, overriding a check-in or a document lock, initiating a transfer on someone else's behalf, deciding access requests, exporting the audit log, and verifying the audit chain. Toggle a checkbox and save — every page and every `actions/*.php` endpoint that gates on that capability re-checks the database on the next request, nothing needs restarting. `includes/roles.php` owns the role catalog; `includes/permissions.php` owns the capability matrix.

**What this deliberately does NOT touch**, and why: the deeper three-layer per-matter RBAC in `includes/matter_access.php` (ethical wall → confidentiality tier → team assignment), a matter's managing-partner-specific overrides (a Partner can always approve custody requests or decide access requests on a matter they personally manage, regardless of what the matrix says — that's a fact about the matter's data, not a role privilege), and baseline role-tier rules like "Guest/Auditor can't create documents." Folding those into an admin-editable matrix would be a much bigger, riskier redesign of this app's core security model than a configurable capability list calls for — see `includes/permissions.php`'s and `includes/roles.php`'s docblocks for the full reasoning and `CUSTODIA_PERMISSIONS`/`CUSTODIA_DEFAULT_ROLE_PERMISSIONS`/`CUSTODIA_BUILTIN_ROLES` for the exact catalogs and defaults (the defaults reproduce the app's original hardcoded behavior exactly, so installing either feature changes nothing until an admin edits the matrix or adds a role).

**A newly created role is deliberately narrow.** It gets a key and a label — nothing else — and starts with every permission in the matrix unchecked. It can never become "firm-wide" (see every matter, the way System Admin/Records Manager can) the way a built-in role can; it always sees only matters it's explicitly assigned to, the same tier Associate/Paralegal are in today. Making a role's matter-visibility tier itself configurable was considered and deliberately deferred — it would mean extending `custodia_firm_wide_roles()`-style logic (currently hardcoded to those two roles) across `matters.php`, `audit_query.php`, `physical_files.php`, and `access_requests.php` to be table-driven, a materially bigger change than adding a role key. Renaming a role isn't supported yet.

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

## Known gaps for a production deployment

- **Auth** is session + `password_hash()`/`password_verify()` for demo
  purposes. Swap point for real SSO/OIDC: `includes/auth.php`'s
  `custodia_attempt_login()` — replace the password check with a token
  verification call against the firm's IdP (Azure AD / Okta / Keycloak),
  then populate `$_SESSION['user_id']` the same way afterward.
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
  other document. If Tesseract isn't installed, image uploads just fall back
  to their prior behavior (`extraction_status = 'FAILED'`, no text) rather
  than erroring. **Scanned/image-only PDFs are still not OCR'd** — that needs
  rasterizing each page to an image first (Ghostscript or poppler), a bigger
  dependency than this deployment currently has; a real production setup
  would add that and move OCR off the request thread onto a job queue for
  large files. The legacy `ocr_text`/`ocr_status` columns are unrelated to
  this and remain unused (see schema comments).
- **The overdue-return sweep and retention-review job** described in the
  blueprint (a nightly pass flagging overdue files / matters past their
  retention window) is not wired up as a scheduled job here — `dashboard.php`
  computes "overdue" live on every page load instead, which is correct but
  doesn't send proactive alerts. A real deployment would add this as a
  cron entry calling a small PHP script (`php jobs/overdue_sweep.php`),
  matching how XAMPP/most PHP hosts already support cron.
- **No self-service "forgot password" flow or account lockout after repeated
  failed logins.** A System Administrator can create accounts, edit profiles/
  roles, and reset any user's password from Admin → User Accounts
  (`includes/users.php`) — but that's admin-driven (the admin sets the value
  and shares it with the user directly), not self-service. The last active
  System Administrator can't be deactivated or demoted, so the account
  screen can't lock everyone out.
- No automated browser/UI tests — `tests/smoke.php` covers the business
  logic layer thoroughly; the pages themselves were verified manually via
  curl during development (see "Tests" above) rather than with something
  like Playwright.
- **PDF text extraction is best-effort, not a full PDF parser.** It handles
  typical born-digital PDFs (Word/PDF exports — the common case for legal
  documents) well, but can miss or garble text in PDFs using heavily
  subsetted/custom-encoded fonts, and finds nothing at all in scanned/
  image-only PDFs — see the OCR bullet above for why those specifically
  aren't covered yet. `.doc`/`.xls`/`.ppt` (legacy binary Office formats)
  are not extracted at all (flagged `UNSUPPORTED`, not silently guessed at);
  standalone images are OCR'd instead (see above).
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
- **UUIDs are generated in PHP**, not by MySQL, matching the earlier
  version's approach — application code always knows the id of a row it
  just created, and it keeps the schema portable.
- **Tabs on the matter detail page reload the page** (`?tab=documents`
  etc.) rather than switching client-side. This is a deliberate simplicity
  trade-off consistent with "plain PHP, no framework" — a SPA-style
  client-side router was exactly the kind of complexity this rebuild was
  meant to avoid.
