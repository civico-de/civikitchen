import { test, expect } from '@playwright/test';

const TARGET = process.env.CIVIKITCHEN_E2E_TARGET ?? 'standalone';
const E2E_PATH = process.env.CIVICRM_E2E_PATH ?? '/';
const ADMIN_USER = process.env.CIVIKITCHEN_E2E_USER ?? 'admin';
const ADMIN_PASS = process.env.CIVIKITCHEN_E2E_PASS ?? 'admin';

const CMS_TARGETS: Record<string, { loginPath: string; civiPath: string }> = {
  drupal10: { loginPath: '/user/login', civiPath: '/civicrm' },
  drupal11: { loginPath: '/user/login', civiPath: '/civicrm' },
  wordpress: { loginPath: '/wp-login.php', civiPath: '/wp-admin/admin.php?page=CiviCRM' },
  joomla: { loginPath: '/administrator/index.php', civiPath: '/administrator/index.php?option=com_civicrm' },
  backdrop: { loginPath: '/user/login', civiPath: '/civicrm' },
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

    await loginToCms(page, TARGET, cms.loginPath);
    await assertHealthyPage(page, cms.civiPath);
    await expect(page.locator('body')).toContainText(/CiviCRM/i, { timeout: 15_000 });

    expect(failures, 'no server errors and no broken CSS/JS/font assets after CMS login').toEqual([]);
  });
});

async function loginToCms(page: import('@playwright/test').Page, target: string, loginPath: string) {
  await page.goto(loginPath, { waitUntil: 'domcontentloaded' });

  if (target === 'drupal10' || target === 'drupal11' || target === 'backdrop') {
    await page.locator('input[name="name"]').fill(ADMIN_USER);
    await page.locator('input[name="pass"]').fill(ADMIN_PASS);
    await submitLogin(page);
    await expect(page).not.toHaveURL(/\/user\/login/, { timeout: 15_000 });
    return;
  }

  if (target === 'wordpress') {
    await page.locator('input[name="log"], input#user_login').first().fill(ADMIN_USER);
    await page.locator('input[name="pwd"], input#user_pass').first().fill(ADMIN_PASS);
    await submitLogin(page);
    await expect(page).toHaveURL(/wp-admin|admin\.php/, { timeout: 15_000 });
    return;
  }

  if (target === 'joomla') {
    await page.locator('input[name="username"], input#mod-login-username').first().fill(ADMIN_USER);
    await page.locator('input[name="passwd"], input#mod-login-password').first().fill(ADMIN_PASS);
    await submitLogin(page);
    return;
  }

  throw new Error(`No CMS login flow configured for ${target}`);
}

async function submitLogin(page: import('@playwright/test').Page) {
  await Promise.all([
    page.waitForLoadState('domcontentloaded').catch(() => {}),
    page.locator('button[type="submit"]:visible, input[type="submit"]:visible').first().click(),
  ]);
}

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
