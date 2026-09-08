# Deployment recommendations

Written in response to the security review (2026-09-03), finding 4.6:
*"The application is being served in a way consistent with a development
setup (PHP's built-in server or a bare local Apache/XAMPP install on a
Windows workstation), reachable at a private LAN IP rather than through a
managed, backed-up production host. There's no visible evidence of
scheduled backups, log rotation, or process supervision."*

This is guidance, not code — there's no single "production XAMPP" install
step to script, and the review's own recommendation explicitly allows
staying on-premises. What follows is a concrete checklist, roughly ordered
by effort, to close the gap the review flagged. None of it is optional once
this instance holds real client data, even short-term.

## Where this stands today

- The instance the review looked at was reachable at `192.168.18.142:8080`,
  consistent with either PHP's built-in server (`php -S`, per
  `implementation-status.md`'s own notes on how this was last run) or
  XAMPP's Apache in its default interactive Control-Panel mode.
- Two scheduled jobs now exist (`jobs/verify_audit_chain.php`,
  `jobs/overdue_sweep.php` — see the Known Gaps section of `README.md`) that
  depend on *something* reliably invoking them on a schedule. Windows Task
  Scheduler entries are the recommended mechanism (each job's own header
  comment has the exact `schtasks` command), but Task Scheduler only fires
  if the machine is on, logged in or configured to run whether logged in or
  not, and not asleep — which is exactly the kind of gap "process
  supervision" below is about.

## 1. Stop running the app from an interactive terminal or Control Panel session

`php -S` run from a Downloads folder, or XAMPP's Control Panel left open,
both stop the instant that terminal/window closes, the user logs out, or
the machine reboots — with no automatic restart. Neither is acceptable for
something holding real client matters.

- **Minimum fix, same machine:** install XAMPP's Apache and MySQL as actual
  Windows Services rather than running them from the Control Panel —
  XAMPP's Control Panel has an "Install Service" checkbox/button for both.
  Services start automatically on boot and are supervised by the Windows
  Service Manager (restart-on-failure can be configured per-service via
  `services.msc` → right-click → Recovery).
- **Better:** move off a single workstation entirely onto a server-class
  Windows machine (or a Linux host — PHP/MySQL/Bootstrap has no Windows
  dependency, only the XAMPP *installer* is Windows-specific) that isn't
  someone's day-to-day laptop, doesn't sleep, and has restricted physical
  and network access.
- **Whichever of the two you do, apply this at the same time** (security
  review 2026-09-03, finding 1.4): the review observed every response
  carrying `Server: Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12` and
  `X-Powered-By: PHP/8.2.12` — exact OS/httpd/TLS/PHP version numbers,
  handed to anyone who requests a page. `X-Powered-By` is now suppressed at
  the application layer regardless of deployment mode (`header_remove()` in
  `includes/security_headers.php` — see that file's comment for why
  `expose_php = Off` alone isn't reachable from inside the app). The
  `Server: Apache/...` string can only be fixed in Apache's own config,
  which is why it's here rather than in the app itself — add to
  `C:\xampp\apache\conf\httpd.conf`:
  ```
  ServerTokens Prod
  ServerSignature Off
  ```
  and confirm `C:\xampp\php\php.ini` has:
  ```
  expose_php = Off
  ```
  (belt-and-suspenders with the app-layer `header_remove()` fix above — one
  covers `php -S`, the other covers Apache+mod_php once this section's move
  to a supervised Apache happens; either alone leaves a gap the other
  closes). **Restart Apache from the XAMPP Control Panel** (or restart the
  Windows Service, once installed as one per the bullet above) for
  `httpd.conf`/`php.ini` changes to take effect — neither is picked up
  without a restart. As of this fix, `C:\xampp\htdocs` still only holds
  XAMPP's own default splash-page folders and PHP's built-in dev server
  (confirmed directly: `php -S` on this app's own PHP version sends no
  `Server` header at all) — so today's actual deployment likely isn't
  emitting the review's exact `Server: Apache/...` string either way; this
  is here so it's already in place the moment §1's move to real,
  supervised Apache happens, rather than a second finding waiting to be
  rediscovered later.

## 2. Scheduled, verified database backups

There is currently no evidence of any backup of the `custodia` MySQL
database — with 8,084+ matters and 5,824 clients, this is the single
highest-impact gap on this list; a disk failure or ransomware event with no
backup is a total-loss, not just downtime.

- Nightly `mysqldump`, scripted and scheduled (Task Scheduler, same pattern
  as the jobs above):
  ```
  "C:\xampp\mysql\bin\mysqldump.exe" -u custodia -p<password> custodia > D:\backups\custodia_%date:~-4,4%%date:~-10,2%%date:~-7,2%.sql
  ```
  (adjust paths; consider `--single-transaction` for a consistent snapshot
  without locking tables during the dump).
- **Back up to a *different* disk than the one MySQL runs on**, and ideally
  off the machine entirely — the same `.gitignore` in this project already
  references "the 'Custodia Weekly Backup' scheduled task / OneDrive" for
  uploaded documents (`storage/`); the database deserves at least the same
  treatment, on its own schedule (nightly, not weekly, given transaction
  volume).
- **Set a retention window** (e.g. keep 30 daily + 12 monthly) so backups
  don't silently fill the disk — a backup job that fails because the disk
  is full is worse than no backup job, because it *looks* configured.
- **Actually test a restore periodically.** An untested backup is a
  hypothesis, not a backup. Restoring the latest dump into a scratch
  database and running `tests/smoke.php` against it is a reasonable, cheap
  verification to do monthly.
- Uploaded documents (`storage/`) need the same discipline — they're
  already `.gitignore`d specifically because they're "real client data,
  not source code. Backed up separately" per that file's own comment;
  confirm that backup is actually running and tested, not just documented.

## 3. Log rotation

Apache's access/error logs and PHP's own error log (`php.ini`'s
`error_log` directive) grow unbounded by default on a Windows/XAMPP
install — nothing rotates or prunes them.

- XAMPP's bundled Apache doesn't rotate logs out of the box on Windows the
  way it does via `logrotate` on Linux. The practical options: (a) a
  scheduled task that periodically archives and truncates
  `xampp\apache\logs\*.log` and `xampp\php\logs\php_error_log`, or (b) if
  moving to a Linux host per §1, use `logrotate` (already standard there).
- At minimum, monitor free disk space — an unrotated log filling the disk
  will eventually take down MySQL and Apache both, which is a worse outage
  than the log noise itself.

## 4. Confirm the two new scheduled jobs are actually wired up

Once `jobs/verify_audit_chain.php` and `jobs/overdue_sweep.php` are
registered with Task Scheduler (commands in each file's own header
comment), confirm the tasks are set to **"Run whether user is logged on or
not"** in their Task Scheduler properties — the default "only when logged
on" setting means both silently stop firing the moment the account isn't
actively logged in, which defeats the point of moving them off the
dashboard's live page-load computation in the first place.

## 5. Longer-term

The review's own framing — "even if it stays on-premises" — sets the bar
at *supervised and backed up*, not necessarily cloud-hosted. That said, if
and when this firm's usage grows past what one supervised on-prem host
comfortably handles, the natural next step is a managed database (so
backups/patching/failover are the provider's job, not a scheduled task
someone has to remember exists) and a proper application host (a small VM
or container behind a reverse proxy) rather than XAMPP on a workstation.
Nothing in this codebase assumes Windows or XAMPP specifically — `sql/`
schema and `includes/config.php`'s environment-variable overrides already
support pointing at any MySQL-compatible host.
