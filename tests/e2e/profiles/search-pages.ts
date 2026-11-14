import { expect, test } from '@playwright/test';
import { loginToCms } from '../cms';
import { civiUrl, expectHealthy, requireProfile, userFramework, watchFailures } from './site';

/** A data profile's SearchKit page lists its seeded records to the admin. */
export function searchPageSuite(profile: string, route: string) {
  test.describe(`${profile} profile`, () => {
    requireProfile(profile);

    test(`${route} lists the seeded records`, async ({ page }) => {
      const failures = watchFailures(page);
      await loginToCms(page, userFramework());
      await page.goto(civiUrl(route));
      const rows = page.locator('crm-search-display-table table tbody tr');
      await expect(rows.first()).toBeVisible({ timeout: 20_000 });
      expect(await rows.count()).toBeGreaterThan(1);
      await expectHealthy(page);
      expect(failures).toEqual([]);
    });
  });
}
