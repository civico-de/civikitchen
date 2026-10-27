// One `ck demo` recording: logs in, shows each page, runs the optional steps
// module and saves the video. The last stdout line is {"errors": [...]}.
import { chromium, type Page } from '@playwright/test';
import { pathToFileURL } from 'node:url';

type Script = {
  cms: string;
  phase: 'before' | 'after';
  site: string;
  routePrefix: string;
  login: { url: string; username: string; password: string; dismiss?: { role: 'button'; name: string } };
  pages: string[];
  steps?: string;
  size: { width: number; height: number };
  dwellMs: number;
  out: string;
};

const script = JSON.parse(process.argv[2] ?? '') as Script;

// A CiviCRM route ('civicrm/contact/search?reset=1#tab') as this CMS serves it.
const url = (route: string): string => {
  const [beforeHash, hash = ''] = route.split(/#(.*)/s, 2);
  const [path, query = ''] = beforeHash.split(/\?(.*)/s, 2);
  const prefix = script.site + script.routePrefix;
  const separator = prefix.includes('?') ? '&' : '?';
  return prefix + path + (query === '' ? '' : separator + query) + (hash === '' ? '' : '#' + hash);
};

const login = async (page: Page): Promise<void> => {
  await page.goto(script.site + script.login.url);
  await page.fill(script.login.username, 'admin');
  await page.fill(script.login.password, 'admin');
  await page.keyboard.press('Enter');
  // Every CMS leaves its login form once the session exists, also when it logs in over XHR.
  await page.locator(script.login.password).waitFor({ state: 'detached' });
  await page.waitForLoadState('networkidle');
  const dismiss = script.login.dismiss;
  if (dismiss !== undefined) {
    const button = page.getByRole(dismiss.role, { name: dismiss.name, exact: true });
    if ((await button.count()) > 0) {
      await button.first().click();
      await page.waitForLoadState('networkidle');
    }
  }
};

// Unrecorded first visits rebuild the caches `cv flush` emptied and absorb the
// status alert CiviCRM pops up for admins on the first page after a flush.
const warmUp = async (page: Page): Promise<void> => {
  for (const route of ['civicrm/dashboard?reset=1', ...script.pages]) {
    await page.goto(url(route));
    await page.waitForLoadState('networkidle');
  }
};

const browser = await chromium.launch();
try {
  const auth = await browser.newContext({ viewport: script.size });
  const authPage = await auth.newPage();
  await login(authPage);
  await warmUp(authPage);
  const storageState = await auth.storageState();
  await auth.close();

  const context = await browser.newContext({
    viewport: script.size,
    storageState,
    recordVideo: { dir: '/tmp/ck-demo-video', size: script.size },
  });
  const page = await context.newPage();
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error') {
      errors.push(message.text());
    }
  });
  for (const route of script.pages) {
    await page.goto(url(route));
    await page.waitForLoadState('networkidle');
    // A login form or a CMS error page instead of CiviCRM would make a misleading video.
    if ((await page.locator('#crm-container').count()) === 0) {
      throw new Error(`${route} shows no CiviCRM page on ${script.cms} (${page.url()})`);
    }
    await page.waitForTimeout(script.dwellMs);
  }
  if (script.steps !== undefined) {
    const steps: unknown = (await import(pathToFileURL(script.steps).href)).default;
    if (typeof steps !== 'function') {
      throw new Error(`${script.steps} has no default export function`);
    }
    await steps({ page, url, cms: script.cms, phase: script.phase });
  }
  const video = page.video();
  await context.close();
  if (video === null) {
    throw new Error('the context recorded no video');
  }
  await video.saveAs(script.out);
  console.log(JSON.stringify({ errors }));
} finally {
  await browser.close();
}
