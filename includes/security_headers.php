<?php
/**
 * Security review 2026-09-03, finding 1.5: no Content-Security-Policy,
 * X-Frame-Options, X-Content-Type-Options, or Referrer-Policy header was
 * present on any page — leaving the app more exposed to clickjacking,
 * MIME-sniffing, and making any future XSS more damaging than it needs to
 * be. Also carries the app-layer half of finding 1.4 (server/framework
 * fingerprinting) — see custodia_send_security_headers()'s last line.
 *
 * custodia_send_security_headers() is called once per request from
 * custodia_start_session() (includes/auth.php) — the one chokepoint every
 * PHP entry point in the app passes through before any output: every
 * top-level page (via custodia_require_login()/custodia_current_user()),
 * every actions/*.php endpoint (via custodia_run_action() in
 * includes/action_bootstrap.php, which calls custodia_start_session()
 * directly), and actions/download_document.php (calls
 * custodia_current_user() directly, same as every page). Static files under
 * assets/ are served straight by Apache and never touch PHP at all — those
 * get the httpd-layer equivalent from the app's own root .htaccess instead.
 */

function custodia_send_security_headers(): void
{
    static $sent = false;
    if ($sent || headers_sent()) {
        return;
    }
    $sent = true;

    // Clickjacking: nothing in this app ever legitimately frames it (no
    // <iframe> of it exists anywhere in the codebase), so deny outright
    // rather than the weaker SAMEORIGIN.
    header('X-Frame-Options: DENY');

    // Stops a browser from re-interpreting a response's declared
    // Content-Type based on sniffing its content (e.g. treating an uploaded
    // .txt whose contents look like markup as HTML/JS). Matters most for
    // actions/download_document.php, which already set this itself — see
    // finding 1.5's write-up for why that alone wasn't enough — but every
    // response gets it now, not just downloads.
    header('X-Content-Type-Options: nosniff');

    // Matter/document/client IDs and search terms show up in query strings
    // throughout the app (matter.php?id=..., search.php?q=..., etc.) — don't
    // leak the full referring URL to a third-party origin a user navigates
    // to from inside the app. same-origin still sends the full referrer for
    // navigations that stay inside the app itself.
    header('Referrer-Policy: same-origin');

    // Scoped to what the app actually loads: Bootstrap + Chart.js from
    // jsDelivr (includes/layout_header.php / layout_footer.php) and nothing
    // else external. The app relies extensively on inline <script> blocks,
    // onclick/onchange handlers, and inline style="" attributes throughout
    // (vanilla JS, no build step) — a strict nonce/hash-based CSP would mean
    // reworking markup on every page, which is out of scope for this fix, so
    // 'unsafe-inline' is kept for script-src/style-src. That still blocks
    // the more common real-world risk this finding calls out: a future XSS
    // (or a compromised/typosquatted CDN) trying to load or exfiltrate to a
    // domain the app doesn't already trust. object-src/media-src 'self'
    // covers the <embed>/<audio>/<video> document-preview elements in
    // includes/document_modals.php, which always point at this app's own
    // actions/download_document.php, never anywhere else.
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
        "img-src 'self' data:",
        "font-src 'self' https://cdn.jsdelivr.net",
        "connect-src 'self'",
        "object-src 'self'",
        "media-src 'self'",
        "frame-ancestors 'none'",
        "base-uri 'self'",
        "form-action 'self'",
    ]);
    header("Content-Security-Policy: {$csp}");

    // Security review 2026-09-03, finding 1.4: every response also carried
    // X-Powered-By: PHP/<exact version>, handed to anyone who requests a
    // page. expose_php (the php.ini directive that adds this header) is
    // PHP_INI_SYSTEM scope — it can only be set in php.ini itself or an
    // Apache <Directory>/vhost's php_admin_flag, never in .htaccess and
    // never at runtime via ini_set() — so it can't be turned off from
    // inside the app the way everything else in this file can. But PHP
    // still lets a script strip a header the SAPI already queued, whatever
    // set it: header_remove() operates on the outgoing header list itself,
    // not on php.ini, so this reliably removes X-Powered-By regardless of
    // expose_php's setting or which SAPI is serving the request (php -S,
    // Apache+mod_php, PHP-FPM). Confirmed live against a running php -S
    // server with expose_php's default (On) — see implementation-status.md
    // for the before/after. The Apache-layer half of this finding
    // (Server: Apache/... / ServerTokens / ServerSignature) is httpd.conf
    // configuration outside this app's own files entirely — see
    // DEPLOYMENT.md.
    header_remove('X-Powered-By');
}
