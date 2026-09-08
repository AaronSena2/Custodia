// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAs, loginWithCredentials, DEMO_PASSWORD } = require('./fixtures');
const db = require('../fixtures/db');

/** Every admin.php sub-tab and the heading that proves it actually rendered (not the 403 page, not the retention fallback). */
const ADMIN_TABS = {
  retention: 'Retention Policy Configuration',
  users: 'User Accounts',
  permissions: 'Permissions',
  practicegroups: 'Practice Groups',
  locations: 'Physical Locations',
  audit: 'Audit Log',
};

async function expect403(page, url) {
  await page.goto(url);
  await expect(page.locator('h1')).toHaveText('403');
  await expect(page.locator('body')).toContainText("You don't have permission to view this page.");
}

async function expectTabRenders(page, tab) {
  await page.goto(`/admin.php?tab=${tab}`);
  await expect(page.locator('h1.page-title')).toHaveText(ADMIN_TABS[tab]);
}

test.describe('RBAC — admin.php sub-tabs (security review 2026-09-03, finding 2.4)', () => {
  test('Paralegal is denied every admin.php tab, including retention', async ({ page }) => {
    await loginAs(page, 'paralegal');
    for (const tab of Object.keys(ADMIN_TABS)) {
      await expect403(page, `/admin.php?tab=${tab}`);
    }
  });

  test('Associate is denied every admin.php tab', async ({ page }) => {
    await loginAs(page, 'associate');
    await expect403(page, '/admin.php?tab=retention');
    await expect403(page, '/admin.php?tab=users');
  });

  test('Records Manager (default perms: retention only) can reach retention but is 403\'d on the rest', async ({ page }) => {
    await loginAs(page, 'recordsManager');
    await expectTabRenders(page, 'retention');
    for (const tab of ['users', 'permissions', 'practicegroups', 'locations', 'audit']) {
      await expect403(page, `/admin.php?tab=${tab}`);
    }
  });

  test('System Administrator can reach every tab', async ({ page }) => {
    await loginAs(page, 'sysadmin');
    for (const tab of Object.keys(ADMIN_TABS)) {
      await expectTabRenders(page, tab);
    }
  });
});

test.describe('RBAC — custom roles actually plug into the permission system (security review 2026-09-03, finding 2.2)', () => {
  const roleKey = `E2E_LEGAL_CLERK_${Date.now()}`; // timestamped: a left-behind role (if cleanup can't fully delete it — see db_fixture.php) never collides with a later run
  const email = `e2e.clerk.${Date.now()}@custodia.test`;
  const clerkFullName = 'E2E Legal Clerk';
  let clerkUserId;
  let ownMatterId; // the clerk is a team member here — generates a VIEW audit event attributed to them
  let otherMatterId; // the clerk has no access here — System Admin's own VIEW event on it must stay invisible to the clerk

  test.beforeAll(() => {
    db.createRole({
      roleKey,
      label: clerkFullName,
      // Deliberately NOT manage_users — the clerk should reach the
      // retention tab (has manage_retention_policies) and the audit tab
      // (has view_audit_log), but nothing else.
      permissions: ['manage_retention_policies', 'view_audit_log'],
    });
    const created = db.createUser({ email, password: DEMO_PASSWORD, role: roleKey, fullName: clerkFullName });
    clerkUserId = created.id;
    ownMatterId = db.getMatterIdByNumber('M-2024-0142');
    otherMatterId = db.getMatterIdByNumber('M-2024-0187');
    db.addTeamMember(ownMatterId, clerkUserId, 'Paralegal');
  });

  test.afterAll(() => {
    db.removeTeamMember(ownMatterId, clerkUserId);
    db.deleteUser(email);
    db.deleteRole(roleKey);
  });

  test('a custom role granted an admin-area permission can reach admin.php, and is still 403\'d on tabs it was not granted', async ({ page }) => {
    await loginWithCredentials(page, email, DEMO_PASSWORD);
    await expectTabRenders(page, 'retention');
    await expectTabRenders(page, 'audit');
    await expect403(page, '/admin.php?tab=users');
    await expect403(page, '/admin.php?tab=practicegroups');
  });

  test('a custom role granted view_audit_log sees only its own actions, not a firm-wide role\'s (the finding\'s over-grant bug, now closed)', async ({ page, browser }) => {
    // System Administrator (firm-wide role) views a matter the clerk has no
    // access to, generating a VIEW audit event attributed to Sam Okafor.
    const adminContext = await browser.newContext();
    const adminPage = await adminContext.newPage();
    await loginAs(adminPage, 'sysadmin');
    await adminPage.goto(`/matter.php?id=${otherMatterId}`);
    await expect(adminPage.locator('h1, .page-title').first()).toBeVisible();
    await adminContext.close();

    // The clerk views their own assigned matter, generating a VIEW event
    // attributed to them, then checks the audit tab.
    await loginWithCredentials(page, email, DEMO_PASSWORD);
    await page.goto(`/matter.php?id=${ownMatterId}`);
    await expect(page.locator('h1, .page-title').first()).toBeVisible();

    await page.goto('/admin.php?tab=audit');
    // Scoped to the results table, not the whole page — the actor filter
    // dropdown legitimately lists every user's name as a selectable option
    // regardless of RBAC scope, which isn't what this test is checking.
    const auditRows = page.locator('table.table-hover tbody');
    await expect(auditRows).toContainText(clerkFullName);
    await expect(auditRows).not.toContainText('Sam Okafor');
  });
});
