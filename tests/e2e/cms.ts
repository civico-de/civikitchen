import { expect, type Page } from '@playwright/test';

/** CiviCRM's user framework names (CIVICRM_UF) mapped to the CMS login form they log in through. */
export type UserFramework = 'Standalone' | 'Drupal8' | 'Backdrop' | 'WordPress' | 'Joomla';

export const ADMIN_USER = process.env.CIVIKITCHEN_E2E_USER ?? 'admin';
export const ADMIN_PASS = process.env.CIVIKITCHEN_E2E_PASS ?? 'admin';

/** Log in through the CMS's own login form. */
export async function loginToCms(page: Page, uf: UserFramework, user = ADMIN_USER, pass = ADMIN_PASS) {
  switch (uf) {
    case 'Standalone':
      await page.goto('/civicrm/login', { waitUntil: 'domcontentloaded' });
      await page.getByRole('textbox', { name: 'Username' }).fill(user);
      await page.getByRole('textbox', { name: 'Password' }).fill(pass);
      await page.getByRole('button', { name: /log in/i }).click();
      await expect(page.locator('#civicrm-menu')).toBeAttached({ timeout: 15_000 });
      return;

    case 'Drupal8':
    case 'Backdrop':
      await page.goto('/user/login', { waitUntil: 'domcontentloaded' });
      await page.locator('input[name="name"]').fill(user);
      await page.locator('input[name="pass"]').fill(pass);
      await submitLogin(page);
      await expect(page).not.toHaveURL(/\/user\/login/, { timeout: 15_000 });
      return;

    case 'WordPress':
      await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
      await page.locator('input[name="log"], input#user_login').first().fill(user);
      await page.locator('input[name="pwd"], input#user_pass').first().fill(pass);
      await submitLogin(page);
      await expect(page).toHaveURL(/wp-admin|admin\.php/, { timeout: 15_000 });
      return;

    case 'Joomla':
      await page.goto('/administrator/index.php', { waitUntil: 'domcontentloaded' });
      await page.locator('input[name="username"], input#mod-login-username').first().fill(user);
      await page.locator('input[name="passwd"], input#mod-login-password').first().fill(pass);
      await submitLogin(page);
      return;
  }
}

async function submitLogin(page: Page) {
  await Promise.all([
    page.waitForLoadState('domcontentloaded').catch(() => {}),
    page.locator('button[type="submit"]:visible, input[type="submit"]:visible').first().click(),
  ]);
}
