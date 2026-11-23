import { expect, test } from '@playwright/test';
import { searchPageSuite } from './search-pages';
import { api4, civiUrl, expectHealthy, requireProfile, watchFailures } from './site';

searchPageSuite('events', 'civicrm/demo/events');

test.describe('events profile, public registration', () => {
  requireProfile('events');

  test('an anonymous visitor registers for an upcoming event', async ({ page }) => {
    const failures = watchFailures(page);
    const [event] = api4<{ id: number }>('Event.get', {
      select: ['id'],
      // The profile's conference, not a sample event the image may ship.
      where: [['title', '=', 'Fachkonferenz Engagement'], ['start_date', '>', 'now'],
        ['is_public', '=', true], ['is_online_registration', '=', true]],
    });
    expect(event, "the profile's upcoming conference, open for registration").toBeDefined();
    const email = `e2e-${Date.now()}@example.org`;

    await page.goto(civiUrl(`civicrm/event/register?reset=1&id=${event.id}`, true));
    await page.locator('[name="first_name"]').fill('Erika');
    await page.locator('[name="last_name"]').fill('Beispiel');
    await page.locator('[name^="email-"]').first().fill(email);
    await page.locator('[name="_qf_Register_upload"]').click();
    await page.locator('[name="_qf_Confirm_next"]').first().click();
    await expect(page).toHaveURL(/_qf_ThankYou_display/, { timeout: 20_000 });
    await expectHealthy(page);

    const participants = api4('Participant.get', {
      select: ['id'],
      where: [['event_id', '=', event.id], ['contact_id.email_primary.email', '=', email]],
    });
    expect(participants).toHaveLength(1);
    expect(failures).toEqual([]);
  });
});
