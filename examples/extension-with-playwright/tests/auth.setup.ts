import { test as setup, expect } from '@playwright/test';

// Logs in once as the demo user and persists the session to .auth/admin.json.
// Tests in the `chromium` project load this storage state automatically and
// start out already authenticated.
//
// Credentials default to the standalone image's demo user (admin / admin).
// Override via DEMO_USER / DEMO_PASS env vars if you changed
// CIVIKITCHEN_DEMO_USER / CIVIKITCHEN_DEMO_PASS in docker-compose.yml.

const authFile = '.auth/admin.json';
const DEMO_USER = process.env.DEMO_USER ?? 'admin';
const DEMO_PASS = process.env.DEMO_PASS ?? 'admin';

setup('authenticate as admin', async ({ page }) => {
  await page.goto('/civicrm/login');

  // crmLogin Angular module renders fields with ARIA labels.
  await page.getByRole('textbox', { name: 'Username' }).fill(DEMO_USER);
  await page.getByRole('textbox', { name: 'Password' }).fill(DEMO_PASS);
  await page.getByRole('button', { name: /log in/i }).click();

  await expect(page).not.toHaveURL(/\/civicrm\/login/, { timeout: 15_000 });
  // CiviCRM's menubar renders only for a logged-in user.
  await expect(page.getByRole('navigation').getByRole('link', { name: 'Home' })).toBeVisible({ timeout: 15_000 });

  await page.context().storageState({ path: authFile });
});
