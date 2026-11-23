import { expect, test } from '@playwright/test';
import { ADMIN_USER, loginToCms } from '../cms';
import { api4, cv, requireProfile, upstreamBug, userFramework } from './site';

// What every extension of the applied stack must survive, whichever profile
// brought it: its menu pages render, and core writes pass its hooks.

// PHP's own error output and CiviCRM's error page; help texts mention "fatal error" too.
const FATAL = /<b>(?:Fatal error|Parse error|Warning|Deprecated)<\/b>:|Uncaught \w|Sorry, due to an error|DB Error:/;

/** The site-relative URL of every backend menu entry core shows the admin, one cv call for all. */
function menuUrls(): { route: string; url: string }[] {
  const php = `
    $rows = \\Civi\\Api4\\Navigation::get(FALSE)->addSelect('url', 'permission', 'permission_operator')
      ->addWhere('is_active', '=', TRUE)->addWhere('domain_id', '=', 'current_domain')
      ->addWhere('url', 'LIKE', 'civicrm/%')->execute();
    $urls = [];
    foreach ($rows as $row) {
      // The menu's own filter: a disabled component's entries stay active but hidden.
      $item = ['permission' => implode(',', $row['permission'] ?? []), 'operator' => $row['permission_operator']];
      if (!\\CRM_Core_BAO_Navigation::checkPermission($item)) {
        continue;
      }
      $route = html_entity_decode($row['url']);
      [$path, $query] = array_pad(explode('?', $route, 2), 2, '');
      $urls[$route] = ['route' => $route, 'url' => \\CRM_Utils_System::url($path, $query, TRUE, NULL, FALSE)];
    }
    echo json_encode(array_values($urls));`;
  const rows = JSON.parse(cv(['ev', `--user=${ADMIN_USER}`, php])) as { route: string; url: string }[];
  return rows
    .filter(({ route }) => !/logout|civicrm\/a\/?#/.test(route))
    .map(({ route, url }) => {
      const parsed = new URL(url);
      return { route, url: parsed.pathname + parsed.search };
    });
}

test.describe('extension stack', () => {
  requireProfile('common');

  test('every backend menu page renders', async ({ page }) => {
    test.setTimeout(600_000);
    await loginToCms(page, userFramework());
    const pages = menuUrls();
    expect(pages.length, 'backend menu entries').toBeGreaterThan(50);

    const broken: string[] = [];
    for (const { route, url } of pages) {
      const response = await page.request.get(url, { timeout: 60_000 });
      const body = await response.text();
      const fatal = body.match(FATAL)?.[0];
      if (response.status() >= 500 || fatal) {
        broken.push(`${response.status()} ${route}${fatal ? ` (${fatal})` : ''}`);
      }
    }
    expect(broken).toEqual([]);
  });

  test('core writes pass the hooks of every enabled extension', () => {
    const tag = `e2e-${Date.now()}`;
    const create = <T extends { id: number }>(entity: string, values: object): T =>
      api4<T>(`${entity}.create`, { values })[0];

    const contact = create('Contact', { contact_type: 'Individual', first_name: 'Erika', last_name: tag });
    const [event] = api4<{ id: number }>('Event.get', { select: ['id'], limit: 1 });
    const [membershipType] = api4<{ id: number }>('MembershipType.get', { select: ['id'], limit: 1 });
    expect(event, 'a seeded event').toBeDefined();
    expect(membershipType, 'a seeded membership type').toBeDefined();

    create('Email', { contact_id: contact.id, email: `${tag}@example.org` });
    create('Address', { contact_id: contact.id, street_address: 'Musterweg 1', city: 'Musterstadt' });
    const contribution = create<{ id: number }>('Contribution', {
      contact_id: contact.id, financial_type_id: 1, total_amount: 10, contribution_status_id: 1,
    });
    create('ContributionRecur', { contact_id: contact.id, amount: 10, frequency_interval: 1 });
    create('Participant', { contact_id: contact.id, event_id: event.id, status_id: 1, role_id: 1, register_date: 'now' });
    create('Membership', { contact_id: contact.id, membership_type_id: membershipType.id });
    create('Activity', { source_contact_id: contact.id, activity_type_id: 1, subject: tag });
    const group = create<{ id: number }>('Group', { title: tag });
    create('GroupContact', { contact_id: contact.id, group_id: group.id });

    api4('Contribution.update', { where: [['id', '=', contribution.id]], values: { total_amount: 12 } });
    api4('Contact.update', { where: [['id', '=', contact.id]], values: { first_name: 'Erik' } });
    // Core keeps a contact that still has financial items.
    api4('Contribution.delete', { where: [['contact_id', '=', contact.id]] });
    api4('Contact.delete', { where: [['id', '=', contact.id]], useTrash: false });
    api4('Group.delete', { where: [['id', '=', group.id]] });

    expect(api4('Contact.get', { select: ['id'], where: [['id', '=', contact.id]] })).toHaveLength(0);
  });

  // The shape core's own forms send for a new record: hooks are promised an int or NULL id.
  test('a save with an explicit null id passes the hooks', () => {
    upstreamBug({ url: 'https://github.com/civicrm/civicrm-core/pull/38111', fixedIn: '6.21', profile: 'dach' });
    const tag = `e2e-null-${Date.now()}`;
    const [contact] = api4<{ id: number }>('Contact.save', {
      records: [{ id: null, contact_type: 'Individual', first_name: 'Erika', last_name: tag }],
    });
    api4('Email.save', { records: [{ id: null, contact_id: contact.id, email: `${tag}@example.org` }] });
    api4('Contact.delete', { where: [['id', '=', contact.id]], useTrash: false });
  });
});
