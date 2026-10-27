import { defineConfig, devices } from '@playwright/test';

// WCAG checks of a site built by build.py; SITE_DIR names it.
const PORT = 18434;

export default defineConfig({
  testDir: '.',
  timeout: 30_000,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  use: { baseURL: `http://127.0.0.1:${PORT}`, trace: 'retain-on-failure' },
  webServer: {
    command: `python3 -m http.server ${PORT} --bind 127.0.0.1 --directory "${process.env.SITE_DIR}"`,
    url: `http://127.0.0.1:${PORT}/`,
    reuseExistingServer: false,
    stderr: 'ignore',
  },
  projects: [{ name: 'chromium', use: devices['Desktop Chrome'] }],
});
