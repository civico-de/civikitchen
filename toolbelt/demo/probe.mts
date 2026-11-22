// One `ck ab` Standalone browser probe: logs into CiviCRM Standalone,
// visits authenticated and anonymous routes, captures console/page/network
// errors, #crm-container presence, WCAG 2.1 AA axe violations, and full-page
// screenshots. Writes `${outDir}/${prefix}.browser.json` and prints compact
// JSON on the last stdout line.
import { chromium, type BrowserContext, type Page } from '@playwright/test';
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

type Spec = {
  site: string;
  pages: string[];
  anonymousPages?: string[];
  outDir: string;
  prefix: 'A' | 'B';
  size?: { width: number; height: number };
  runA11y?: boolean;
};

type NetworkFailure = {
  status: number;
  method: string;
  urlPath: string;
};

type A11yViolation = {
  id: string;
  impact: string;
  description: string;
  nodes: number;
  targets: string[];
};

type RouteProbe = {
  route: string;
  mode: 'auth' | 'anon';
  finalPathname: string;
  status: number | null;
  hasCrmContainer: boolean;
  redirectedToLogin: boolean;
  consoleErrors: string[];
  pageErrors: string[];
  networkFailures: NetworkFailure[];
  a11yViolations: A11yViolation[];
  a11ySkippedReason?: string;
  screenshot: string;
};

type AxeNode = {
  target: unknown[];
};

type AxeViolation = {
  id: string;
  impact?: string | null;
  description: string;
  nodes: AxeNode[];
};

type AxeConstructor = new (options: { page: Page }) => {
  withTags(tags: string[]): { analyze(): Promise<{ violations: AxeViolation[] }> };
};

const spec = JSON.parse(process.argv[2] ?? '') as Spec;
const baseSite = spec.site.replace(/\/+$/, '');
const size = spec.size ?? { width: 1280, height: 800 };
const runA11y = spec.runA11y ?? true;
const authPages = spec.pages ?? [];
const anonPages = spec.anonymousPages ?? [];

const url = (route: string): string => {
  const clean = route.replace(/^\/+/, '');
  const [beforeHash, hash = ''] = clean.split(/#(.*)/s, 2);
  const [path, query = ''] = beforeHash.split(/\?(.*)/s, 2);
  return `${baseSite}/${path}${query === '' ? '' : `?${query}`}${hash === '' ? '' : `#${hash}`}`;
};

const stripOrigin = (rawUrl: string): string => {
  try {
    const parsed = new URL(rawUrl);
    return `${parsed.pathname}${parsed.search}`;
  } catch {
    return rawUrl;
  }
};

const routeSlug = (route: string, mode: 'auth' | 'anon'): string => {
  const cleaned =
    route
      .replace(/^\/+/, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '') || 'root';
  const slug = mode === 'anon' ? `anon-${cleaned}` : cleaned;
  return slug.slice(0, 120);
};

const login = async (page: Page): Promise<void> => {
  await page.goto(`${baseSite}/civicrm/login`);
  await page.fill('#usernameInput', 'admin');
  await page.fill('#passwordInput', 'admin');
  await page.keyboard.press('Enter');
  await page.locator('#passwordInput').waitFor({ state: 'detached' });
  await page.waitForLoadState('networkidle');
};

// Warm up dashboard caches and absorb post-flush admin alerts before probing routes.
const warmUp = async (page: Page): Promise<void> => {
  await page.goto(url('civicrm/dashboard?reset=1'));
  await page.waitForLoadState('networkidle');
};

let AxeBuilderCtor: AxeConstructor | null = null;
let globalA11ySkippedReason: string | undefined;
if (runA11y) {
  try {
    const mod = (await import('@axe-core/playwright')) as Record<string, unknown>;
    const defaultExport = mod.default as Record<string, unknown> | AxeConstructor | undefined;
    const candidate =
      typeof mod.AxeBuilder === 'function'
        ? mod.AxeBuilder
        : typeof defaultExport === 'function'
          ? defaultExport
          : typeof defaultExport === 'object' && defaultExport !== null && typeof defaultExport.AxeBuilder === 'function'
            ? defaultExport.AxeBuilder
            : undefined;
    if (typeof candidate === 'function') {
      AxeBuilderCtor = candidate as AxeConstructor;
    } else {
      globalA11ySkippedReason = '@axe-core/playwright did not export AxeBuilder';
    }
  } catch (error) {
    globalA11ySkippedReason = error instanceof Error ? error.message : String(error);
  }
}

const screenshotsDir = join(spec.outDir, 'screenshots');
await mkdir(screenshotsDir, { recursive: true });

const probeRoute = async (
  context: BrowserContext,
  route: string,
  mode: 'auth' | 'anon',
): Promise<RouteProbe> => {
  const page = await context.newPage();
  const consoleErrors: string[] = [];
  const pageErrors: string[] = [];
  const networkFailures: NetworkFailure[] = [];

  page.on('console', (message) => {
    if (message.type() === 'error') {
      consoleErrors.push(message.text());
    }
  });
  page.on('pageerror', (error) => {
    pageErrors.push(error.message);
  });
  page.on('response', (response) => {
    if (response.status() >= 400) {
      networkFailures.push({
        status: response.status(),
        method: response.request().method(),
        urlPath: stripOrigin(response.url()),
      });
    }
  });

  const targetUrl = url(route);
  let status: number | null = null;
  try {
    const response = await page.goto(targetUrl, { waitUntil: 'domcontentloaded' });
    status = response !== null ? response.status() : null;
    await page.waitForLoadState('networkidle', { timeout: 15_000 }).catch(() => {});
  } catch (error) {
    pageErrors.push(error instanceof Error ? error.message : String(error));
  }

  let finalPathname = '';
  try {
    finalPathname = new URL(page.url()).pathname;
  } catch {
    finalPathname = page.url();
  }
  let targetPathname = '';
  try {
    targetPathname = new URL(targetUrl).pathname;
  } catch {
    targetPathname = targetUrl;
  }
  const redirectedToLogin =
    (finalPathname === '/civicrm/login' || finalPathname.startsWith('/civicrm/login/')) &&
    targetPathname !== '/civicrm/login' &&
    !targetPathname.startsWith('/civicrm/login/');
  const hasCrmContainer = (await page.locator('#crm-container').count()) > 0;

  const screenshot = `${spec.prefix}-${routeSlug(route, mode)}.png`;
  await page.screenshot({ path: join(screenshotsDir, screenshot), fullPage: true });

  let a11yViolations: A11yViolation[] = [];
  let a11ySkippedReason = globalA11ySkippedReason;
  if (runA11y && AxeBuilderCtor !== null) {
    try {
      const result = await new AxeBuilderCtor({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
        .analyze();
      a11yViolations = result.violations.map((v) => ({
        id: v.id,
        impact: v.impact ?? 'unknown',
        description: v.description,
        nodes: v.nodes.length,
        targets: v.nodes.slice(0, 3).map((n) => n.target.map(String).join(' ')),
      }));
    } catch (error) {
      a11ySkippedReason = error instanceof Error ? error.message : String(error);
    }
  }

  await page.close();

  const entry: RouteProbe = {
    route,
    mode,
    finalPathname,
    status,
    hasCrmContainer,
    redirectedToLogin,
    consoleErrors,
    pageErrors,
    networkFailures,
    a11yViolations,
    screenshot,
  };
  if (a11ySkippedReason !== undefined) {
    entry.a11ySkippedReason = a11ySkippedReason;
  }
  return entry;
};

const browser = await chromium.launch();
const routes: RouteProbe[] = [];
try {
  const authContext = await browser.newContext({ viewport: size });
  try {
    const warmPage = await authContext.newPage();
    await login(warmPage);
    await warmUp(warmPage);
    await warmPage.close();

    for (const route of authPages) {
      routes.push(await probeRoute(authContext, route, 'auth'));
    }
  } finally {
    await authContext.close();
  }

  for (const route of anonPages) {
    const anonContext = await browser.newContext({ viewport: size });
    try {
      routes.push(await probeRoute(anonContext, route, 'anon'));
    } finally {
      await anonContext.close();
    }
  }
} finally {
  await browser.close();
}

const report: Record<string, unknown> = {
  prefix: spec.prefix,
  site: baseSite,
  runA11y,
  routes,
};
if (globalA11ySkippedReason !== undefined) {
  report.a11ySkippedReason = globalA11ySkippedReason;
}

await writeFile(join(spec.outDir, `${spec.prefix}.browser.json`), `${JSON.stringify(report, null, 2)}\n`, 'utf8');
console.log(JSON.stringify(report));
