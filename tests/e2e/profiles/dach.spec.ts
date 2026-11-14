import { expect, test } from '@playwright/test';
import { loginToCms } from '../cms';
import { civiUrl, expectHealthy, requireProfile, userFramework, watchFailures } from './site';

test.describe('dach profile', () => {
  requireProfile('dach');

  test('CiviSEPA batches the seeded mandates into a collection group', async ({ page }) => {
    const failures = watchFailures(page);
    await loginToCms(page, userFramework());
    await page.goto(civiUrl('civicrm/sepa'));
    await page.getByTitle('update recurring').click();
    await expect(page.locator('a.button_view', { hasText: 'Contributions' }).first()).toBeVisible({ timeout: 30_000 });
    await expectHealthy(page);
    expect(failures).toEqual([]);
  });
});
