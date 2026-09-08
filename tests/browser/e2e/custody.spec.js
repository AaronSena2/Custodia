// @ts-check
const { test, expect } = require('@playwright/test');
const { loginAs, loginWithCredentials, DEMO_PASSWORD } = require('./fixtures');
const db = require('../fixtures/db');

/**
 * These drive the checkout/check-in/approve AJAX endpoints directly via
 * fetch() inside the page (real cookies, real CSRF token read from the
 * <meta name="csrf-token"> tag every authenticated page renders —
 * includes/layout_header.php), rather than clicking through the Bootstrap
 * modal in scan.php/matter.php. That modal's JS comes from Bootstrap's
 * CDN bundle (assets loaded via <script src="https://cdn.jsdelivr.net/...">
 * in includes/layout_header.php/footer.php) — not reachable from every
 * environment this suite runs in (this sandbox included; see README.md).
 * Driving the same endpoints the modal itself calls (actions/checkout_file.php
 * etc.) still exercises the real server-side business logic and RBAC —
 * the part worth regression-testing — and every result is then verified
 * through ordinary server-rendered navigation (scan.php?barcode=...), no
 * JS dependency either way.
 */
async function callAction(page, actionPath, fields) {
  return page.evaluate(
    async ({ actionPath, fields }) => {
      const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
      const body = new URLSearchParams({ ...fields, csrf_token: token });
      const res = await fetch(actionPath, { method: 'POST', body });
      return { status: res.status, json: await res.json() };
    },
    { actionPath, fields }
  );
}

test.describe('Custody workflow', () => {
  test('System Administrator checkout is auto-approved, and check-in returns the file to IN_REGISTRY', async ({ page }) => {
    const locationId = db.getFirstLocationId();
    // Force the precondition rather than assume it: seed.php only seeds
    // ONE of the three fixture barcodes as IN_REGISTRY (the other two are
    // deliberately seeded CHECKED_OUT/OFFSITE_ARCHIVE as fixture data for
    // the app's own overdue/offsite-archive features), so asserting on
    // whatever state a barcode happened to start in was never safe — this
    // also makes the test self-healing if a previous run's own cleanup
    // didn't run (e.g. the process was killed mid-test).
    db.setFileStatus('PF-000482912', 'IN_REGISTRY', { locationId });
    const file = db.getFileByBarcode('PF-000482912');
    expect(file.status).toBe('IN_REGISTRY');

    await loginAs(page, 'sysadmin');
    await page.goto('/scan.php'); // any authenticated page, just to get the CSRF meta tag + cookies

    try {
      const checkout = await callAction(page, 'actions/checkout_file.php', {
        fileId: file.id,
        reason: 'E2E test checkout.',
        dueBackAt: '2027-01-01',
      });
      expect(checkout.status).toBe(200);
      // checkout_file.php's "data" is the custody_movements row, not the
      // physical_files row — for an auto-approved checkout the MOVEMENT's
      // status is "COMPLETED" (includes/custody.php: custodia_checkout()).
      // The file's own status (asserted below via the rendered page, and
      // via the DB right after) is what actually becomes CHECKED_OUT.
      expect(checkout.json.data.status).toBe('COMPLETED');

      await page.goto(`/scan.php?barcode=PF-000482912`);
      // .barcode-display is used by BOTH the scan-box <input> at the top of
      // the page and the result card's <div>; .first() in DOM order lands
      // on the <input>, whose value isn't text content — scope to the div.
      await expect(page.locator('div.barcode-display')).toContainText('PF-000482912');
      await expect(page.locator('body')).toContainText('Sam Okafor'); // current custodian
      expect(db.getFileByBarcode('PF-000482912').status).toBe('CHECKED_OUT');

      const checkin = await callAction(page, 'actions/checkin_file.php', {
        fileId: file.id,
        locationId,
        reason: 'E2E test return.',
        override: '0',
      });
      expect(checkin.status).toBe(200);
      // custodia_complete_checkin() returns only {id} — no status field —
      // so the file's status is verified directly against the DB instead.
      expect(db.getFileByBarcode('PF-000482912').status).toBe('IN_REGISTRY');
    } finally {
      // Self-cleaning regardless of pass/fail, so a re-run against the same
      // database starts from the same precondition this test itself checks.
      const current = db.getFileByBarcode('PF-000482912');
      if (current.status !== 'IN_REGISTRY') {
        await callAction(page, 'actions/checkin_file.php', {
          fileId: file.id,
          locationId,
          reason: 'E2E cleanup.',
          override: '1',
        });
      }
    }
  });

  test('Paralegal checkout is not auto-approved, and stays PENDING_APPROVAL until a records manager approves it', async ({ page }) => {
    const locationId = db.getFirstLocationId();
    db.setFileStatus('PF-000482913', 'IN_REGISTRY', { locationId });
    const file = db.getFileByBarcode('PF-000482913');
    expect(file.status).toBe('IN_REGISTRY');

    await loginAs(page, 'paralegal');
    await page.goto('/scan.php');

    let movementId;
    try {
      const checkout = await callAction(page, 'actions/checkout_file.php', {
        fileId: file.id,
        reason: 'E2E test checkout — should require approval.',
        dueBackAt: '2027-01-01',
      });
      expect(checkout.status).toBe(200);
      expect(checkout.json.data.status).toBe('PENDING_APPROVAL');
      movementId = checkout.json.data.id;

      await page.goto(`/scan.php?barcode=PF-000482913`);
      // custodia_status_badge() renders IN_REGISTRY as "In Registry" (title
      // case, no underscore) — includes/helpers.php — not the raw enum value.
      await expect(page.locator('body')).toContainText('In Registry'); // file itself untouched while pending

      // Records Manager approves it.
      await loginAs(page, 'recordsManager');
      await page.goto('/approvals.php');
      const approve = await callAction(page, 'actions/approve_movement.php', { movementId });
      expect(approve.status).toBe(200);

      await page.goto(`/scan.php?barcode=PF-000482913`);
      await expect(page.locator('body')).toContainText('Marcus Webb'); // now the custodian
    } finally {
      const current = db.getFileByBarcode('PF-000482913');
      if (current.status !== 'IN_REGISTRY') {
        await loginAs(page, 'sysadmin');
        await page.goto('/scan.php');
        await callAction(page, 'actions/checkin_file.php', {
          fileId: file.id,
          locationId,
          reason: 'E2E cleanup.',
          override: '1',
        });
      }
    }
  });

  test.describe('managing-partner override is data-driven, not hardcoded to the PARTNER role (security review 2026-09-03, finding 2.2)', () => {
    const roleKey = `E2E_SENIOR_ASSOCIATE_${Date.now()}`; // timestamped: a left-behind role (if cleanup can't fully delete it — see db_fixture.php) never collides with a later run
    const email = `e2e.incharge.${Date.now()}@custodia.test`;
    let inchargeUserId;
    let matterId;

    test.beforeAll(() => {
      // No approve_custody_movements permission — the only thing that
      // should let this user approve is being the matter's incharge.
      db.createRole({ roleKey, label: 'E2E Senior Associate', permissions: [] });
      const created = db.createUser({ email, password: DEMO_PASSWORD, role: roleKey, fullName: 'E2E Incharge Test' });
      inchargeUserId = created.id;
      // PF-000479821 (the file this test uses) belongs to M-2024-0187, a
      // RESTRICTED matter — must match the file's actual matter, not just
      // any matter, or custodia_assert_matter_access() throws before the
      // approval logic under test is even reached.
      matterId = db.getMatterIdByNumber('M-2024-0187');
      db.setMatterIncharge(matterId, inchargeUserId);
      // Being managing_partner_id alone does not satisfy the confidentiality
      // gate (includes/matter_access.php layer 3b) for a RESTRICTED/
      // PRIVILEGED matter — only firm-wide role, team membership, approved
      // access, or group access do. Team membership is the realistic way an
      // actual incharge would have that: the matter's own incharge being
      // walled out of the RESTRICTED matter they're in charge of would be a
      // bug in the app, not something this test should route around.
      db.addTeamMember(matterId, inchargeUserId, 'Senior Associate');
    });

    test.afterAll(() => {
      // Restore the matter's original managing partner (seeded value —
      // M-2024-0187's own team, not relevant to other tests) isn't tracked
      // here since no other spec depends on this matter's incharge; still
      // clean up the throwaway team membership/role/user.
      db.removeTeamMember(matterId, inchargeUserId);
      db.deleteUser(email);
      db.deleteRole(roleKey);
    });

    test('a non-Partner role placed in charge of a matter can approve a custody movement on it', async ({ page }) => {
      const locationId = db.getFirstLocationId();
      // PF-000479821 is seeded OFFSITE_ARCHIVE (fixture data for the
      // offsite-archive feature) — force it IN_REGISTRY rather than assume it.
      db.setFileStatus('PF-000479821', 'IN_REGISTRY', { locationId });
      const file = db.getFileByBarcode('PF-000479821');
      expect(file.status).toBe('IN_REGISTRY');

      await loginAs(page, 'paralegal');
      await page.goto('/scan.php');
      const checkout = await callAction(page, 'actions/checkout_file.php', {
        fileId: file.id,
        reason: 'E2E incharge-override test.',
        dueBackAt: '2027-01-01',
      });
      expect(checkout.json.data.status).toBe('PENDING_APPROVAL');
      const movementId = checkout.json.data.id;

      try {
        await loginWithCredentials(page, email, DEMO_PASSWORD);
        await page.goto('/approvals.php');
        const approve = await callAction(page, 'actions/approve_movement.php', { movementId });
        expect(approve.status, `expected the matter incharge to be able to approve despite not being PARTNER role or holding approve_custody_movements: ${JSON.stringify(approve.json)}`).toBe(200);
      } finally {
        const current = db.getFileByBarcode('PF-000479821');
        if (current.status !== 'IN_REGISTRY') {
          await loginAs(page, 'sysadmin');
          await page.goto('/scan.php');
          await callAction(page, 'actions/checkin_file.php', {
            fileId: file.id,
            locationId,
            reason: 'E2E cleanup.',
            override: '1',
          });
        }
      }
    });
  });
});
