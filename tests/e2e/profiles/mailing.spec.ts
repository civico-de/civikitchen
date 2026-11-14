import { expect, test } from '@playwright/test';
import { searchPageSuite } from './search-pages';
import { api3, api4, requireProfile } from './site';

searchPageSuite('mailing', 'civicrm/demo/mailing-lists');

test.describe('mailing profile', () => {
  requireProfile('mailing');

  test('a mailing preview resolves its tokens', () => {
    const [mailing] = api4<{ id: number }>('Mailing.create', {
      values: {
        name: `e2e-${Date.now()}`,
        subject: 'Hello {contact.contact_type}',
        body_html: '<p>{contact.contact_type} {action.optOutUrl} {domain.address}</p>',
        body_text: '{action.optOutUrl} {domain.address}',
      },
    });
    const [contact] = api4<{ id: number }>('Contact.get', {
      select: ['id'], where: [['contact_type', '=', 'Individual']], limit: 1,
    });
    const preview = api3<{ subject: string; body_html: string }>('Mailing.preview', {
      id: mailing.id, contact_id: contact.id,
    });
    api4('Mailing.delete', { where: [['id', '=', mailing.id]] });

    expect(preview.subject).toBe('Hello Individual');
    expect(preview.body_html).toMatch(/https?:\/\/[^"<\s]*optout/i);
    expect(preview.body_html).not.toMatch(/\{(contact|action|domain)\./);
  });
});
