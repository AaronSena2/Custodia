// @ts-check

/**
 * Shared login helpers + the seeded demo accounts (see seed.php). Password
 * is the same for all of them at seed time — see README.md "Demo login".
 */
const DEMO_PASSWORD = 'ChangeMe123!';

const USERS = {
  sysadmin: { email: 'sam.okafor@custodia.demo', role: 'SYSTEM_ADMIN' },
  recordsManager: { email: 'rita.alvarez@custodia.demo', role: 'RECORDS_MANAGER' },
  partner: { email: 'daniel.reyes@custodia.demo', role: 'PARTNER' },
  partnerOutside: { email: 'priya.nair@custodia.demo', role: 'PARTNER' },
  associate: { email: 'elena.cho@custodia.demo', role: 'ASSOCIATE' },
  paralegal: { email: 'marcus.webb@custodia.demo', role: 'PARALEGAL' },
};

/**
 * login.php redirects an already-authenticated session straight to
 * dashboard.php without ever rendering the form — so re-using one `page`
 * to log in as a second user mid-test (custody.spec.js's approval flows,
 * rbac.spec.js's role switches) would otherwise hang forever waiting for
 * an #email field that never appears. logout.php is a plain GET with no
 * CSRF requirement, so hitting it first is a harmless no-op when nobody's
 * logged in yet and makes every login in this suite safe to call back to
 * back on the same page.
 */
async function ensureLoggedOut(page) {
  await page.goto('/logout.php');
}

/** Logs in as the given seeded user via the real login form (not a cookie shortcut, so this also exercises login.php itself). */
async function loginAs(page, userKey, password = DEMO_PASSWORD) {
  const user = USERS[userKey];
  if (!user) {
    throw new Error(`Unknown demo user key: ${userKey}`);
  }
  await ensureLoggedOut(page);
  await page.goto('/login.php');
  await page.fill('#email', user.email);
  await page.fill('#password', password);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
  return user;
}

/** Logs in with a raw email/password pair (for negative-path tests: wrong password, locked account, etc.) without assuming success. */
async function attemptLogin(page, email, password) {
  await ensureLoggedOut(page);
  await page.goto('/login.php');
  await page.fill('#email', email);
  await page.fill('#password', password);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
}

/** Same as attemptLogin, but for a fixture-created account expected to succeed (e.g. a throwaway custom-role test user not in the USERS map above). */
async function loginWithCredentials(page, email, password) {
  await attemptLogin(page, email, password);
}

module.exports = { USERS, DEMO_PASSWORD, loginAs, attemptLogin, loginWithCredentials };
