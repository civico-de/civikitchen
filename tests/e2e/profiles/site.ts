import { execFileSync } from 'node:child_process';
import { expect, test, type Page } from '@playwright/test';
import type { UserFramework } from '../cms';

// The suites here check what a profile promises on a booted site: its pages,
// public forms and extension workflows. CIVIKITCHEN_E2E_CONTAINER names the
// site's container; the suite reads the applied profiles and every URL from it.

const CONTAINER = process.env.CIVIKITCHEN_E2E_CONTAINER ?? '';
const BUNDLED = '/opt/civikitchen/docker/profiles';
const BUILDKIT_WEB = '/home/buildkit/buildkit/build/site/web';

function docker(args: string[]): string {
  return execFileSync('docker', args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

function containerEnv(name: string): string {
  return docker(['exec', CONTAINER, 'sh', '-c', `printf %s "\${${name}:-}"`]);
}

let buildkit: boolean | undefined;

/** cv as the site's web user: buildkit flavors and the standalone dev image differ. */
export function cv(args: string[]): string {
  buildkit ??= docker(['exec', CONTAINER, 'sh', '-c', 'id -u buildkit >/dev/null 2>&1 && echo yes || echo no']) === 'yes';
  return buildkit
    ? docker(['exec', '-u', 'buildkit', '-w', BUILDKIT_WEB, CONTAINER, '/home/buildkit/buildkit/bin/cv', ...args])
    : docker(['exec', '-u', 'www-data', CONTAINER, 'cv', ...args]);
}

/** APIv4 through cv; params as in the API explorer's JSON. */
export function api4<T = Record<string, unknown>>(call: string, params: object = {}): T[] {
  return JSON.parse(cv(['api4', call, JSON.stringify(params)])) as T[];
}

/** APIv3 through cv, for the extensions that offer nothing else; scalar params only. */
export function api3<T = Record<string, unknown>>(call: string, params: Record<string, string | number> = {}): T {
  const args = Object.entries(params).map(([key, value]) => `${key}=${value}`);
  return (JSON.parse(cv(['api3', call, ...args])) as { values: T }).values;
}

let applied: Set<string> | undefined;

/** The profiles applied to the site, includes resolved the way the driver does. */
function appliedProfiles(): Set<string> {
  if (applied) return applied;
  applied = new Set();
  if (!CONTAINER) return applied;
  const selected = containerEnv('CIVIKITCHEN_PROFILE').split(',').map((p) => p.trim()).filter(Boolean);
  if (selected.length === 0) {
    throw new Error(`container ${CONTAINER} has no CIVIKITCHEN_PROFILE`);
  }
  const roots = [...containerEnv('CIVIKITCHEN_PROFILE_PATH').split(':').filter(Boolean), BUNDLED];
  const expand = (name: string) => {
    if (applied!.has(name)) return;
    applied!.add(name);
    const found = roots.map((root) => `${root.replace(/\/$/, '')}/${name}/profile.json`)
      .filter((file) => docker(['exec', CONTAINER, 'sh', '-c', `test -f '${file}' && echo yes || echo no`]) === 'yes');
    if (found.length !== 1) {
      throw new Error(`profile ${name}: ${found.length} profile.json files in ${roots.join(', ')}`);
    }
    const profile = JSON.parse(docker(['exec', CONTAINER, 'cat', found[0]])) as { include?: string[] };
    (profile.include ?? []).forEach(expand);
  };
  selected.forEach(expand);
  return applied;
}

/** Skip the enclosing describe unless the profile was applied. */
export function requireProfile(name: string) {
  test.skip(!appliedProfiles().has(name), `profile ${name} is not applied`);
}

let core: number[] | undefined;

/** Major and minor of the site's CiviCRM, so a beta counts as its release. */
function coreMinor(): number[] {
  core ??= cv(['ev', 'echo CRM_Utils_System::version();']).split('.').slice(0, 2).map(Number);
  return core;
}

const before = (a: number[], b: number[]) => a[0] < b[0] || (a[0] === b[0] && a[1] < b[1]);
const minor = (version: string) => version.split('.').map(Number);

/**
 * Call first in a test a known upstream bug breaks (docs/upstream-bugs.md): on
 * the affected versions, with the profile that triggers it, it must fail, and a
 * pass turns it red.
 */
export function upstreamBug(bug: { url: string; since?: string; fixedIn?: string; profile?: string }) {
  const site = coreMinor();
  const affected = !(bug.profile && !appliedProfiles().has(bug.profile))
    && !(bug.since && before(site, minor(bug.since))) && !(bug.fixedIn && !before(site, minor(bug.fixedIn)));
  test.fail(affected, `known upstream bug: ${bug.url}`);
}

let uf: UserFramework | undefined;

export function userFramework(): UserFramework {
  uf ??= cv(['ev', 'echo CIVICRM_UF;']) as UserFramework;
  return uf;
}

/** The site-relative URL of a CiviCRM route; frontend for anonymous visitors. */
export function civiUrl(route: string, frontend = false): string {
  const url = new URL(JSON.parse(cv(['url', ...(frontend ? ['--frontend'] : []), route])) as string);
  return url.pathname + url.search + url.hash;
}

/** Collect 5xx responses and broken CSS/JS so a test can assert none happened. */
export function watchFailures(page: Page): string[] {
  const failures: string[] = [];
  page.on('response', (response) => {
    const status = response.status();
    if (status >= 500 || (status >= 400 && /\.(css|js|mjs)(\?|$)/i.test(response.url()))) {
      failures.push(`${status} ${response.url()}`);
    }
  });
  return failures;
}

/** A page that rendered without a PHP or database error. */
export async function expectHealthy(page: Page) {
  await expect(page.locator('body')).not.toContainText(/Fatal error|Parse error|PDOException|DB Error|Sorry, due to an error/i);
}
