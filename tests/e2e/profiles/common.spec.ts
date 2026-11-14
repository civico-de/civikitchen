import { expect, test } from '@playwright/test';
import { loginToCms } from '../cms';
import { civiUrl, expectHealthy, requireProfile, userFramework, watchFailures } from './site';

test.describe('common profile', () => {
  requireProfile('common');

  test('Mosaico offers its base templates for a new template', async ({ page }) => {
    const failures = watchFailures(page);
    await loginToCms(page, userFramework());
    await page.goto(civiUrl('civicrm/mosaico-template-list'));
    await page.getByRole('button', { name: 'New Template' }).click();
    await expect(page.getByRole('dialog')).toContainText(/versafix/i, { timeout: 20_000 });
    await expectHealthy(page);
    expect(failures).toEqual([]);
  });
});
