// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAs } = require('./fixtures');

/** The exact CSP string custodia_send_security_headers() sends (includes/security_headers.php) — kept in sync deliberately, so a drift here is a real regression signal. */
const EXPECTED_CSP =
  "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " +
  "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; " +
  "font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'; object-src 'self'; " +
  "media-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

function assertSecurityHeaders(headers) {
  expect(headers['x-frame-options']).toBe('DENY');
  expect(headers['x-content-type-options']).toBe('nosniff');
  expect(headers['referrer-policy']).toBe('same-origin');
  expect(headers['content-security-policy']).toBe(EXPECTED_CSP);
  // Security review 2026-09-03, finding 1.4 — expose_php can't be flipped
  // from inside the app, so this is a header_remove() rather than a
  // php.ini change; see includes/security_headers.php.
  expect(headers['x-powered-by']).toBeUndefined();
}

test.describe('Security headers (security review 2026-09-03, findings 1.4 and 1.5)', () => {
  test('unauthenticated login.php carries the full header set', async ({ page }) => {
    const response = await page.goto('/login.php');
    assertSecurityHeaders(response.headers());
  });

  test('an authenticated page carries the full header set', async ({ page }) => {
    await loginAs(page, 'sysadmin');
    const response = await page.goto('/dashboard.php');
    assertSecurityHeaders(response.headers());
  });

  test('an actions/*.php AJAX endpoint carries the full header set', async ({ page }) => {
    await loginAs(page, 'sysadmin');
    const response = await page.goto('/actions/dashboard_analytics.php');
    assertSecurityHeaders(response.headers());
  });

  test('an unauthenticated redirect (302 to login.php) still carries the full header set', async ({ page }) => {
    const response = await page.goto('/dashboard.php');
    assertSecurityHeaders(response.headers());
  });

  test('the CSP does not block the app\'s own inline scripts/handlers (negative-control regression guard)', async ({ page }) => {
    const cspViolations = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error' && /Content Security Policy|Refused to/.test(msg.text())) {
        cspViolations.push(msg.text());
      }
    });

    await loginAs(page, 'sysadmin');
    for (const url of ['/dashboard.php', '/matters.php', '/admin.php', '/scan.php', '/documents.php', '/approvals.php']) {
      await page.goto(url);
      await page.waitForLoadState('networkidle');
    }

    expect(cspViolations, `Unexpected CSP violations:\n${cspViolations.join('\n')}`).toHaveLength(0);
  });
});
