import { defineConfig, devices } from '@playwright/test';

// Browser smoke tests for the dev images, against a running example stack:
//
//   cd examples/standalone && docker compose up -d --wait && cd ../..
//   make e2e

const BASE_URL = process.env.CIVICRM_BASE_URL ?? 'http://localhost:8080';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: BASE_URL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'chromium', use: devices['Desktop Chrome'] },
  ],
});
