const { defineConfig, devices } = require('@playwright/test');

const port = Number(process.env.PAGECORE_SAMPLE_PORT || 8765);
const baseURL = process.env.PAGECORE_BASE_URL || `http://127.0.0.1:${port}`;

module.exports = defineConfig({
  testDir: './tests',
  timeout: 30 * 1000,
  expect: {
    timeout: 5000
  },
  fullyParallel: false,
  workers: 1,
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
