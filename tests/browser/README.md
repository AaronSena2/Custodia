# Custodia browser (E2E) test suite

This folder is a **dev/CI-only tool**. It is not part of the deployed
Custodia application and should never be required at runtime — Custodia's
own README describes the app itself as dependency-free (no build step, no
Composer, no npm), and this suite deliberately lives outside that promise
rather than compromising it. Nothing under `tests/` is loaded by any PHP
entry point.

## What it covers

- **Authentication** (`e2e/auth.spec.js`): unauthenticated redirect to
  login, successful login, wrong-password handling, account lockout after
  repeated failed attempts (finding 1.1/1.6), and the forced
  password-reset flow for `must_reset_password` accounts.
- **RBAC** (`e2e/rbac.spec.js`): every `admin.php` sub-tab against each
  built-in role (finding 2.4), plus a custom admin-configured role — built
  through the same `roles`/`role_permissions` tables the Permissions admin
  screen manages — that is granted some admin capabilities and not others,
  and a check that its audit-log visibility is correctly scoped rather
  than firm-wide (security review 2026-09-03, finding 2.2).
- **Security headers** (`e2e/security-headers.spec.js`): CSP,
  X-Frame-Options, X-Content-Type-Options, Referrer-Policy and the absence
  of `X-Powered-By` on both unauthenticated and authenticated responses and
  an AJAX endpoint (findings 1.4/1.5), plus a regression guard that
  browses six real pages as an authenticated user and asserts zero
  CSP-violation console errors.
- **Custody workflow** (`e2e/custody.spec.js`): auto-approved checkout/
  check-in for a role with `auto_approve_checkout`; the
  PENDING_APPROVAL → Records Manager approval path for a role without it;
  and the finding-2.2 fix itself — a non-Partner custom role placed in
  charge of a matter (`matters.managing_partner_id`) can approve a
  custody movement on that matter purely because the data says so, not
  because their role happens to be `PARTNER`.

## How to run

```bash
cd tests/browser
npm install

# Start the app yourself, pointed at a real (ideally disposable/test) database:
php -S 127.0.0.1:8099 -t ../..

# In another shell:
CUSTODIA_BASE_URL=http://127.0.0.1:8099 npx playwright test --workers=1
```

The suite does not start PHP's built-in server itself. It needs to log in
as several different seeded roles across files, and PHP's built-in dev
server is single-threaded — a `webServer`-managed instance shared across
parallel workers would just serialize every request anyway, so running
against a server you start yourself with `--workers=1` is simpler and
avoids a misleading "slow" reading that's really just that serialization.

`CUSTODIA_PHP_BIN` overrides the `php` binary the DB fixture helper shells
out to, if it isn't on `PATH` as `php`.

### Database state

These tests read and write real rows — seeded demo users, seeded physical
files, throwaway custom roles/users created and torn down per test. Run
them against a database you're fine mutating (a local/CI copy seeded via
`seed.php`), never against the firm's live data. Every test either forces
its own preconditions before asserting on them (`db.setFileStatus(...)`
in `custody.spec.js`) or creates its own throwaway fixtures
(`fixtures/db_fixture.php`, via `fixtures/db.js`) rather than assuming
whatever `seed.php` happened to leave a row as — `seed.php` only seeds one
of the three fixture physical files as `IN_REGISTRY`; the other two are
deliberately seeded `CHECKED_OUT`/`OFFSITE_ARCHIVE` as fixture data for
the app's own overdue and offsite-archive features. Every test also
cleans up after itself in a `finally`/`afterAll` block, so the suite is
safe to re-run repeatedly against the same database without a reseed
between runs. One exception: `audit_log` is append-only by design (its
hash chain depends on it), so a throwaway test user who has generated an
audit event can't be hard-deleted — cleanup deactivates them instead
(`is_active = 0`), which is correct app behavior, not a workaround; a
handful of harmless deactivated `e2e.*@custodia.test` rows accumulating
in a long-lived test database is expected.

## Known limitation: the Bootstrap modal UI itself isn't click-tested

`scan.php`/`matter.php`'s checkout/check-in/transfer modals
(`includes/custody_modals.php`) depend on Bootstrap's JS, loaded from
`https://cdn.jsdelivr.net/...` in `includes/layout_header.php` /
`layout_footer.php`. That CDN isn't reachable from every environment this
suite might run in (confirmed unreachable in the sandbox this suite was
built in — `registry.npmjs.org` was reachable, `cdn.jsdelivr.net` was
not), so `custody.spec.js` drives the same endpoints the modal itself
calls (`actions/checkout_file.php`, `actions/checkin_file.php`,
`actions/approve_movement.php`) directly via `fetch()` inside the page,
using the real session cookie and the real CSRF token read from the
`<meta name="csrf-token">` tag every authenticated page renders. This
still exercises the real server-side business logic and RBAC — the part
worth regression-testing — and every result is then verified through
ordinary server-rendered navigation (`scan.php?barcode=...`), so nothing
about the assertions themselves depends on the CDN either way. If a future
environment can reach the CDN reliably, the modal's own click-through
(button → modal opens → form submit → toast/redirect) would be a
worthwhile addition, but isn't required for the coverage this suite
currently provides.

## What this suite intentionally does not cover

It's a targeted regression suite for the specific gaps and fixes above,
not a full functional test of the app. Left out for now (see the project's
`implementation-status.md` for the fuller list of known gaps): SSO/OIDC
integration (still demo-only auth), S3-compatible storage (still
local-disk), and confirmation that the scheduled overdue/retention-sweep
job is registered in the OS task scheduler on the live machine.
