// @ts-check
const { defineConfig, devices } = require('@playwright/test');

/**
 * Config for Custodia's browser (E2E) test suite. This whole tests/browser/
 * folder is a dev/CI-only tool — see README.md in this directory for what
 * it is, why it lives outside the deployed app, and how to run it.
 *
 * BASE_URL points at an already-running instance of the app (this suite
 * does not start PHP's built-in server itself — see README.md for why:
 * the suite needs to log in as several different seeded roles across
 * files, and PHP's built-in dev server is single-threaded, so a
 * webServer-managed instance shared across parallel workers would
 * serialize every request anyway; running against a server you started
 * yourself, with `--workers=1`, is both simpler and avoids a false
 * "slow" reading caused by that serialization rather than the app).
 */
module.exports = defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1, // see comment above — PHP's built-in server is single-threaded
  retries: 0,
  reporter: [['list']],
  timeout: 30_000,
  use: {
    baseURL: process.env.CUSTODIA_BASE_URL || 'http://127.0.0.1:8099',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // This environment pre-installs one specific Chromium build outside
        // node_modules (PLAYWRIGHT_BROWSERS_PATH) rather than letting each
        // @playwright/test version download its own matching build — point
        // at it directly instead of relying on auto-discovery, which can
        // pick a version string playwright's launcher doesn't recognize.
        // Harmless to leave in on a machine where the normal `npx playwright
        // install` download flow works fine instead.
        launchOptions: process.env.PLAYWRIGHT_BROWSERS_PATH
          ? { executablePath: `${process.env.PLAYWRIGHT_BROWSERS_PATH}/chromium` }
          : {},
      },
    },
  ],
});
