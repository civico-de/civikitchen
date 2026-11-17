import { test, expect } from '@playwright/test';

const TARGET = process.env.CIVIKITCHEN_E2E_TARGET ?? 'standalone';

// E2E smoke tests for the standalone dev image: the auto-installed site at
// CIVICRM_BASE_URL renders, with its assets and the crmLogin bundle.

test.describe('standalone CiviCRM site', () => {
  test.skip(TARGET !== 'standalone', 'Standalone-specific routes and login form');

  test('homepage redirects anonymous users to login', async ({ page }) => {
    // standaloneusers answers /civicrm/home with a 403; the image's Apache
    // RedirectMatch (conf-enabled/civicrm.conf) sends it to login.
    await page.goto('/');
    expect(page.url(), 'should land on /civicrm/login').toMatch(/\/civicrm\/login/);
  });

  test('login page renders without permission errors', async ({ page }) => {
    await page.goto('/civicrm/login');

    // The title confirms civi booted and the login route resolved.
    await expect(page).toHaveTitle(/Log In/i);

    // This banner (with a 200) means private/cache has the wrong owner.
    const html = await page.content();
    expect(html, 'cache dir should be writable').not.toContain(
      'could not write'
    );

    // crmLogin renders the fields with ARIA labels, not name= attributes;
    // this times out if the asset bundle never loads.
    await expect(page.getByRole('textbox', { name: 'Username' })).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.getByRole('textbox', { name: 'Password' })).toBeVisible({
      timeout: 15_000,
    });
  });

  test('static assets load (no 404s in console)', async ({ page }) => {
    const failures: string[] = [];
    page.on('response', (resp) => {
      if (resp.status() >= 400 && /\.(css|js)(\?|$)/.test(resp.url())) {
        failures.push(`${resp.status()} ${resp.url()}`);
      }
    });
    await page.goto('/civicrm/login');
    await page.waitForLoadState('networkidle');
    expect(failures, 'no CSS/JS asset 404s').toEqual([]);
  });
});
