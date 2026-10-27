import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// One page per layout: landing, long reference with tables and code, plain page, ADR.
const PAGES = ['/', '/images/', '/extension-standards/', '/core-development/', '/adr/0001-one-versioned-contract-pinned-at-v1/'];
const SCHEMES = { light: 'default', dark: 'slate' } as const;

for (const [colorScheme, theme] of Object.entries(SCHEMES) as [keyof typeof SCHEMES, string][]) {
  for (const path of PAGES) {
    test(`${path} passes WCAG 2.1 AA in ${colorScheme} mode`, async ({ page }) => {
      await page.emulateMedia({ colorScheme });
      await page.goto(path);
      // Without this the run would pass on whichever scheme happened to load.
      await expect(page.locator('body')).toHaveAttribute('data-md-color-scheme', theme);
      // A web font arriving late leaves code blocks unfocusable; the check passed only while it was slow.
      await expect(page.locator('link[href*="fonts.googleapis.com"]')).toHaveCount(0);
      const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
      expect(violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
    });
  }
}
