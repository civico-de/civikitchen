# The entry point for working on civikitchen locally, and the implementation
# .github/workflows/lint.yml calls — one implementation, reachable from both
# sides. Before it, the fast test suites could be run in exactly one place:
# GitHub Actions.
#
# Every target is .PHONY: nothing here is built from file timestamps, so Make
# contributes no dependency graph — it contributes being installed everywhere
# and `make help`.

include toolbelt/versions.env

# Strict bash for every recipe: `set -eu -o pipefail` is what the shell in this
# repo already runs under, and a recipe that swallows a failing stage in a pipe
# is exactly the kind of green-but-untested that this file exists to end.
#
# A make without .SHELLFLAGS (< 3.82, e.g. Apple's 3.81) IGNORES that line and
# runs every recipe unguarded — a failing shellcheck then still prints "clean"
# and exits 0. Refuse loudly instead; `oneshell` arrived in the same release,
# so its presence in .FEATURES is the exact capability probe.
ifeq ($(filter oneshell,$(.FEATURES)),)
$(error GNU Make >= 3.82 required: this make ($(MAKE_VERSION)) ignores .SHELLFLAGS, so recipes would run without 'set -eu -o pipefail'. Run `bash scripts/doctor.sh` for this and every other host prerequisite at once)
endif

# `bash` resolved via PATH, not /bin/bash: macOS ships bash 3.2 frozen at that
# path, and a modern one is expected to be first in PATH there.
SHELL := bash
.SHELLFLAGS := -eu -o pipefail -c

# Downloads land here rather than in /tmp so a second run reuses them, and so
# `make clean` has one thing to remove. CI overrides nothing: same paths.
CACHE := .cache
PHPUNIT := $(CACHE)/phpunit-$(CK_PHPUNIT_VERSION).phar
SHARED_PHP_COVERAGE := $(CACHE)/shared-php-coverage.xml
SHARED_PHP_COVERAGE_MIN := 36
SCENARIO_YAML_STAMP := packages/civikitchen-scenario-schema/vendor/.civikitchen-installed
OXLINT_STAMP := toolbelt/oxlint/node_modules/.civikitchen-installed

# The pinned release IN the path: a catalog bump must invalidate the cached
# tree, or the drift gates keep testing the old core until a `make clean`.
# The four-pin agreement check stays in the recipe, which still fails loudly
# when the catalogs disagree among themselves.
CORE_VERSION := $(shell php -r 'require "toolbelt/ckconform/src/HookCatalog.php"; echo CiviKitchen\Ckconform\HookCatalog::CORE_VERSION;')
CORE := $(CACHE)/civicrm-core-$(CORE_VERSION)

# Pinned here, not in versions.env: this is their only consumer. actionlint's
# installer is a shell script piped into bash, so it gets the same treatment as
# the phars — pinned tag AND pinned content. Re-derive after a bump with
# `curl -fsSL <url> | sha256sum`.
CK_ACTIONLINT_VERSION := 1.7.12
CK_ACTIONLINT_INSTALLER_SHA256 := 72fa3e45ac20f3c3a512d6747b4fcf719e21f890e8c43e78d48a41fdfb900c4e
CK_ZIZMOR_VERSION := 1.29.0
CK_JSCPD_VERSION := 5.0.14
CK_ZENSICAL_VERSION := 0.0.70

# zizmor and check-jsonschema are Python CLIs fetched on demand rather than
# installed. uv and pipx both do that, so demanding one specific runner would
# invent a prerequisite this repo does not have — GitHub's runners ship pipx,
# a laptop is as likely to have uv. Same pinned versions either way; only the
# pin syntax differs.
ifeq ($(shell command -v uvx >/dev/null 2>&1 && echo yes),yes)
pyrun_pinned = uvx $(1)@$(2)
pyrun = uvx $(1)
else
pyrun_pinned = pipx run $(1)==$(2)
pyrun = pipx run $(1)
endif

# shellcheck is fetched and pinned like the phars, because the sweep's verdict
# depends on the version: 0.10 grew checks 0.9 never ran, so a laptop's distro
# shellcheck and CI's disagreeing turns "one implementation for both" into two.
# One content hash per supported platform; a new platform adds its pair here.
CK_SHELLCHECK_VERSION := 0.11.0
CK_SHELLCHECK_SHA256_linux_x86_64 := 8c3be12b05d5c177a04c29e3c78ce89ac86f1595681cab149b65b97c4e227198
CK_SHELLCHECK_SHA256_darwin_aarch64 := 56affdd8de5527894dca6dc3d7e0a99a873b0f004d7aabc30ae407d3f48b0a79
# `uname -m` says arm64 on macOS but the release assets say aarch64.
SHELLCHECK_PLATFORM := $(shell uname -s | tr '[:upper:]' '[:lower:]').$(subst arm64,aarch64,$(shell uname -m))
SHELLCHECK_SHA256 := $(CK_SHELLCHECK_SHA256_$(subst .,_,$(SHELLCHECK_PLATFORM)))
SHELLCHECK := $(CACHE)/shellcheck-$(CK_SHELLCHECK_VERSION)

# mago for the extension's fixture suite: the image's release (versions.env),
# one hash per platform there. Release assets are named by target triple.
MAGO_TRIPLE_linux_x86_64 := x86_64-unknown-linux-gnu
MAGO_TRIPLE_linux_aarch64 := aarch64-unknown-linux-gnu
MAGO_TRIPLE_darwin_aarch64 := aarch64-apple-darwin
MAGO_TRIPLE := $(MAGO_TRIPLE_$(subst .,_,$(SHELLCHECK_PLATFORM)))
MAGO_SHA256 := $(CK_MAGO_SHA256_$(subst .,_,$(SHELLCHECK_PLATFORM)))
MAGO := $(CACHE)/mago-$(CK_MAGO_VERSION)

# What gets linted is decided by WHAT A FILE IS, never by where it lives.
#
# A directory list fails OPEN: a file it does not match is not reported as
# unchecked, it is simply absent, and the run says "clean". That happened twice
# here — a *.sh-only glob skipped every ck* tool, and the directory list that
# replaced it omitted template/, leaving three template-MANAGED files (stamped
# byte-identical into every extension repo) ungated.
#
# `if`, not `cmd && printf`: under `set -e` a non-matching grep fails the whole
# AND-list and kills the loop, silently and short. An `if` condition is exempt.
# `(*.sh)`, balanced: bash 3.2 cannot parse an unbalanced case paren inside
# `$( )` and dies on the whole substitution.
WORKTREE_FILES = git ls-files --cached --others --exclude-standard
SHELL_FILES = $(WORKTREE_FILES) | while read -r f; do \
	  case "$$f" in (*.sh) printf '%s\n' "$$f"; continue ;; esac ; \
	  if grep -qaE '^\#!.*(\bsh\b|\bbash\b)|^\# shellcheck shell=' \
	      < <(head -1 "$$f" 2>/dev/null) ; then \
	    printf '%s\n' "$$f" ; \
	  fi ; \
	done
PHP_FILES = $(WORKTREE_FILES) -- '*.php'

# A selector that matches nothing must be an error, not a pass. Otherwise the
# fail-open bug just moves up a level: a broken pattern would lint zero files
# and report success, which is precisely the failure mode these selectors were
# rewritten to end.
define require_nonempty
[ -n "$(1)" ] || { echo "no $(2) files matched — the selector is broken" >&2; exit 1; }
endef

.DEFAULT_GOAL := help
.PHONY: help doctor release test test-shared-php-coverage test-ckconform test-phpstan test-mago-ext test-mago-stubs test-rector test-ckinit test-example-template test-ckcreate test-ckcivix test-ckmodernize test-ck test-ckboot test-composer-deps test-profiles test-scenario \
	test-compose-isolation test-concurrency-groups test-matrix-legs test-sibling-wiring test-dist-build-wiring test-pre-checkout-workdir test-image-wiring test-promote-scanned test-promote-digest test-composer-auth test-stack-image test-gitlab-mr-jobs test-sibling-checkout test-vendored-paths test-ckeslint test-ckcoverage test-ckcommon-git test-missing-tool test-doctor test-tool-locks \
	test-ck-headless test-ckcoretest test-phpstan-bootstrap test-shell-portability test-install-trivy test-resolve-civicrm-stable test-resolve-civicrm-git test-cms-families lint lint-shell lint-shell-portability \
	test-database-matrix test-compose-config test-playwright-port test-demo-basic-auth test-release-retag test-release-steps test-ci-gates-step test-release-script test-release-verify-images \
        lint-actions lint-php lint-schema lint-changelog docs docs-serve docs-a11y test-changelog test-extension-docs build test-images e2e-ckcreate e2e tools clean

help: ## Show this help
	@echo "civikitchen — make targets"
	@echo
	@grep -hE '^[a-z][a-z0-9-]*:.*?## ' $(MAKEFILE_LIST) \
	  | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'
	@echo
	@echo "  test and lint need no Docker. build and test-images do."

# --- the fast loop -----------------------------------------------------------

# Every host prerequisite in one pass, before a recipe fails on the first of
# them. Deliberately not a prerequisite of lint/test: those must stay usable on
# a host that is missing only what they do not touch.
doctor: ## Report every missing host prerequisite in one pass
	bash scripts/doctor.sh

test: test-shared-php-coverage test-ckconform test-phpstan test-mago-ext test-mago-stubs test-rector test-ckinit test-example-template test-ckcreate test-ckcivix test-ckmodernize test-ck test-ckboot test-composer-deps test-profiles test-scenario test-provision test-core-patches test-ckcoretest test-ck-headless test-ck-headless-phpstan test-phpstan-bootstrap test-ckcommon-git test-missing-tool test-compose-isolation test-concurrency-groups test-matrix-legs test-sibling-wiring test-dist-build-wiring test-pre-checkout-workdir test-image-wiring test-promote-scanned test-promote-digest test-composer-auth test-stack-image test-gitlab-mr-jobs test-sibling-checkout test-sibling-vendor test-sibling-build test-database-matrix test-demo-basic-auth test-release-retag test-release-move-major-tag test-release-publish-flags test-release-steps test-ci-gates-step test-release-script test-release-verify-images test-vendored-paths test-ckeslint test-ckcoverage test-doctor test-tool-locks test-shell-portability test-install-trivy test-resolve-civicrm-stable test-resolve-civicrm-git test-cms-families test-changelog test-extension-docs ## Run every fast test suite (no Docker)

test-shared-php-coverage: $(PHPUNIT) $(SCENARIO_YAML_STAMP) ## Shared PHP unit tests and measured line-coverage floor
	if command -v phpdbg >/dev/null 2>&1; then \
	  phpdbg -qrr $(PHPUNIT) -c tests/shared-php/phpunit.xml.dist --coverage-clover $(SHARED_PHP_COVERAGE); \
	elif grep -qiE '^(pcov|xdebug)$$' <<<"$$(php -m)"; then \
	  XDEBUG_MODE=coverage php $(PHPUNIT) -c tests/shared-php/phpunit.xml.dist --coverage-clover $(SHARED_PHP_COVERAGE); \
	else \
	  echo 'shared PHP coverage requires phpdbg, pcov, or xdebug' >&2; exit 2; \
	fi
	php tests/shared-php/assert-coverage.php $(SHARED_PHP_COVERAGE) $(SHARED_PHP_COVERAGE_MIN)
	php tests/shared-php/test-assert-coverage.php

# The catalogs are generated from a CiviCRM release and rot on their own: core
# adds hooks and namespaces every release, and a stale catalog reports them as
# typos. The drift tests regenerate and diff — but they SKIP without a core
# checkout, so without $(CORE) the suite goes green while the gate never runs.
test-ckconform: $(PHPUNIT) $(CORE) $(SCENARIO_YAML_STAMP) ## ckconform's check fixtures + catalog drift gates
	CIVICRM_CORE_DIR=$(abspath $(CORE)) \
	  php $(PHPUNIT) -c toolbelt/ckconform/phpunit.xml.dist

# The rule fixtures run through phpstan's own test harness, so this package
# needs its vendor tree; the drift gates alone would not.
test-phpstan: $(PHPUNIT) $(CORE) ## The phpstan extension's rule tests + catalog drift gates
	composer install --no-interaction --no-progress --working-dir=toolbelt/phpstan
	CIVICRM_CORE_DIR=$(abspath $(CORE)) \
	  php $(PHPUNIT) -c toolbelt/phpstan/phpunit.xml.dist
	# Without fiber scopes a rule sees body variables as mixed; both paths must agree.
	PHPSTAN_FNSR=0 CIVICRM_CORE_DIR=$(abspath $(CORE)) \
	  php $(PHPUNIT) -c toolbelt/phpstan/phpunit.xml.dist

# Every mago extension rule against its fixtures, through the pinned mago
# binary; the runner refuses a binary whose version differs from the SDK's.
test-mago-ext: $(MAGO) ## The mago extension's rule and plugin fixtures
	composer install --no-interaction --no-progress --working-dir=toolbelt/mago-ext
	php toolbelt/mago-ext/tests/run.php --mago=$(abspath $(MAGO))

# A stub replaces core's declaration for mago, so a member core adds or changes
# must fail here; the image tests run the same check against each image's core.
test-mago-stubs: $(CORE) ## The mago stubs declare every member of the pinned core
	CIVICRM_CORE_DIR=$(abspath $(CORE)) php tests/toolbelt/test-mago-stub-parity.php

# ckmodernize writes these rewrites into extension code, so each rule's
# fixtures pin both the rewrite and the calls it must leave alone.
test-rector: $(PHPUNIT) ## The rector rules' fixture tests
	composer install --no-interaction --no-progress --working-dir=toolbelt/rector
	php $(PHPUNIT) -c toolbelt/rector/phpunit.xml.dist

# ckinit stamps the template into every extension repo and its --check mode
# gates those repos' CI, so a silent regression here either rewrites files it
# must not touch or waves drift through.
test-ckinit: $(SCENARIO_YAML_STAMP) ## ckinit seed/update/check integration checks, ckup port picking
	bash tests/ckinit/test-ckinit.sh
	bash tests/ckinit/test-ckinit-wrapper.sh
	bash tests/ckinit/test-ckup.sh
	bash tests/ckinit/test-ckx.sh

test-example-template: $(SCENARIO_YAML_STAMP) ## The monorepo examples carry the current template files and pass ckconform
	set -e; for info in examples/monorepo/*/info.xml; do php scaffold/ckinit.php --check "$$(dirname "$$info")"; (cd "$$(dirname "$$info")" && ../../../toolbelt/bin/ckconform >/dev/null) || { echo "ckconform fails in $$(dirname "$$info")" >&2; exit 1; }; done

test-ckcreate: $(SCENARIO_YAML_STAMP) ## ckcreate orchestration and atomic-output checks (fake Docker)
	bash tests/ckcreate/test-ckcreate.sh

test-ckcivix: ## ckcivix current/behind/missing/update checks (fake civix)
	bash tests/toolbelt/test-ckcivix.sh

test-ckmodernize: ## ckmodernize --fix stops on civix failures, convert-entity only with schema XML (fake civix)
	bash tests/toolbelt/test-ckmodernize.sh

test-ck: ## Unified ck dispatcher and compatibility aliases
	bash tests/toolbelt/test-ck.sh

test-ckboot: ## ckboot passes the repository's init.d hooks and dev profile to the entrypoint
	bash tests/toolbelt/test-ckboot.sh

test-ckcommon-git: ## ck_git trusts the worktree root, not the cwd
	bash tests/toolbelt/test-ckcommon-git.sh

test-missing-tool: ## A tool missing from PATH is named, not a proc_open warning
	bash tests/toolbelt/test-missing-tool.sh

test-composer-deps: ## ckdeps config: core-provided classes only, sibling checkouts not scanned
	php tests/toolbelt/test-composer-deps.php
	composer install --no-interaction --no-progress --working-dir=toolbelt/ckdeps-root
	bash tests/toolbelt/test-composer-deps-paths.sh

test-profiles: $(SCENARIO_YAML_STAMP) ## Profile schema, external resolution, credentials modes, file permissions and seed helpers
	php tests/profiles/test-credentials.php
	php tests/profiles/test-validator.php
	php -d zend.assertions=1 -d assert.exception=1 tests/profiles/test-profile-data.php
	php tests/profiles/test-seed-lib.php
	bash tests/profiles/test-external-profiles.sh
	bash tests/profiles/test-profile-clone.sh
	bash tests/profiles/test-profile-composer.sh

test-scenario: $(SCENARIO_YAML_STAMP) ## Declarative scenario schema, plan, Compose render and fixed checks
	bash tests/scenario/test-scenario.sh

# The <requires> resolution itself is ExtensionProvisionerTest in test-shared-php-coverage;
# these check provision.sh hands it the ext dir, archive dir, mounts and lists.
test-provision: ## provision.sh: extension provisioning wiring, mounted extensions, core locales, test DB and its boot patch, no civicrm.org calls (fake cv), site port, database marker
	bash tests/toolbelt/test-provision-requires.sh
	bash tests/toolbelt/test-provision-mounts.sh
	bash tests/toolbelt/test-provision-locales.sh
	bash tests/toolbelt/test-provision-test-db.sh
	bash tests/toolbelt/test-provision-phone-home.sh
	bash tests/toolbelt/test-provision-db-marker.sh
	bash tests/toolbelt/test-provision-site-port.sh
	bash tests/toolbelt/test-test-db-boot.sh

test-ckcoretest: ## ckcoretest --e2e runs only e2e tests against the dev site, as www-data; headless runs keep the test-DB gate and get core's startup ini
	bash tests/toolbelt/test-ckcoretest.sh

test-core-patches: ## Standalone core patches: applied once, contained ones skipped, stale or unlinked ones fail
	bash tests/toolbelt/test-core-patches.sh

# Two managed template files derive the <requires> chain from info.xml — the
# test bootstrap for Civi\Test, the phpstan bootstrap for class resolution.
test-ck-headless: ## ck_headless() installs the <requires> closure and rebuilds on install-file changes (stubbed Civi)
	bash tests/toolbelt/test-ck-headless.sh

test-ck-headless-phpstan: $(CORE) ## The managed ckHeadless.php passes phpstan level 10 against the pinned core
	composer install --no-interaction --no-progress --working-dir=toolbelt/phpstan
	CIVICRM_CORE_DIR=$(abspath $(CORE)) bash tests/toolbelt/test-ck-headless-phpstan.sh

test-phpstan-bootstrap: ## phpstanBootstrap.php autoloads mounted <requires> extensions (stubbed core)
	bash tests/toolbelt/test-phpstan-bootstrap.sh

# `vendored_paths` decides what the linters never see; a regression there is
# silent by construction — the run says "clean" about files it skipped, or
# reformats third-party source that must stay byte-identical.
test-vendored-paths: ## civikitchen.yaml vendored_paths file-list exclusion
	bash tests/toolbelt/test-vendored-paths.sh

# The APIv4 contract rule reaches oxlint through its alpha jsPlugins bridge;
# only a run through the pinned toolchain proves the rule still fires.
test-ckeslint: $(OXLINT_STAMP) $(SCENARIO_YAML_STAMP) ## ckeslint: APIv4 contract rule and --core over fixtures
	bash tests/toolbelt/test-ckeslint.sh

test-ckcoverage: $(SCENARIO_YAML_STAMP) ## ckcoverage temporary logs, coverage floor, tests opt-out
	bash tests/toolbelt/test-ckcoverage.sh

# A doctor that cannot fail is worse than none: it reports a healthy host while
# the prerequisite it was written for is absent. The fixtures shadow PATH so
# every verdict is exercised against a host that really lacks the tool.
test-doctor: ## doctor verdicts against synthesised hosts
	bash tests/toolbelt/test-doctor.sh

# A lock that installs is not a lock that runs: resolved against a newer PHP
# than the images ship, a tool tree can pick a transitive that dies at startup
# on an 8.1/8.2 image. No build catches that — this does, without Docker.
test-tool-locks: ## Tool lockfiles: in sync, and resolved against the PHP floor
	bash tests/toolbelt/test-tool-locks.sh

# Two jobs of one run sharing a compose project means the second one's
# `down -v` kills the first one's containers. Only reproducible where jobs
# share a Docker daemon, i.e. never on the runner this suite runs on.
test-compose-isolation: $(SCENARIO_YAML_STAMP) ## Per-job compose project names in the workflows
	bash tests/parity/test-compose-project-isolation.sh

# A group missing the caller workflow, the image or the leg cancels jobs that
# are not its own copy in a newer run, which Actions only shows as cancelled.
test-concurrency-groups: $(SCENARIO_YAML_STAMP) ## Extension CI concurrency groups name the caller workflow, image and leg
	bash tests/parity/test-concurrency-groups.sh

test-matrix-legs: $(SCENARIO_YAML_STAMP) ## Extension CI matrix legs: own project and artifact, compat and browser legs as written
	bash tests/parity/test-matrix-legs.sh

# Without the sibling override a stack-booting job cannot install an extension
# whose <requires> names a sibling the registry does not know.
test-sibling-wiring: $(SCENARIO_YAML_STAMP) ## Stack-booting jobs reach the private-dependency steps
	bash tests/parity/test-sibling-wiring.sh

test-dist-build-wiring: $(SCENARIO_YAML_STAMP) ## Jobs that run the suite build the policy.dist.build output first
	bash tests/parity/test-dist-build-wiring.sh

# The runner creates only the workspace root; a step in a subdirectory before
# the checkout fails on every fresh runner and passes on one with a stale tree.
test-pre-checkout-workdir: $(SCENARIO_YAML_STAMP) ## No workflow step runs in a workspace subdirectory before the checkout
	bash tests/parity/test-pre-checkout-workdir.sh

test-composer-auth: ## Only the images' own composer gets the composer_auth build secret
	bash tests/parity/test-composer-auth.sh

test-image-wiring: $(SCENARIO_YAML_STAMP) ## Stack images reach compose only through the policy.civicrm resolver
	bash tests/parity/test-image-wiring.sh

test-promote-scanned: $(SCENARIO_YAML_STAMP) ## Every image promote job waits for the scan of its own candidates
	bash tests/parity/test-promote-scanned.sh

test-promote-digest: ## Scan resolves each candidate digest once; promote publishes exactly that digest
	bash tests/parity/test-promote-digest.sh

test-stack-image: ## stack-image.sh: policy.civicrm or the image input, never both
	bash tests/parity/test-stack-image.sh

test-gitlab-mr-jobs: $(SCENARIO_YAML_STAMP) ## Every shared GitLab job runs in merge request pipelines
	bash tests/parity/test-gitlab-mr-jobs.sh

test-sibling-checkout: ## Sibling clones land in one directory per extension key
	bash tests/parity/test-sibling-checkout.sh

test-sibling-vendor: ## Siblings with runtime packages get vendor/ built before the mount
	bash tests/parity/test-sibling-vendor.sh

test-sibling-build: $(SCENARIO_YAML_STAMP) ## Siblings declaring policy.dist.build get built before the mount
	bash tests/parity/test-sibling-build.sh

test-database-matrix: $(SCENARIO_YAML_STAMP) ## Supported database images gate standalone promotion and bound policy.database
	bash tests/parity/test-database-matrix.sh

test-compose-config: ## Every shipped compose file passes `docker compose config`
	bash tests/parity/test-compose-config.sh

# Browser jobs on one self-hosted host share its ports; a fixed one fails the
# second job. Needs node and the docker CLI, like test-compose-config.
test-playwright-port: $(SCENARIO_YAML_STAMP) ## The browser job publishes a free port and points the site URL at it
	bash tests/parity/test-playwright-port.sh

test-demo-basic-auth: ## Demo smoke test delegates long Basic Auth credentials to curl
	bash tests/parity/test-demo-basic-auth.sh

test-release-retag: ## Release aliases preserve the verified image digest; major tags only for the newest patch
	bash tests/parity/test-release-retag.sh

test-release-move-major-tag: ## Major tag push: App token required, never on disk, idempotent, never backwards
	bash tests/parity/test-release-move-major-tag.sh

test-release-publish-flags: ## Extension releases: pre-release flag, Latest only for the highest plain version
	bash tests/parity/test-release-publish-flags.sh

test-release-steps: $(SCENARIO_YAML_STAMP) ## Extension releases: working_directory, same-repository closure, pins and staging steps as written
	bash tests/parity/test-release-steps.sh

release: ## Cut a release: VERSION=X.Y.Z [APPLY=1] — every pre-flight check, then the tag
	@[ -n "$(VERSION)" ] || { echo "VERSION=X.Y.Z is required" >&2; exit 1; }
	bash scripts/release.sh $(VERSION) $(if $(APPLY),--apply)

test-ci-gates-step: $(SCENARIO_YAML_STAMP) ## Extension CI: the ck ci step as written, over a fake docker
	bash tests/parity/test-ci-gates-step.sh

test-release-script: ## Release pre-flight: argument, branch, sync, tag, image gate, dry run vs --apply
	bash tests/parity/test-release-script.sh

test-release-verify-images: ## Release image check: standalone mandatory, CMS drift warns and waits, catch-up re-run
	bash tests/parity/test-release-verify-images.sh

test-shell-portability: ## Shell portability lint accepts portable edits and rejects BSD-only sed -i
	bash tests/parity/test-shell-portability.sh

test-install-trivy: ## Trivy installer architecture selection and release checksum pins
	bash tests/parity/test-install-trivy.sh

test-resolve-civicrm-stable: ## The image matrix always builds the phpstan catalog's CiviCRM minor
	bash tests/parity/test-resolve-civicrm-stable.sh

test-cms-families: $(SCENARIO_YAML_STAMP) ## Build CMS Images skips exactly the families its skip input names
	bash tests/parity/test-cms-families.sh

test-resolve-civicrm-git: ## The git-built images track master and the newest release branch
	bash tests/parity/test-resolve-civicrm-git.sh

test-changelog: ## Changelog gate: grammar, release mode and section extraction
	bash tests/parity/test-changelog-check.sh

test-extension-docs: ## extension-docs.py: a site per language with fallback, nav from frontmatter
	CK_ZENSICAL_VERSION=$(CK_ZENSICAL_VERSION) bash tests/extension-docs/test-extension-docs.sh

# --- static checks -----------------------------------------------------------

lint: lint-shell lint-shell-portability lint-actions lint-php lint-schema lint-changelog docs ## Every static check CI runs

lint-shell: $(SHELLCHECK) ## shellcheck (pinned) over every tracked or untracked, non-ignored shell file
	@files=$$($(SHELL_FILES)) ; \
	  $(call require_nonempty,$$files,shell) ; \
	  printf '%s\n' "$$files" | xargs $(SHELLCHECK) -S style ; \
	  echo "shellcheck clean ($$(printf '%s\n' "$$files" | wc -l) files)"

lint-shell-portability: ## Reject host-incompatible BSD sed in-place syntax
	@files=$$($(SHELL_FILES)) ; \
	  $(call require_nonempty,$$files,shell) ; \
	  scripts/lint-shell-portability.sh $$files

# actionlint reads workflows as YAML+shell; zizmor reads them as a threat model
# — unpinned actions, expression injection into run blocks, tokens wider than
# the job needs. Exemptions and their reasons live in zizmor.yml.
lint-actions: $(CACHE)/actionlint ## actionlint + zizmor over the workflows
	$(CACHE)/actionlint -color
	$(call pyrun_pinned,zizmor,$(CK_ZIZMOR_VERSION)) --no-online-audits --config zizmor.yml \
	  .github/workflows .github/actions scaffold/template/extension/.github/workflows

# The release job runs the same script with --release, so a tag whose version
# has no section fails before anything is pushed. Structure is checked here so
# a malformed file is a red PR, not a red release.
lint-changelog: ## CHANGELOG.md follows the Keep a Changelog grammar
	.github/scripts/changelog-check.sh --lint

# The site is built from docs/ by zensical.toml; --strict fails on a link to a
# missing page or anchor. No --clean: it empties .cache/, this Makefile's tool cache.
docs: ## Build the documentation site into site/, failing on broken links
	$(call pyrun_pinned,zensical,$(CK_ZENSICAL_VERSION)) build --strict

docs-serve: ## Serve the documentation site locally with live reload
	$(call pyrun_pinned,zensical,$(CK_ZENSICAL_VERSION)) serve

docs-a11y: docs ## WCAG 2.1 AA checks of the site in light and dark mode (needs npm ci and Playwright chromium)
	npx playwright test -c playwright.docs.config.ts

# Advisory token-level clone report, not part of `lint`. Duplicated on purpose:
# check run() skeletons, parallel test fixtures, clones across composer packages.
dupcheck: ## Copy-paste clone report (jscpd), advisory
	bunx jscpd@$(CK_JSCPD_VERSION) --config .jscpd.json

lint-php: ## php -l over every tracked or untracked, non-ignored PHP file
	@files=$$($(PHP_FILES)) ; \
	  $(call require_nonempty,$$files,PHP) ; \
	  rc=0 ; \
	  while IFS= read -r f; do php -l "$$f" >/dev/null || rc=1; done <<< "$$files" ; \
	  [ "$$rc" = 0 ] && echo "php syntax clean ($$(printf '%s\n' "$$files" | wc -l) files)"

# The schemas are published contracts, so validate both the schemas themselves
# and every repository-owned example/fixture. Keeping fixtures in this gate is
# important: runtime tests must not be the first place an external profile or
# scenario discovers that it drifted from its canonical format.
lint-schema: ## Validate the profile/scenario schemas and every repository-owned instance
	$(call pyrun,check-jsonschema) --check-metaschema \
	  packages/civicrm-profile-schema/profile.schema.json \
	  packages/civikitchen-scenario-schema/scenario.schema.json
	$(call pyrun,check-jsonschema) \
	  --schemafile packages/civicrm-profile-schema/profile.schema.json \
	  docker/profiles/*/profile.json \
	  tests/images/fixtures/external-profile/*/profile.json
	$(call pyrun,check-jsonschema) \
	  --schemafile packages/civikitchen-scenario-schema/scenario.schema.json \
	  examples/scenario/civikitchen.yaml \
	  tests/scenario/civikitchen.yaml

# --- the slow loop (needs Docker) --------------------------------------------

build: ## Build the standalone image locally; CIVICRM_VERSION=<release>, or CIVICRM_SOURCE=git CIVICRM_VERSION=master
	docker build -f docker/standalone/Dockerfile \
	    $(if $(CIVICRM_VERSION),--build-arg CIVICRM_VERSION=$(CIVICRM_VERSION)) \
	    $(if $(CIVICRM_SOURCE),--build-arg CIVICRM_SOURCE=$(CIVICRM_SOURCE)) \
	    -t civikitchen:standalone$(if $(CIVICRM_VERSION),-$(CIVICRM_VERSION)) .

e2e-ckcreate: ## A fresh ckcreate scaffold passes ck ci in its dev stack (Docker; IMAGE=, default :v1)
	bash tests/ckcreate/e2e-ckcreate.sh $(or $(IMAGE),ghcr.io/civico-de/civikitchen:v1)

test-images: ## Boot tests + dev-tool tests against the published images (~1 h)
	bash tests/images/run-local.sh

e2e: ## Playwright smoke tests against a running compose stack
	npm run test:e2e

# --- fetched inputs ----------------------------------------------------------

$(OXLINT_STAMP): toolbelt/oxlint/package.json toolbelt/oxlint/package-lock.json
	npm ci --prefix toolbelt/oxlint --no-audit --no-fund --loglevel=error
	@touch $(OXLINT_STAMP)

$(SCENARIO_YAML_STAMP): packages/civikitchen-scenario-schema/composer.json packages/civikitchen-scenario-schema/composer.lock
	composer install --no-interaction --no-progress --working-dir=packages/civikitchen-scenario-schema
	@touch $(SCENARIO_YAML_STAMP)

tools: $(PHPUNIT) $(MAGO) $(CACHE)/actionlint $(SHELLCHECK) $(SCENARIO_YAML_STAMP) $(OXLINT_STAMP) ## Pre-fetch the pinned tools and YAML parser

$(SHELLCHECK):
	@mkdir -p $(CACHE)
	@[ -n "$(SHELLCHECK_SHA256)" ] || { \
	  echo "no shellcheck hash pinned for $(SHELLCHECK_PLATFORM) — add CK_SHELLCHECK_SHA256_$(subst .,_,$(SHELLCHECK_PLATFORM)) to the Makefile" >&2; exit 1; }
	curl -fsSLo $@.tar.xz \
	  "https://github.com/koalaman/shellcheck/releases/download/v$(CK_SHELLCHECK_VERSION)/shellcheck-v$(CK_SHELLCHECK_VERSION).$(SHELLCHECK_PLATFORM).tar.xz"
	echo "$(SHELLCHECK_SHA256)  $@.tar.xz" | sha256sum -c --strict -
	tar -xJf $@.tar.xz -C $(CACHE) --strip-components=1 shellcheck-v$(CK_SHELLCHECK_VERSION)/shellcheck
	mv $(CACHE)/shellcheck $@
	rm $@.tar.xz

$(MAGO):
	@mkdir -p $(CACHE)
	@[ -n "$(MAGO_SHA256)" ] && [ -n "$(MAGO_TRIPLE)" ] || { \
	  echo "no mago hash pinned for $(SHELLCHECK_PLATFORM) — add CK_MAGO_SHA256_$(subst .,_,$(SHELLCHECK_PLATFORM)) to toolbelt/versions.env" >&2; exit 1; }
	curl -fsSLo $@.tar.gz \
	  "https://github.com/carthage-software/mago/releases/download/$(CK_MAGO_VERSION)/mago-$(CK_MAGO_VERSION)-$(MAGO_TRIPLE).tar.gz"
	echo "$(MAGO_SHA256)  $@.tar.gz" | sha256sum -c --strict -
	tar -xzf $@.tar.gz -C $(CACHE) --strip-components=1 mago-$(CK_MAGO_VERSION)-$(MAGO_TRIPLE)/mago
	mv $(CACHE)/mago $@
	rm $@.tar.gz

$(PHPUNIT):
	@mkdir -p $(CACHE)
	curl -fsSLo $@ "https://phar.phpunit.de/phpunit-$(CK_PHPUNIT_VERSION).phar"
	echo "$(CK_PHPUNIT_SHA256)  $@" | sha256sum -c --strict -

$(CACHE)/actionlint:
	@mkdir -p $(CACHE)
	curl -fsSL -o $(CACHE)/download-actionlint.bash \
	  "https://raw.githubusercontent.com/rhysd/actionlint/v$(CK_ACTIONLINT_VERSION)/scripts/download-actionlint.bash"
	echo "$(CK_ACTIONLINT_INSTALLER_SHA256)  $(CACHE)/download-actionlint.bash" | sha256sum -c --strict -
	bash $(CACHE)/download-actionlint.bash $(CK_ACTIONLINT_VERSION) $(CACHE)

# All four catalogs must pin the SAME release, or the drift gates would each
# test a different core and none of them would say so. The namespace generator
# scans the whole tree (CRM/, Civi/, ext/), hence a source tree rather than
# single files.
$(CORE):
	@set -eu; \
	ver="$(CORE_VERSION)"; \
	[ -n "$$ver" ] || { echo "could not read HookCatalog::CORE_VERSION (is php installed?)" >&2; exit 1; }; \
	nsver=$$(php -r 'require "toolbelt/phpstan/src/CoreNamespaceCatalog.php"; echo CiviKitchen\PHPStan\CoreNamespaceCatalog::CORE_VERSION;'); \
	apiver=$$(php -r 'require "toolbelt/phpstan/src/Api4Catalog.php"; echo CiviKitchen\PHPStan\Api4Catalog::CORE_VERSION;'); \
	schemaver=$$(php -r 'require "toolbelt/phpstan/src/SchemaCatalog.php"; echo CiviKitchen\PHPStan\SchemaCatalog::CORE_VERSION;'); \
	if [ "$$ver" != "$$nsver" ] || [ "$$ver" != "$$apiver" ] || [ "$$ver" != "$$schemaver" ]; then \
	  echo "catalog pins disagree: hooks=$$ver namespaces=$$nsver api4=$$apiver schema=$$schemaver — regenerate them from one release" >&2; \
	  exit 1; \
	fi; \
	echo "pinned CiviCRM $$ver"; \
	mkdir -p $(CACHE); \
	curl -fsSLo $(CORE).tar.gz \
	  "https://github.com/civicrm/civicrm-core/archive/refs/tags/$$ver.tar.gz"; \
	mkdir -p $(CORE).tmp; \
	tar -xzf $(CORE).tar.gz -C $(CORE).tmp --strip-components=1; \
	rm $(CORE).tar.gz; \
	mv $(CORE).tmp $(CORE)

clean: ## Remove .cache/ (fetched phars and the CiviCRM source tree)
	rm -rf $(CACHE)
