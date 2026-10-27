import { defineConfig, devices } from '@playwright/test';

// Browser smoke tests for civikitchen dev images. Boot one of the example
// compose stacks, wait for it to become healthy, then run the suite in Docker:
//
//   cd examples/standalone && docker compose up -d --wait && cd ../..
//   make e2e
//
// CI runs the same for standalone and each buildkit CMS flavor in
// .github/workflows/build-dev-images.yml and build-cms-images.yml.

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
