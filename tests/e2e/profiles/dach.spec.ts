import { expect, test } from '@playwright/test';
import { loginToCms } from '../cms';
import { api3, api4, civiUrl, expectHealthy, requireProfile, userFramework, watchFailures } from './site';

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

  test('a new contract signs a membership', () => {
    const [contact] = api4<{ id: number }>('Contact.create', {
      values: { contact_type: 'Individual', first_name: 'Erika', last_name: `e2e-contract-${Date.now()}` },
    });
    const [membershipType] = api4<{ id: number }>('MembershipType.get', { select: ['id'], limit: 1 });
    const today = new Date().toISOString().slice(0, 10);
    api3('Contract.create', {
      contact_id: contact.id, membership_type_id: membershipType.id, join_date: today, start_date: today,
    });

    const memberships = api4<{ id: number }>('Membership.get', { select: ['id'], where: [['contact_id', '=', contact.id]] });
    expect(memberships).toHaveLength(1);
    const signed = api4('Activity.get', {
      select: ['id'],
      where: [['activity_type_id:name', '=', 'Contract_Signed'], ['source_record_id', '=', memberships[0].id]],
    });
    expect(signed).toHaveLength(1);
  });

  test('a Twingle donation becomes a contribution', () => {
    const trx = `e2e-${Date.now()}`;
    const result = api3<{ contribution: Record<string, unknown> }>('TwingleDonation.submit', {
      project_id: 'e2e', trx_id: trx, confirmed_at: '20260105120000', purpose: 'Widget', amount: 1500,
      currency: 'EUR', newsletter: 0, donation_receipt: 0, payment_method: 'banktransfer',
      donation_rhythm: 'one_time', user_firstname: 'Erika', user_lastname: trx, user_email: `${trx}@example.org`,
    });
    expect(result.contribution).toBeDefined();

    const contributions = api4<{ total_amount: number }>('Contribution.get', {
      select: ['total_amount'], where: [['trxn_id', 'CONTAINS', trx]],
    });
    expect(contributions.map((c) => Number(c.total_amount))).toEqual([15]);
  });
});
