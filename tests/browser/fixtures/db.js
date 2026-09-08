// @ts-check
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const FIXTURE_SCRIPT = path.join(__dirname, 'db_fixture.php');
const PHP_BIN = process.env.CUSTODIA_PHP_BIN || 'php';

/** Shells out to db_fixture.php — see that file for why this is raw SQL rather than going through the app's own PHP functions. */
function fixture(command, args = {}) {
  const out = execFileSync(PHP_BIN, [FIXTURE_SCRIPT, command, JSON.stringify(args)], {
    encoding: 'utf8',
  });
  return JSON.parse(out);
}

module.exports = {
  createUser: (args) => fixture('create-user', args),
  deleteUser: (email) => fixture('delete-user', { email }),
  createRole: (args) => fixture('create-role', args),
  deleteRole: (roleKey) => fixture('delete-role', { roleKey }),
  resetLockout: (email) => fixture('reset-lockout', { email }),
  setMatterIncharge: (matterId, userId) => fixture('set-matter-incharge', { matterId, userId }),
  addTeamMember: (matterId, userId, roleOnMatter) => fixture('add-team-member', { matterId, userId, roleOnMatter }),
  removeTeamMember: (matterId, userId) => fixture('remove-team-member', { matterId, userId }),
  getMatterIdByNumber: (matterNumber) => fixture('get-matter-id-by-number', { matterNumber }).id,
  getFileByBarcode: (barcode) => fixture('get-file-id-by-barcode', { barcode }),
  getFirstLocationId: () => fixture('get-first-location-id', {}).id,
  setFileStatus: (barcode, status, opts = {}) =>
    fixture('set-file-status', {
      barcode,
      status,
      custodianId: opts.custodianId ?? null,
      locationId: opts.locationId ?? null,
    }),
};
