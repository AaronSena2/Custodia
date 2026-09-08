// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAs, attemptLogin, DEMO_PASSWORD } = require('./fixtures');
const db = require('../fixtures/db');

test.describe('Authentication', () => {
  test('unauthenticated visitor to dashboard.php is redirected to login.php', async ({ page }) => {
    await page.goto('/dashboard.php');
    await expect(page).toHaveURL(/login\.php/);
  });

  test('successful login reaches the dashboard and shows the signed-in user', async ({ page }) => {
    const user = await loginAs(page, 'sysadmin');
    await expect(page).toHaveURL(/dashboard\.php/);
    await expect(page.locator('.sidebar-user-name')).toHaveText('Sam Okafor');
    await expect(page.locator('.sidebar-user-role')).toHaveText('System Administrator');
    void user;
  });

  test('wrong password shows a generic error and does not sign in', async ({ page }) => {
    await attemptLogin(page, 'sam.okafor@custodia.demo', 'not-the-right-password');
    await expect(page).toHaveURL(/login\.php/);
    await expect(page.locator('.alert-danger')).toHaveText('Incorrect email or password.');
  });

  test('account locks after the configured number of failed attempts (security review 2026-09-03, finding 1.6)', async ({ page }) => {
    const email = `e2e.lockout.${Date.now()}@custodia.test`;
    db.createUser({ email, password: DEMO_PASSWORD, role: 'PARALEGAL', fullName: 'E2E Lockout Test' });

    try {
      // Default threshold is 5 (CUSTODIA_MAX_FAILED_LOGINS) — 4 wrong
      // attempts should still show the generic message, not lock yet.
      for (let i = 0; i < 4; i++) {
        await attemptLogin(page, email, 'wrong-password');
        await expect(page.locator('.alert-danger')).toHaveText('Incorrect email or password.');
      }

      // 5th failure crosses the threshold and locks the account.
      await attemptLogin(page, email, 'wrong-password');
      await expect(page.locator('.alert-danger')).toContainText('temporarily locked');

      // Even the *correct* password is rejected while locked — the
      // password is never checked once locked (can't be raced).
      await attemptLogin(page, email, DEMO_PASSWORD);
      await expect(page).toHaveURL(/login\.php/);
      await expect(page.locator('.alert-danger')).toContainText('temporarily locked');
    } finally {
      db.deleteUser(email);
    }
  });

  test('forced password reset (security review 2026-09-03, finding 1.1): a flagged account is bounced to change_password.php until it sets its own password', async ({ page }) => {
    const email = `e2e.reset.${Date.now()}@custodia.test`;
    db.createUser({ email, password: DEMO_PASSWORD, role: 'PARALEGAL', fullName: 'E2E Reset Test', mustResetPassword: true });

    try {
      await attemptLogin(page, email, DEMO_PASSWORD);
      // Signs in fine, but every page bounces to change_password.php.
      await expect(page).toHaveURL(/change_password\.php/);

      await page.goto('/dashboard.php');
      await expect(page).toHaveURL(/change_password\.php/);

      // Wrong current password is rejected.
      await page.fill('#current_password', 'wrong-current-password');
      await page.fill('#new_password', 'BrandNewPassword1!');
      await page.fill('#confirm_password', 'BrandNewPassword1!');
      await page.click('button[type="submit"]');
      await expect(page).toHaveURL(/change_password\.php/);
      await expect(page.locator('.alert-danger')).toBeVisible();

      // Correct current password + matching new password clears the flag.
      await page.fill('#current_password', DEMO_PASSWORD);
      await page.fill('#new_password', 'BrandNewPassword1!');
      await page.fill('#confirm_password', 'BrandNewPassword1!');
      await page.click('button[type="submit"]');
      await page.waitForLoadState('networkidle');

      // The app is now fully reachable, and the old shared password no
      // longer works.
      await page.goto('/dashboard.php');
      await expect(page).toHaveURL(/dashboard\.php/);

      await page.goto('/logout.php');
      await attemptLogin(page, email, DEMO_PASSWORD);
      await expect(page.locator('.alert-danger')).toHaveText('Incorrect email or password.');

      await attemptLogin(page, email, 'BrandNewPassword1!');
      await expect(page).toHaveURL(/dashboard\.php/);
    } finally {
      db.deleteUser(email);
    }
  });
});
