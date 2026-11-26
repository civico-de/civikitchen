// One `ck steps` run: logs in, runs the steps module and reports what it did and
// what the page shows afterwards. The last stdout line is the report.
import { chromium, type Page } from '@playwright/test';
import { pathToFileURL } from 'node:url';

type Spec = {
  cms: string;
  phase: 'before' | 'after';
  site: string;
  routePrefix: string;
  login: { url: string; username: string; password: string; dismiss?: { role: 'button'; name: string } };
  steps: string;
  // The route to open before the steps, as `ck demo --page` leaves one open for them.
  page?: string;
  size: { width: number; height: number };
  timeoutMs: number;
};

type Report = {
  ok: boolean;
  error?: string;
  value?: unknown;
  url?: string;
  title?: string;
  text?: string;
  errors: string[];
};

// What a reader needs to see where the steps ended, not the whole page.
const TEXT_LIMIT = 6000;

const spec = JSON.parse(process.argv[2] ?? '') as Spec;

// A CiviCRM route ('civicrm/contact/search?reset=1#tab') as this CMS serves it.
const url = (route: string): string => {
  const [beforeHash, hash = ''] = route.replace(/^\/+/, '').split(/#(.*)/s, 2);
  const [path, query = ''] = beforeHash.split(/\?(.*)/s, 2);
  const prefix = spec.site + spec.routePrefix;
  const separator = prefix.includes('?') ? '&' : '?';
  return prefix + path + (query === '' ? '' : separator + query) + (hash === '' ? '' : '#' + hash);
};

const login = async (page: Page): Promise<void> => {
  await page.goto(spec.site + spec.login.url);
  await page.fill(spec.login.username, 'admin');
  await page.fill(spec.login.password, 'admin');
  await page.keyboard.press('Enter');
  // Every CMS leaves its login form once the session exists, also when it logs in over XHR.
  await page.locator(spec.login.password).waitFor({ state: 'detached' });
  await page.waitForLoadState('networkidle');
  const dismiss = spec.login.dismiss;
  if (dismiss !== undefined) {
    const button = page.getByRole(dismiss.role, { name: dismiss.name, exact: true });
    if ((await button.count()) > 0) {
      await button.first().click();
      await page.waitForLoadState('networkidle');
    }
  }
};

const message = (error: unknown): string => (error instanceof Error ? error.message : String(error));

const report: Report = { ok: false, errors: [] };
const browser = await chromium.launch();
let page: Page | undefined;

// Where the steps stopped is the evidence, whether they finished, threw or ran out of time.
// The first caller decides the outcome; a later one changes nothing and waits for the exit.
let finished = false;
let timer: ReturnType<typeof setTimeout> | undefined;
const finish = async (outcome: Partial<Report>): Promise<never> => {
  if (finished) {
    return new Promise<never>(() => {});
  }
  finished = true;
  clearTimeout(timer);
  Object.assign(report, outcome);
  if (page !== undefined) {
    report.url = page.url();
    report.title = await page.title().catch(() => '');
    report.text = (await page.locator('body').ariaSnapshot({ timeout: 5000 }).catch(() => '')).slice(0, TEXT_LIMIT);
  }
  console.log(JSON.stringify(report));
  await browser.close().catch(() => {});
  process.exit(0);
};

try {
  const context = await browser.newContext({ viewport: spec.size });
  page = await context.newPage();
  await login(page);
} catch (error) {
  // No session is the runner's failure, not the steps'.
  console.error(`could not log into ${spec.cms} at ${spec.site}: ${message(error)}`);
  await browser.close().catch(() => {});
  process.exit(1);
}

page.on('pageerror', (error) => report.errors.push(error.message));
page.on('console', (entry) => {
  if (entry.type() === 'error') {
    report.errors.push(entry.text());
  }
});
// Tells the caller that a run ended from outside got as far as the steps' own time.
console.log('ck steps: running the steps');
timer = setTimeout(() => {
  void finish({ error: `the steps did not finish within ${spec.timeoutMs / 1000} s` });
}, spec.timeoutMs);

let outcome: Partial<Report>;
try {
  const steps: unknown = (await import(pathToFileURL(spec.steps).href)).default;
  if (typeof steps !== 'function') {
    throw new Error('the steps module has no default export function');
  }
  if (spec.page !== undefined) {
    await page.goto(url(spec.page));
    await page.waitForLoadState('networkidle');
    if ((await page.locator('#crm-container').count()) === 0) {
      throw new Error(`${spec.page} shows no CiviCRM page on ${spec.cms} (${page.url()})`);
    }
  }
  const value: unknown = await steps({ page, url, cms: spec.cms, phase: spec.phase });
  // Through JSON, so a value the report cannot carry fails here and not while printing.
  outcome = { ok: true, value: value === undefined ? undefined : JSON.parse(JSON.stringify(value)) };
} catch (error) {
  outcome = { error: message(error) };
}
await finish(outcome);
