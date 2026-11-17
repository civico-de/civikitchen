import { test, expect } from '@playwright/test';

// The entrypoint's demo-user step (demo-user.php) produced a usable login.
// Credentials match examples/standalone/docker-compose.yml (admin/admin).
const DEMO_USER = process.env.DEMO_USER ?? 'admin';
const DEMO_PASS = process.env.DEMO_PASS ?? 'admin';
const TARGET = process.env.CIVIKITCHEN_E2E_TARGET ?? 'standalone';

test.describe('demo user login', () => {
  test.skip(TARGET !== 'standalone', 'Standalone demo-user login is image-specific');

  test('admin/admin logs in and lands inside CiviCRM', async ({ page }) => {
    await page.goto('/civicrm/login');

    // crmLogin renders fields with ARIA labels.
    await page.getByRole('textbox', { name: 'Username' }).fill(DEMO_USER);
    await page.getByRole('textbox', { name: 'Password' }).fill(DEMO_PASS);
    await page.getByRole('button', { name: /log in/i }).click();

    // The landing page depends on Civi's post-login URL setting.
    await expect(page).not.toHaveURL(/\/civicrm\/login/, { timeout: 15_000 });

    // The menubar renders only once authenticated; since 6.16 it does not
    // print the username, so the menubar itself is the marker.
    await expect(page.locator('#civicrm-menu')).toBeAttached({
      timeout: 15_000,
    });
  });
});
