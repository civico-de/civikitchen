import { test, expect } from '@playwright/test';
import { loginToCms, type UserFramework } from './cms';

const TARGET = process.env.CIVIKITCHEN_E2E_TARGET ?? 'standalone';
const E2E_PATH = process.env.CIVICRM_E2E_PATH ?? '/';
const CMS_TARGETS: Record<string, { uf: UserFramework; civiPath: string }> = {
  drupal10: { uf: 'Drupal8', civiPath: '/civicrm' },
  drupal11: { uf: 'Drupal8', civiPath: '/civicrm' },
  wordpress: { uf: 'WordPress', civiPath: '/wp-admin/admin.php?page=CiviCRM' },
  joomla: { uf: 'Joomla', civiPath: '/administrator/index.php?option=com_civicrm' },
  backdrop: { uf: 'Backdrop', civiPath: '/civicrm' },
};

function looksLikeAsset(url: string): boolean {
  return /\.(css|js|mjs|woff2?|ttf|eot)(\?|$)/i.test(url);
}

test.describe(`${TARGET} browser smoke`, () => {
  test('configured CiviCRM/CMS page loads without server or asset errors', async ({ page }) => {
    const failures: string[] = [];

    page.on('response', (resp) => {
      const status = resp.status();
      const url = resp.url();
      if (status >= 500 || (status >= 400 && looksLikeAsset(url))) {
        failures.push(`${status} ${url}`);
      }
    });

    const response = await page.goto(E2E_PATH, { waitUntil: 'domcontentloaded' });
    expect(response, `expected ${E2E_PATH} to return a response`).not.toBeNull();
    expect(response!.status(), `HTTP status for ${E2E_PATH}`).toBeLessThan(500);

    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});

    const html = await page.content();
    expect(html).not.toMatch(
      /Fatal error|Parse error|PDOException|DB Error|Permission denied|could not write/i
    );
    await expect(page.locator('body')).toContainText(/\S/);

    expect(failures, 'no server errors and no broken CSS/JS/font assets').toEqual([]);
  });

  test('admin can reach the CiviCRM UI', async ({ page }) => {
    const cms = CMS_TARGETS[TARGET];
    test.skip(!cms, 'CMS login flow is only defined for buildkit flavors');

    const failures: string[] = [];
    page.on('response', (resp) => {
      const status = resp.status();
      const url = resp.url();
      if (status >= 500 || (status >= 400 && looksLikeAsset(url))) {
        failures.push(`${status} ${url}`);
      }
    });

    await loginToCms(page, cms.uf);
    await assertHealthyPage(page, cms.civiPath);
    await expect(page.locator('body')).toContainText(/CiviCRM/i, { timeout: 15_000 });

    expect(failures, 'no server errors and no broken CSS/JS/font assets after CMS login').toEqual([]);
  });
});

async function assertHealthyPage(page: import('@playwright/test').Page, path: string) {
  const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
  expect(response, `expected ${path} to return a response`).not.toBeNull();
  expect(response!.status(), `HTTP status for ${path}`).toBeLessThan(500);
  await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});

  const html = await page.content();
  expect(html).not.toMatch(
    /Fatal error|Parse error|PDOException|DB Error|Permission denied|could not write/i
  );
}
