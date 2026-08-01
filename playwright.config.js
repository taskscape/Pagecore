const { defineConfig, devices } = require('@playwright/test');
const os = require('os');
const path = require('path');
const { randomUUID } = require('crypto');

const port = Number(process.env.PAGECORE_SAMPLE_PORT || 8765);
const baseURL = process.env.PAGECORE_BASE_URL || `http://127.0.0.1:${port}`;
process.env.PAGECORE_TEST_ROOT ||= path.join(os.tmpdir(), `pagecore-playwright-${randomUUID()}`);

module.exports = defineConfig({
  testDir: './tests',
  testMatch: 'sample-site.spec.js',
  timeout: 30 * 1000,
  expect: {
    timeout: 5000
  },
  fullyParallel: true,
  workers: process.env.CI ? 2 : 4,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    trace: 'on-first-retry'
  },
  webServer: {
    command: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/Start-SampleSite.ps1 -Port ${port}`,
    url: `${baseURL}/sample-site/`,
    reuseExistingServer: false,
    timeout: 30 * 1000
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] }
    }
  ]
});
