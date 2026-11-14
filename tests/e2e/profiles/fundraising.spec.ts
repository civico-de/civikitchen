import { expect, test } from '@playwright/test';
import { searchPageSuite } from './search-pages';
import { api4, civiUrl, expectHealthy, requireProfile, watchFailures } from './site';

searchPageSuite('fundraising', 'civicrm/demo/donors');

test.describe('fundraising profile, donation page', () => {
  requireProfile('fundraising');

  test('an anonymous visitor makes a test donation', async ({ page }) => {
    const failures = watchFailures(page);
    const [donationPage] = api4<{ id: number }>('ContributionPage.get', {
      select: ['id'],
      where: [['name', '=', 'spenden']],
    });
    expect(donationPage, 'the seeded donation page').toBeDefined();
    const email = `e2e-${Date.now()}@example.org`;

    // action=preview runs the page in test mode, against the Dummy test processor.
    await page.goto(civiUrl(`civicrm/contribute/transact?reset=1&id=${donationPage.id}&action=preview`, true));
    await page.locator('[name="email-5"]').fill(email);
    await page.locator('[name="credit_card_number"]').fill('4111111111111111');
    await page.locator('[name="cvv2"]').fill('123');
    await page.locator('[name="credit_card_exp_date[m]"]').selectOption({ index: 12 });
    const years = page.locator('[name="credit_card_exp_date[Y]"] option');
    await page.locator('[name="credit_card_exp_date[Y]"]').selectOption({ index: (await years.count()) - 1 });
    await page.locator('[name="billing_first_name"]').fill('Erika');
    await page.locator('[name="billing_last_name"]').fill('Beispiel');
    await page.locator('[name="billing_street_address-5"]').fill('Musterweg 1');
    await page.locator('[name="billing_city-5"]').fill('Musterstadt');
    await page.locator('[name="billing_postal_code-5"]').fill('12345');
    // Select2 hides both selects; the state list loads once the country is set.
    await page.locator('[name="billing_country_id-5"]').selectOption({ label: 'Germany' }, { force: true });
    const state = page.locator('[name="billing_state_province_id-5"]');
    await expect(state.locator('option')).not.toHaveCount(1, { timeout: 15_000 });
    await state.selectOption({ label: 'Berlin' }, { force: true });
    await page.locator('[name="_qf_Main_upload"]').click();
    await page.locator('[name="_qf_Confirm_next"]').first().click();
    await expect(page).toHaveURL(/_qf_ThankYou_display/, { timeout: 30_000 });
    await expectHealthy(page);

    const contributions = api4<{ total_amount: number }>('Contribution.get', {
      select: ['total_amount'],
      where: [
        ['contact_id.email_primary.email', '=', email],
        ['is_test', '=', true],
        ['contribution_status_id:name', '=', 'Completed'],
      ],
    });
    expect(contributions).toHaveLength(1);
    expect(Number(contributions[0].total_amount)).toBe(50);
    expect(failures).toEqual([]);
  });
});
