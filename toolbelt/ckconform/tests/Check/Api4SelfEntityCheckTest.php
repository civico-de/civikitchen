<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\Api4SelfEntityCheck;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Tests\CheckTestCase;
use CiviKitchen\Ckconform\Tests\FakeCoreTrait;
use CiviKitchen\Toolbelt\Filesystem\Directory;

final class Api4SelfEntityCheckTest extends CheckTestCase
{
    use FakeCoreTrait;

    /** A frontend API module: a URL constant, a fetch helper and a wrapper over it. */
    private const API_MODULE = <<<'TS'
        const API_BASE = "/civicrm/ajax/api4";
        async function apiCall<T>(entity: string, action: string, params: Record<string, unknown> = {}): Promise<T[]> {
          const url = `${API_BASE}/${entity}/${action}`;
          const response = await fetch(url, { method: "POST", body: JSON.stringify(params) });
          return (await response.json()).values;
        }
        export async function getEntities<T>(
          entity: string,
          where: Array<[string, string, unknown]> = [],
          orderBy: Record<string, "ASC" | "DESC"> = {},
        ): Promise<T[]> {
          return apiCall<T>(entity, "get", { where, orderBy });
        }
        TS;

    /**
     * @param list<string> $entities
     */
    private function core(array $entities = ['Contact', 'Email', 'MailingJob']): void
    {
        $this->makeCore();
        foreach ($entities as $name) {
            file_put_contents(
                $this->core . '/Civi/Api4/' . $name . '.php',
                "<?php\nnamespace Civi\\Api4;\nclass {$name} {}\n"
            );
        }
    }

    /**
     * @param array<string, string> $files
     */
    private function ext(array $files): Context
    {
        $this->core();

        return $this->repo([
            'Civi/Api4/LedgerTransaction.php' => "<?php\n",
        ] + $files, git: true);
    }

    /** A fake entity under tests/fixtures is not shipped and must not vouch for a call. */
    public function testAFixtureEntityDoesNotCountAsShipped(): void
    {
        $context = $this->ext([
            'tests/fixtures/Civi/Api4/LedgerAdapter.php' => "<?php\n",
            'frontend/src/api.ts' => self::API_MODULE,
            'frontend/src/PipelineEditor.tsx' => "import { getEntities } from './api';\nconst a = await getEntities('LedgerAdapter', []);\n",
        ]);
        $this->assertFails($this->run_(new Api4SelfEntityCheck(), $context), 'LedgerAdapter');
    }

    public function testSilentWithoutJavaScript(): void
    {
        $this->assertSilent($this->run_(new Api4SelfEntityCheck(), $this->ext([])));
    }

    /**
     * The case this rule exists for: a React component calling an entity of
     * ours that was never written, through two layers of wrappers.
     */
    public function testAnEntityThatExistsNowhereFails(): void
    {
        $context = $this->ext([
            'frontend/src/api/civicrm.ts' => self::API_MODULE,
            'frontend/src/pipeline-editor/PipelineEditor.tsx'
                => "import { getEntities } from '@/api/civicrm';\n"
                . "const a = await getEntities<AdapterDefinition>('LedgerAdapter', [], ['*']);\n",
        ]);
        $reporter = $this->run_(new Api4SelfEntityCheck(), $context);
        $this->assertFails($reporter, 'LedgerAdapter');
        self::assertStringContainsString(
            'PipelineEditor.tsx',
            implode("\n", $reporter->messages('FAIL'))
        );
    }

    public function testAnEntityWeDefineOurselvesPasses(): void
    {
        $context = $this->ext([
            'frontend/src/api.ts' => self::API_MODULE,
            'frontend/src/x.ts' => "getEntities('LedgerTransaction', []);\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    public function testACoreEntityPasses(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "crmApi4('MailingJob', 'get', {});\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /**
     * Single-word strings are ordinary code — labels, keys, enum members. Only
     * the multi-word CamelCase shape of an entity class earns a look.
     */
    public function testSingleWordStringsAreNotEntityReferences(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "crmApi4('Hello', 'get');\nCRM.api4('Pending', 'get');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /**
     * ECK and multi-record custom group entities carry underscores and exist
     * only at runtime, so no class file can vouch for them.
     */
    public function testUnderscoredNamesAreNotEntityReferences(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "crmApi4('Eck_LedgerNote', 'get');\nCRM.api4('Custom_LedgerExtra', 'get');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** A name that is not the entity argument is not in entity position. */
    public function testOnlyTheEntityArgumentCounts(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "crmApi4('Contact', 'AcmeContactTab', {});\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /**
     * `expect(text).not.toBe('TitleParagraph')` is an assertion, not an API
     * call, and nothing about its shape says so.
     */
    public function testAssertionsInTestFilesAreNotEntityReferences(): void
    {
        $context = $this->ext([
            'tests/js/plain-text.test.js' => "expect(text).not.toBe('TitleParagraph');\n",
            'frontend/src/thing.spec.ts' => "expect(x).toEqual('SomeGhostEntity');\n",
        ]);
        $this->assertSilent($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** AngularJS registers controllers and services under CamelCase names of its own. */
    public function testAngularRegistrationsAreNotEntityReferences(): void
    {
        $context = $this->ext([
            'ang/crmLedger/List.js' => "angular.module('crmLedger').controller('LedgerCourseList', function() {});\n"
                . "angular.module('crmLedger')\n  .factory('LedgerStateStore', function() {});\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** A wrapper counts whatever its name, even one an AngularJS registrar shares. */
    public function testAWrapperNamedLikeARegistrarStillCounts(): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "function service(entity, action) { return crmApi4(entity, action, {}); }\n"
                . "const s = service('LedgerAdapter', 'get');\n",
        ]);
        $this->assertFails($this->run_(new Api4SelfEntityCheck(), $context), 'LedgerAdapter');
    }

    public function testBuiltArtefactsAreNotScanned(): void
    {
        $context = $this->ext([
            'dist/pipeline-editor.js' => "CRM.api4('LedgerAdapter', 'get')\n",
            'frontend/node_modules/pkg/index.js' => "CRM.api4('LedgerGhost', 'get')\n",
        ]);
        $this->assertSilent($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** @return iterable<string, array{string}> */
    public static function notEntityLiterals(): iterable
    {
        yield 'angular constant' => ["angular.module('ledger').constant('LedgerSettings', {});\n"];
        yield 'angular value' => ["angular.module('ledger').value('DefaultFilters', {});\n"];
        yield 'angular decorator' => ["app.decorator('LedgerDecorator', fn);\n"];
        yield 'web storage key' => ["localStorage.getItem('LedgerColumnState');\n"];
        yield 'DOM event constructor' => ["window.dispatchEvent(new CustomEvent('FormSaved', {}));\n"];
        yield 'event emitter' => ["emitter.emit('FormSaved', {});\nbus.\$emit('LedgerUpdated');\n"];
        yield 'redux action' => ["dispatch('ResetFilters');\n"];
        yield 'commented-out call' => ["// CRM.api4('LedgerAdapter', 'get', {});\n/* crmApi4('LedgerGhost') */\n"];
        yield 'fixed-entity URL in a helper' => [
            "function upload(file) { return fetch(CRM.url('civicrm/ajax/api4/Afform/submitFile'), { body: file }); }\n"
            . "upload('ReportFile');\n",
        ];
        yield 'method sharing a wrapper name' => [
            "const api = { get(entity, params) { return crmApi4(entity, 'get', params); } };\n"
            . "function load(entity) { return crmApi4(entity, 'get'); }\n"
            . "params.get('ContactId');\nformData.get('FirstName');\ni18n.load('CommonStrings');\n",
        ];
        yield 'regex literal with a quote' => [
            "export function escapeHtml(text) {\n  return text.replace(/&/g, '&amp;').replace(/\"/g, '&quot;');\n}\n"
            . "const text = 'Contact';\nexport const rows = CRM.api4(text, 'get', {});\nescapeHtml('PleaseWait');\n",
        ];
        yield 'regex literal with slashes' => [
            "function routeFor(entity) { return entity.replace(/\\//g, '-').replace(/^https?:\\/\\//, ''); }\n"
            . "const entity = 'Contact';\ncrmApi4(entity, 'get');\nrouteFor('LedgerOverview');\n",
        ];
        yield 'JSX text with an apostrophe' => [
            "function EmptyState(name) { return (<p>You don't have any {name} yet.</p>); }\n"
            . "const name = 'Contact';\nCRM.api4(name, 'get');\nEmptyState('LedgerRows');\n",
        ];
        yield 'JSX text with a URL and a glob' => [
            "function Footer(name) { return (<p>See https://civicrm.org and src/*.ts</p>); }\n"
            . "const name = 'Contact';\nCRM.api4(name, 'get');\nFooter('MainFooter');\n",
        ];
        yield 'shadowed parameter' => [
            "function useEntities(name, entities) {\n  setTitle(name);\n"
            . "  return entities.map((name) => crmApi4(name, 'get'));\n}\nuseEntities('PageTitle', ['Contact']);\n",
        ];
        yield 'URL joined with something other than a slash' => [
            "const API_URL = '/civicrm/ajax/api4';\nfunction debug(label) { console.debug(API_URL + ' ' + label); }\n"
            . "debug('RequestStart');\n",
        ];
        yield 'regex literal with quotes after an arrow' => [
            "export function quoteCount(text) {\n  return text.split('').filter((c) => /[\"']/.test(c)).length;\n}\n"
            . "const text = 'Contact';\nexport const rows = crmApi4(text, 'get');\nquoteCount('PleaseWait');\n",
        ];
        yield 'JSX self-closing tag before a closing tag' => [
            "export function Page(name) {\n  return <div>{error && <Alert message={error} />}</div>;\n}\n"
            . "const name = 'Contact';\nexport const rows = crmApi4(name, 'get');\nPage('LedgerPage');\n",
        ];
        yield 'api4 path in prose' => ["const help = 'POST to /civicrm/ajax/api4/MyEntity/get';\n"];
        yield 'block declaration shadowing a parameter' => [
            "function useThing(name, entities) {\n  setTitle(name);\n  for (const name of entities) { crmApi4(name, 'get'); }\n}\n"
            . "function useOther(entity) {\n  setTitle(entity);\n  { const entity = pick(); return crmApi4(entity, 'get'); }\n}\n"
            . "useThing('PageTitle', []);\nuseOther('OtherTitle');\n",
        ];
        yield 'regex literal after an arrow' => [
            "function track(name, urls) { send(name, urls.filter((s) => /^https?:\\/\\//.test(s)).filter((s) => /\"/.test(s))); }\n"
            . "const name = 'Contact';\ncrmApi4(name, 'get');\ntrack('PageView', []);\n",
        ];
        yield 'same-named functions that are not imported' => [
            "import request from 'superagent';\nimport { query } from '@tanstack/query';\n"
            . "request('PostForm', '/x');\nquery('SearchTerm');\n"
            . "function Page({ getEntities }) { return getEntities('PageData'); }\n"
            . "function useData(apiCall) { return apiCall('CacheKey'); }\n",
        ];
        yield 'typed parameter named like a wrapper' => ["function run(getEntities: (name: string) => void) { getEntities('FormSaved'); }\n"];
        yield 'destructured and caught redeclarations' => [
            "function notify(entity) {\n  if (ready) { const { entity } = settings; return crmApi4(entity, 'get'); }\n  emit(entity);\n}\n"
            . "function report(entity) {\n  try { emit(entity); } catch ({ entity }) { return crmApi4(entity, 'get'); }\n}\n"
            . "notify('FormSaved');\nreport('FormFailed');\n",
        ];
        yield 'local declarations hiding a wrapper' => [
            "export function Cached() { const getEntities = (key) => cache.read(key); return getEntities('LedgerCacheKey'); }\n"
            . "export function Stored() { const apiCall = store.reader; return apiCall('LedgerStoreKey'); }\n"
            . "export function Picked() { const { getEntities } = store; return getEntities('LedgerPickKey'); }\n",
        ];
        yield 'nested helper named like a wrapper of the same file' => [
            "export function load(entity) { return crmApi4(entity, 'get'); }\n"
            . "export function usePrefs() { const load = (key) => localStorage.getItem(key); return load('LedgerColumnState'); }\n"
            . "export function prefs() { function load(key) { return sessionStorage.getItem(key); } return load('LedgerRowState'); }\n",
        ];
        yield 'comparison in a default value' => [
            "function load(entity) { return crmApi4(entity, 'get'); }\n"
            . "export function Prefs(limit = max > 10 ? 10 : max, load) { return load('LedgerColumnState'); }\n",
        ];
        yield 'parameter hiding a namespace import' => [
            "import * as api from './api';\nexport function Page(api) { return api.getEntities('PageData'); }\n",
        ];
        yield 'capitalised receivers' => ["Cookies.get('CsrfToken');\nObject.keys('SomeThing');\nApi.getEntities('LedgerThing');\n"];
        yield 'function that calls the API but forwards its argument elsewhere' => [
            "function notify(eventName) { crmApi4('Contact', 'get', {}); bus.emit(eventName); }\n"
            . "notify('FormSaved');\n",
        ];
    }

    /** @dataProvider notEntityLiterals */
    public function testPascalCaseLiteralsOutsideApiCallsAreNotEntities(string $line): void
    {
        $context = $this->ext([
            'frontend/src/x.ts' => "import { apiCall, getEntities } from './api';\n" . $line,
            'frontend/src/api.ts' => self::API_MODULE,
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    public function testADeclaredDependencyEntityPasses(): void
    {
        $context = $this->ext([
            '__policy_fixture' => "known_api4_entities=OtherextRecord -- supplied by required otherext\n",
            'info.xml' => $this->infoXml(extra: '<requires><ext>otherext</ext></requires>'),
            'ang/ledger.js' => "CRM.api4('OtherextRecord', 'get', {});\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    public function testAnEntityOfARequiredExtensionOnDiskPasses(): void
    {
        $key = 'otherext' . bin2hex(random_bytes(4));
        $context = $this->ext([
            'info.xml' => $this->infoXml(extra: "<requires><ext>{$key}</ext></requires>"),
            'ang/ledger.js' => "CRM.api4('OtherextRecord', 'get', {});\n",
        ]);
        $sibling = dirname($context->root) . '/' . $key;
        mkdir($sibling . '/Civi/Api4', 0777, true);
        try {
            file_put_contents($sibling . '/info.xml', $this->infoXml(key: $key));
            file_put_contents($sibling . '/Civi/Api4/OtherextRecord.php', "<?php\n");
            $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
        } finally {
            Directory::remove($sibling);
        }
    }

    public function testAnUnknownEntityInAnApiCallStillFails(): void
    {
        $context = $this->ext(['ang/ledger.js' => "CRM.api4('LedgerAdapter', 'get', {});\n"]);
        $this->assertFails($this->run_(new Api4SelfEntityCheck(), $context), 'LedgerAdapter');
    }

    /** @return iterable<string, array{string}> */
    public static function wrapperForms(): iterable
    {
        yield 'arrow wrapper' => ["const fetchAll = (entity: string) => crmApi4(entity, 'get', {});\nfetchAll('LedgerAdapter');\n"];
        yield 'arrow without parentheses' => ["const get = entity => CRM.api4(entity, 'get');\nget('LedgerAdapter');\n"];
        yield 'function assigned to an object' => [
            "api.load = function (entity, params) {\n  return crmApi4(entity, 'get', params);\n};\n"
            . "api.load('LedgerAdapter', {});\n",
        ];
        yield 'object return type' => [
            "async function load(entity: string): Promise<{ values: unknown[] }> {\n"
            . "  return crmApi4(entity, 'get', {});\n}\nload('LedgerAdapter');\n",
        ];
        yield 'nested generics' => [
            "function load<T extends Record<string, unknown>>(entity: string): Promise<T[]> {\n"
            . "  return crmApi4(entity, 'get', {});\n}\nload<Array<Row>>('LedgerAdapter');\n",
        ];
        yield 'arrow body continued on the next line' => [
            "const load = (entity) =>\n  cache[entity]\n    ? cache[entity]\n    : crmApi4(entity, 'get', {});\nload('LedgerAdapter');\n",
        ];
        yield 'typed arrow without parentheses' => ["const get: Getter = entity => crmApi4(entity, 'get');\nget('LedgerAdapter');\n"];
        yield 'call through a namespace import' => [
            "import * as api from './api/civicrm';\napi.getEntities('LedgerAdapter');\n",
        ];
        yield 'call in a ternary' => ["const p = ready ? crmApi4('LedgerAdapter', 'get', {}) : { values: [] };\n"];
        yield 'division after an increment or a keyword-named property' => [
            "const half = i++ / 2; crmApi4('LedgerAdapter', 'get'); const r = half / 3;\n"
            . "const q = obj.in / 2; crmApi4('LedgerAdapter', 'get'); const z = 1 / 4;\n",
        ];
        yield 'regex literal before the call' => [
            "const q = s.replace(/\"/g, '&quot;').replace(/`/g, '');\ncrmApi4('LedgerAdapter', 'get');\n",
        ];
        yield 'entity in second position, URL by concatenation' => [
            "function api4(config, entity, action) {\n  const url = config.url +\n    \"/civicrm/ajax/api4/\" +\n"
            . "    encodeURIComponent(entity) + \"/\" + action;\n  return request(config, url);\n}\n"
            . "api4(config, 'LedgerAdapter', 'get');\n",
        ];
        yield 'literal REST path' => ["fetch(CRM.url('civicrm/ajax/api4/LedgerAdapter/get'), {});\n"];
        yield 'wrapper named in a default value' => [
            "import { getEntities } from './api/civicrm';\nfunction load(fetch = getEntities) { return getEntities('LedgerAdapter'); }\n",
        ];
        yield 'parameter redeclared in a loop only' => [
            "function fetchAll(entity) {\n  for (const entity of extra) { log(entity); }\n  return crmApi4(entity, 'get');\n}\nfetchAll('LedgerAdapter');\n",
        ];
        yield 'call inside a component' => [
            "import { getEntities } from './api/civicrm';\nexport function Ledger() { const rows = getEntities('LedgerAdapter'); return rows; }\n",
        ];
        yield 'wrapper inside a module IIFE' => [
            "(function (angular) {\n  function load(entity) { return crmApi4(entity, 'get'); }\n  load('LedgerAdapter');\n})(angular);\n",
        ];
        yield 'injected crmApi4' => [
            "angular.module('ledger').controller('LedgerCtrl', ['\$scope', 'crmApi4', function (\$scope, crmApi4) {\n"
            . "  crmApi4('LedgerAdapter', 'get');\n}]);\n",
        ];
    }

    /** @dataProvider wrapperForms */
    public function testAnEntityThroughAnyWrapperFormFails(string $source): void
    {
        $context = $this->ext(['frontend/src/x.ts' => $source, 'frontend/src/api/civicrm.ts' => self::API_MODULE]);
        $this->assertFails($this->run_(new Api4SelfEntityCheck(), $context), 'LedgerAdapter');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function moduleForms(): iterable
    {
        yield 'parent directory' => [[
            'frontend/src/api/civicrm.ts' => self::API_MODULE,
            'frontend/src/views/x.ts' => "import { getEntities } from '../api/civicrm';\ngetEntities('LedgerAdapter');\n",
        ]];
        yield 'explicit index' => [[
            'frontend/src/api/index.ts' => self::API_MODULE,
            'frontend/src/x.ts' => "import { getEntities } from './api/index';\ngetEntities('LedgerAdapter');\n",
        ]];
        yield 'barrel re-exporting everything' => [[
            'frontend/src/api/civicrm.ts' => self::API_MODULE,
            'frontend/src/api/index.ts' => "export * from './civicrm';\n",
            'frontend/src/x.ts' => "import { getEntities } from '@/api';\ngetEntities('LedgerAdapter');\n",
        ]];
        yield 'barrel renaming' => [[
            'frontend/src/api/civicrm.ts' => self::API_MODULE,
            'frontend/src/api/index.ts' => "export { getEntities as fetchRows } from './civicrm';\n",
            'frontend/src/x.ts' => "import { fetchRows } from './api';\nfetchRows('LedgerAdapter');\n",
        ]];
        yield 'default import' => [[
            'frontend/src/load.ts' => "export default function load(entity) { return crmApi4(entity, 'get'); }\n",
            'frontend/src/x.ts' => "import fetchRows from './load';\nfetchRows('LedgerAdapter');\n",
        ]];
    }

    /**
     * @dataProvider moduleForms
     * @param array<string, string> $files
     */
    public function testAWrapperIsFollowedThroughAnyImportForm(array $files): void
    {
        $this->assertFails($this->run_(new Api4SelfEntityCheck(), $this->ext($files)), 'LedgerAdapter');
    }

    /** `./api` is the sibling module, not every file named api. */
    public function testARelativeImportResolvesToItsSiblingOnly(): void
    {
        $context = $this->ext([
            'frontend/src/a/api.ts' => self::API_MODULE,
            'frontend/src/b/api.ts' => "export function getEntities(name) { bus.emit(name); }\n",
            'frontend/src/b/view.ts' => "import { getEntities } from './api';\ngetEntities('FormSaved');\n",
            'frontend/src/c/a.ts' => "export * from './b';\n",
            'frontend/src/c/b.ts' => "export * from './a';\n",
            'frontend/src/c/view.ts' => "import { getEntities } from './a';\ngetEntities('FormLoaded');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** `@/utils` names the nearest file ending in utils, not every one in the repo. */
    public function testAnAliasImportResolvesToTheNearestMatch(): void
    {
        $context = $this->ext([
            'frontend/src/utils.ts' => "export function load(bundle: string) { return fetch('/l10n/' + bundle); }\n",
            'ang/legacy/utils.js' => "function load(entity) { return CRM.api4(entity, 'get'); }\n",
            'frontend/src/Page.tsx' => "import { load } from '@/utils';\nload('CommonStrings');\n",
            'frontend/src/page.ts' => "function connect(entity: string) { return crmApi4(entity, 'get'); }\nexport default connect(mapState)(Page);\n",
            'frontend/src/App.tsx' => "import Page from './page';\nPage('MainPage');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** An import statement quoted in a string imports nothing. */
    public function testAnImportInsideAStringDoesNotCount(): void
    {
        $context = $this->ext([
            'frontend/src/api.ts' => self::API_MODULE,
            'frontend/src/docs.ts' => "const snippet = `\nimport { getEntities } from './api';\n`;\n"
                . "const { getEntities } = useLedger();\ngetEntities('FormSaved');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** A wrapper defined in one file is followed into the files that call it. */
    public function testAWrapperIsFollowedAcrossFiles(): void
    {
        $context = $this->ext([
            'frontend/src/api.ts' => self::API_MODULE,
            'frontend/src/Ledger.tsx' => "import { getEntities as load } from '../src/api';\n"
                . "const rows = await load('LedgerAdapter');\nemit('LedgerLoaded');\n",
        ]);
        $reporter = $this->run_(new Api4SelfEntityCheck(), $context);
        $this->assertFails($reporter, 'LedgerAdapter (frontend/src/Ledger.tsx)');
        self::assertStringNotContainsString('LedgerLoaded', implode("\n", $reporter->messages('FAIL')));
    }

    /** A base URL constant belongs to the file that defines it. */
    public function testAUrlConstantDoesNotLeakIntoOtherFiles(): void
    {
        $context = $this->ext([
            'frontend/src/api.ts' => self::API_MODULE,
            'frontend/src/icons.ts' => "const API_BASE = '/assets/icons';\n"
                . 'function iconUrl(name) { return `${API_BASE}/${name}.svg`; }' . "\niconUrl('ArrowLeft');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** A file's own function shadows a same-named wrapper elsewhere. */
    public function testALocalFunctionShadowsAWrapperOfTheSameName(): void
    {
        $context = $this->ext([
            'frontend/src/a.ts' => "export function request(entity, action) { return crmApi4(entity, action); }\n",
            'frontend/src/b.ts' => "function request(method, url) { return fetch(url, { method }); }\nrequest('PostForm', '/x');\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }

    /** `this.load = ...` in one class says nothing about another class's `this.load(`. */
    public function testOwnerAssignmentsStayInTheirFile(): void
    {
        $context = $this->ext([
            'frontend/src/a.ts' => "class Store { constructor() { this.load = (entity) => crmApi4(entity, 'get'); } }\n",
            'frontend/src/b.ts' => "class Loader { load(file) { return file; } init() { return this.load('ConfigFile'); } }\n",
        ]);
        $this->assertPasses($this->run_(new Api4SelfEntityCheck(), $context));
    }
}
