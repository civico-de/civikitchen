# Playwright UI tests for a CiviCRM extension

A copy-pasteable starter: boot CiviCRM in Docker, run Playwright tests
against `localhost:8080`, also in Docker. The login is handled once via a
setup project and shared across all tests.

## Try it

```bash
cd examples/extension-with-playwright
docker compose up -d                              # CiviCRM on http://localhost:8080
../../scaffold/ck playwright npm run test:e2e    # runs the example tests
```

`ck playwright` builds an image with the browsers of the Playwright version
`package-lock.json` resolves and the npm tree, once per lockfile, and runs the
command in it on the host network. The host needs only Docker: no PHP, no Node
and no browsers. In your own extension, call `ck` from your civikitchen
checkout, for example `../civikitchen/scaffold/ck`.

The script is called `test:e2e`, not `test`, because that is the name the
shared `extension-ci.yml` runs when you switch its `playwright` input on —
and it leaves npm's `test` slot free for the JS unit suite that CI's
`js_tests` input runs. Two suites, two scripts, both reachable from CI.

The example mounts `extensions/de.systopia.contract` so you can see green
tests immediately. Replace the volume in `docker-compose.yml` with your own
extension when you're ready (see comments in that file).

The container has no display, so there is no headed browser or Inspector.
UI mode, the HTML report and the trace viewer serve on localhost instead:

```bash
ck playwright npm run test:ui                            # UI mode on http://127.0.0.1:9323
ck playwright playwright show-report --host 127.0.0.1    # after a run with the html reporter
ck playwright playwright show-trace --host 127.0.0.1 test-results/<test>/trace.zip
```

## Run it in CI

Once the files below are in your extension repo, the shared workflow runs
this suite for you — no repo-local browser job:

```yaml
    with:
      key: myextension
      playwright: true
```

The job boots your `.docker/docker-compose.ci.yml` stack with the `admin` /
`admin` demo user created, then runs `npm run test:e2e` through `ck playwright`
inside the app container's network, with `CIVICRM_BASE_URL`, `DEMO_USER` and
`DEMO_PASS` set — the same variables `playwright.config.ts` and
`tests/auth.setup.ts` already read here. There the site is `http://localhost`,
so a test that needs the site's URL takes it from `baseURL`, never as a
literal `localhost:8080`. Report and traces are uploaded when it fails. It is
a slow check: put it in the scheduled caller, not in `ci.yml`. Add `bun: true`
alongside and the job installs and runs the suite with Bun instead; nothing in
these files changes. See
[extension-standards.md](../../docs/extension-standards.md#frontend-js-dependencies-js-tests-and-browser-tests).

## Use it in your own extension

Copy these files into your extension repo:

- `playwright.config.ts`
- `tests/auth.setup.ts`
- `tests/extension.spec.ts` — keep as a smoke test, add your own alongside
- `package.json` — or merge `devDependencies` and `scripts` into yours
- `.gitignore` — or append `node_modules/`, `.auth/`, `playwright-report/`,
  `test-results/` to yours

Then point `docker-compose.yml` (a copy of [`examples/standalone/`](../standalone/))
at your extension via the `volumes:` mapping, and you're set.

## How it works

`playwright.config.ts` defines two projects:

1. **setup** runs `tests/auth.setup.ts` once, logs in as the demo user, and
   writes the cookies/localStorage to `.auth/admin.json`.
2. **chromium** depends on `setup` and loads `.auth/admin.json` as
   `storageState`, so every test starts authenticated.

## Credentials

The `auth.setup.ts` step logs in with `admin` / `admin` by default — that's
the demo user the standalone image creates on first start (controlled by
`CIVIKITCHEN_DEMO_USER` / `CIVIKITCHEN_DEMO_PASS` in `docker-compose.yml`).

If you change the demo user, override at test time:

```bash
DEMO_USER=alice DEMO_PASS=secret ck playwright npm run test:e2e
```

## When to use Playwright vs. PHPUnit

- **Playwright** for UI flows: forms, modals, Angular/React widgets, JS
  behaviour, anything you'd otherwise test by clicking around.
- **PHPUnit (headless)** for API/business logic: APIv4 calls, hooks, BAOs,
  workflows. Faster, no browser, no compose stack required for unit tests.
  See the main [Extension development](../../docs/extension-development.md)
  guide for the headless setup.
