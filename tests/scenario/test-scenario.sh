#!/bin/bash
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
scenario="$root/examples/scenario/civikitchen.yaml"
mutate="$root/tests/scenario/mutate.php"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

"$root/toolbelt/bin/ck" scenario validate "$scenario" >/dev/null
"$root/toolbelt/bin/ck" config validate "$scenario" >/dev/null
(cd "$(dirname "$scenario")" && "$root/toolbelt/bin/ck" scenario validate >/dev/null)
cp "$scenario" "$work/noncanonical.yml"
if "$root/toolbelt/bin/ck" scenario validate "$work/noncanonical.yml" >/dev/null 2>&1; then
  echo "scenario accepted noncanonical .yml filename" >&2; exit 1
fi
printf '%s\n' '{"version":1}' > "$work/json-disguised-as-yaml.yaml"
if "$root/toolbelt/bin/ck" scenario validate "$work/json-disguised-as-yaml.yaml" >/dev/null 2>&1; then
  echo "scenario accepted JSON syntax disguised as YAML" >&2; exit 1
fi
printf '%s\n' 'version: 1' 'version: 1' > "$work/duplicate-key.yaml"
if "$root/toolbelt/bin/ck" scenario validate "$work/duplicate-key.yaml" >/dev/null 2>&1; then
  echo "scenario accepted duplicate YAML keys" >&2; exit 1
fi
printf '%s\n' 'version: !php/object "O:8:\"stdClass\":0:{}"' > "$work/object-tag.yaml"
if "$root/toolbelt/bin/ck" scenario validate "$work/object-tag.yaml" >/dev/null 2>&1; then
  echo "scenario accepted an object-producing YAML tag" >&2; exit 1
fi
printf '%s\n' 'version: 1' 'policy:' '  license: MIT' > "$work/policy-only.yaml"
"$root/toolbelt/bin/ck" config validate "$work/policy-only.yaml" >/dev/null
printf '%s\n' 'version: 1' > "$work/empty-config.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/empty-config.yaml" >/dev/null 2>&1; then
  echo "configuration accepted neither policy nor scenario" >&2; exit 1
fi
printf '%s\n' 'version: 1' 'policy:' '  vendor:' '    surprise: true' > "$work/invalid-oneof.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/invalid-oneof.yaml" >/dev/null 2>&1; then
  echo "configuration accepted a vendor value outside every oneOf alternative" >&2; exit 1
fi
if "$root/toolbelt/bin/ck" scenario plan "$work/policy-only.yaml" >/dev/null 2>&1; then
  echo "scenario plan accepted a policy-only configuration" >&2; exit 1
fi
# policy.database and the scenario's database name one server.
mkdir -p "$work/database"
php "$mutate" "$scenario" "$work/database/civikitchen.yaml" database mariadb:11.4
"$root/toolbelt/bin/ck" scenario validate "$work/database/civikitchen.yaml" >/dev/null \
  || { echo "scenario rejected the database policy.database names" >&2; exit 1; }
php "$mutate" "$scenario" "$work/database/civikitchen.yaml" database mariadb:11.4 "mariadb:11.4.5@sha256:$(printf a%.0s {1..64})"
"$root/toolbelt/bin/ck" scenario validate "$work/database/civikitchen.yaml" >/dev/null \
  || { echo "scenario rejected a patch and digest pin of the policy.database image" >&2; exit 1; }
php "$mutate" "$scenario" "$work/database/civikitchen.yaml" database mariadb:10.11
if out=$("$root/toolbelt/bin/ck" scenario validate "$work/database/civikitchen.yaml" 2>&1); then
  echo "scenario accepted a database other than policy.database" >&2; exit 1
fi
grep -qF 'policy.database is mariadb:10.11, but scenario.database.image is mariadb:11.4' <<<"$out" \
  || { echo "scenario did not name the database mismatch (got: $out)" >&2; exit 1; }
printf '%s\n' 'version: 1' 'profile_definitions: {}' > "$work/embedded-profiles.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/embedded-profiles.yaml" >/dev/null 2>&1; then
  echo "configuration accepted embedded profile definitions" >&2; exit 1
fi
printf '%s\n' \
  'version: 1' 'policy:' '  extension_sources:' \
  '    - key: org.example.dep' "      version: '^1.0'" '      url: https://user:secret@example.org/dep.zip?token=x' \
  '      sha256: deadbeef' '      reason: invalid fixture' > "$work/unsafe-source.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/unsafe-source.yaml" >/dev/null 2>&1; then
  echo "configuration accepted an unsafe or non-digest-pinned extension source" >&2; exit 1
fi
printf '%s\n' \
  'version: 1' 'policy:' '  extension_sources:' \
  '    - key: org.example.dep' "      version: '^1.0'" '      url: https://example.org/one.zip' \
  "      sha256: $(printf a%.0s {1..64})" '      reason: first' \
  '    - key: org.example.dep' "      version: '^2.0'" '      url: https://example.org/two.zip' \
  "      sha256: $(printf b%.0s {1..64})" '      reason: duplicate' > "$work/duplicate-source.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/duplicate-source.yaml" >/dev/null 2>&1; then
  echo "configuration accepted duplicate extension source keys" >&2; exit 1
fi
printf '%s\n' \
  'version: 1' 'policy:' '  extension_sources:' \
  '    - key: org.example.dep' "      version: 'not a constraint ???'" \
  '      url: https://example.org/dep.zip' \
  "      sha256: $(printf a%.0s {1..64})" '      reason: invalid constraint' > "$work/invalid-version-constraint.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/invalid-version-constraint.yaml" >/dev/null 2>&1; then
  echo "configuration accepted an invalid Composer version constraint" >&2; exit 1
fi
printf '%s\n' \
  'version: 1' 'policy:' '  extension_sources:' \
  '    - key: org.example.dep' "      version: '^1.0'" \
  '      release:' '        repository: example-org/dep' '        tag: v1.0.0' '        asset: dep-1.0.0.zip' \
  "      sha256: $(printf a%.0s {1..64})" '      reason: private repository' > "$work/release-source.yaml"
"$root/toolbelt/bin/ck" config validate "$work/release-source.yaml" >/dev/null \
  || { echo "configuration rejected a valid staged-release pin" >&2; exit 1; }
printf '%s\n' \
  'version: 1' 'policy:' '  extension_sources:' \
  '    - key: org.example.dep' "      version: '^1.0'" '      url: https://example.org/dep.zip' \
  '      release:' '        repository: example-org/dep' '        tag: v1.0.0' '        asset: dep-1.0.0.zip' \
  "      sha256: $(printf a%.0s {1..64})" '      reason: two ways to fetch one thing' > "$work/both-sources.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/both-sources.yaml" >/dev/null 2>&1; then
  echo "configuration accepted an extension source with both a url and a release" >&2; exit 1
fi
printf '%s\n' \
  'version: 1' 'policy:' '  extension_sources:' \
  '    - key: org.example.dep' "      version: '^1.0'" \
  "      sha256: $(printf a%.0s {1..64})" '      reason: no way to fetch it' > "$work/no-source.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/no-source.yaml" >/dev/null 2>&1; then
  echo "configuration accepted an extension source with neither a url nor a release" >&2; exit 1
fi
printf '%s\n' \
  'version: 1' 'policy:' '  extension_sources:' \
  '    - key: org.example.dep' "      version: '^1.0'" \
  '      release:' '        repository: example-org/dep' '        tag: v1.0.0' "        asset: 'dep 1.0.0.zip'" \
  "      sha256: $(printf a%.0s {1..64})" '      reason: a space breaks the line protocol' > "$work/spaced-asset.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/spaced-asset.yaml" >/dev/null 2>&1; then
  echo "configuration accepted a release asset name containing a space" >&2; exit 1
fi
printf '%s\n' 'version: 1' 'policy:' '  release:' '    mode: none' '    reason: |' '      first line' '      second line' > "$work/multiline-reason.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/multiline-reason.yaml" >/dev/null 2>&1; then
  echo "configuration accepted a multiline reason that cannot use the line protocol" >&2; exit 1
fi
printf '%s\n' 'version: 1' 'policy:' '  lifecycle:' '    log_ignore:' '      - pattern: PHP -- Fatal' '        reason: fixture' > "$work/delimiter-value.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/delimiter-value.yaml" >/dev/null 2>&1; then
  echo "configuration accepted the reserved line-protocol delimiter in a value" >&2; exit 1
fi
printf '%s\n' 'version: 1' 'policy:' '  untagged_versions:' '    - version: 1.1.0' > "$work/reasonless-untagged-version.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/reasonless-untagged-version.yaml" >/dev/null 2>&1; then
  echo "configuration accepted an untagged_versions entry without a reason" >&2; exit 1
fi
printf '%s\n' 'version: 1' 'policy:' '  untagged_versions:' '    - version: 1.1.0' '      reason: bump re-scoped before release' > "$work/untagged-version.yaml"
"$root/toolbelt/bin/ck" config validate "$work/untagged-version.yaml" >/dev/null \
  || { echo "configuration rejected a valid untagged_versions entry" >&2; exit 1; }
printf '%s\n' 'version: 1' 'policy:' '  untagged_versions:' '    - version: v1.1.0' '      reason: bump re-scoped before release' > "$work/v-prefixed-untagged-version.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/v-prefixed-untagged-version.yaml" >/dev/null 2>&1; then
  echo "configuration accepted a v-prefixed untagged_versions entry" >&2; exit 1
fi
printf '%s\n' 'version: 1' 'policy:' '  release:' '    mode: none' "    reason: ' '" > "$work/blank-reason.yaml"
if "$root/toolbelt/bin/ck" config validate "$work/blank-reason.yaml" >/dev/null 2>&1; then
  echo "configuration accepted a whitespace-only reason" >&2; exit 1
fi
php -r 'file_put_contents($argv[1], str_repeat("#", 1024 * 1024 + 1));' "$work/oversized.yaml"
if "$root/toolbelt/bin/ck" scenario validate "$work/oversized.yaml" >/dev/null 2>&1; then
  echo "scenario accepted YAML beyond the configuration size limit" >&2; exit 1
fi
"$root/toolbelt/bin/ck" scenario plan "$scenario" > "$work/plan.json"
"$root/toolbelt/bin/ck" scenario compose "$scenario" > "$work/compose.json"
"$root/toolbelt/bin/ck" scenario commands "$scenario" > "$work/commands"
"$root/toolbelt/bin/ck" scenario materialize "$scenario" "$work/materialized.json" >/dev/null
php -r '
  $p=json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  $c=json_decode(file_get_contents($argv[2]), TRUE, 512, JSON_THROW_ON_ERROR);
  if ($p["credentials_output"] !== "file") exit(1);
  if ($c["services"]["db"]["image"] !== "mariadb:11.4") exit(1);
  if (!str_starts_with($c["services"]["db"]["healthcheck"]["test"][1], "mariadb-admin ping ")) exit(1);
  if (!str_contains($c["services"]["db"]["healthcheck"]["test"][1], "|| mysqladmin ping ")) exit(1);
  if ($c["services"]["app"]["environment"]["CIVIKITCHEN_DEFAULT_LOCALE"] !== "de_DE") exit(1);
  if ($c["services"]["app"]["environment"]["CIVIKITCHEN_DEMO_USER"] !== "admin") exit(1);
  if ($c["services"]["app"]["volumes"][0]["read_only"] !== true) exit(1);
  if ($c["services"]["app"]["volumes"][0]["target"] !== "/civikitchen-extension") exit(1);
  if ($c["services"]["app"]["environment"]["CK_CREDENTIALS_FILE"] !== "/tmp/civikitchen-api-credentials.txt") exit(1);
' "$work/plan.json" "$work/compose.json"
cmp "$work/compose.json" "$work/materialized.json"
grep -qx 'ck lint --all' "$work/commands"
grep -qx 'ck lifecycle' "$work/commands"

php "$mutate" "$scenario" "$work/invalid.yaml" invalid-default-locale
if "$root/toolbelt/bin/ck" scenario validate "$work/invalid.yaml" >/dev/null 2>&1; then
  echo "scenario accepted a default locale absent from locales" >&2; exit 1
fi

php "$mutate" "$scenario" "$work/derived-url.yaml" derived-url
derived_url=$("$root/toolbelt/bin/ck" scenario plan "$work/derived-url.yaml" | php -r '$v=json_decode(stream_get_contents(STDIN), TRUE); echo $v["site_url"];')
[ "$derived_url" = http://localhost:8290 ] || { echo "site_url did not derive from http_port" >&2; exit 1; }

php "$mutate" "$scenario" "$work/bad-port.yaml" invalid-port
if "$root/toolbelt/bin/ck" scenario validate "$work/bad-port.yaml" >/dev/null 2>&1; then
  echo "scenario accepted an invalid TCP port" >&2; exit 1
fi

php "$mutate" "$scenario" "$work/non-http-url.yaml" site-url file:///tmp/not-a-site
if "$root/toolbelt/bin/ck" scenario validate "$work/non-http-url.yaml" >/dev/null 2>&1; then
  echo "scenario accepted a non-HTTP site URL" >&2; exit 1
fi

for bad_url in 'https://localhost:8080' 'http://localhost:8080/subdir' 'http://[::1]:8080'; do
  php "$mutate" "$scenario" "$work/bad-site-url.yaml" site-url "$bad_url"
  if "$root/toolbelt/bin/ck" scenario validate "$work/bad-site-url.yaml" >/dev/null 2>&1; then
    echo "scenario accepted unsupported site URL: $bad_url" >&2; exit 1
  fi
done

for loopback_host in localhost localhost. 127.0.0.1 127.0.0.2; do
  php "$mutate" "$scenario" "$work/mismatched-url-port.yaml" loopback-port "$loopback_host"
  if "$root/toolbelt/bin/ck" scenario validate "$work/mismatched-url-port.yaml" >/dev/null 2>&1; then
    echo "scenario accepted loopback host $loopback_host whose port differs from http_port" >&2; exit 1
  fi
done

# Real CiviCRM keys are commonly reverse-domain identifiers while <file> is a
# shorter PHP symbol prefix. The scenario contract follows the root extension
# key and must not conflate these two independent identifiers.
mkdir -p "$work/dotted-extension"
printf '%s\n' '<extension key="org.example.demo" type="module"><file>demo</file></extension>' \
  > "$work/dotted-extension/info.xml"
php "$mutate" "$scenario" "$work/dotted.yaml" dotted-extension "$work/dotted-extension"
"$root/toolbelt/bin/ck" scenario validate "$work/dotted.yaml" >/dev/null
dotted_target=$("$root/toolbelt/bin/ck" scenario compose "$work/dotted.yaml" \
  | php -r '$v=json_decode(stream_get_contents(STDIN), TRUE); echo $v["services"]["app"]["volumes"][0]["target"];')
[ "$dotted_target" = /civikitchen-extension ] \
  || { echo "dotted extension key did not reach the mount target" >&2; exit 1; }

runtime="$root/tests/scenario/civikitchen.yaml"
"$root/toolbelt/bin/ck" scenario validate "$runtime" >/dev/null
"$root/toolbelt/bin/ck" scenario compose "$runtime" > "$work/runtime-compose.json"
php -r '
  $c=json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  if ($c["services"]["app"]["environment"]["CIVIKITCHEN_TRUST_EXTERNAL_PROFILES"] !== "1") exit(1);
  if ($c["services"]["app"]["environment"]["CIVIKITCHEN_DEMO_USER"] !== "admin") exit(1);
  if ($c["services"]["app"]["volumes"][1]["read_only"] !== true) exit(1);
' "$work/runtime-compose.json"

php "$mutate" "$runtime" "$work/untrusted.yaml" untrusted-profiles
if "$root/toolbelt/bin/ck" scenario validate "$work/untrusted.yaml" >/dev/null 2>&1; then
  echo "scenario accepted external executable profiles without trust" >&2; exit 1
fi

php "$mutate" "$runtime" "$work/untrusted-extension.yaml" untrusted-extension
if "$root/toolbelt/bin/ck" scenario validate "$work/untrusted-extension.yaml" >/dev/null 2>&1; then
  echo "scenario accepted an external extension path without trust" >&2; exit 1
fi

php "$mutate" "$runtime" "$work/secret-log.yaml" secret-logging-without-consent
if "$root/toolbelt/bin/ck" scenario validate "$work/secret-log.yaml" >/dev/null 2>&1; then
  echo "scenario accepted credential logging without explicit consent" >&2; exit 1
fi

# The scenario smoke jobs execute the host-side YAML renderer before starting
# a container. A clean GitHub runner therefore needs the locked parser just as
# a fresh local checkout does; cached vendor trees must never make CI green.
for leg in build-dev-images:scenario-test-standalone build-cms-images:scenario-test-buildkit; do
  job=${leg#*:}
  steps=$(awk -v job="$job" '
    $0 == "  " job ":" { inside=1; next }
    inside && /^  [a-zA-Z0-9_-]+:/ { exit }
    inside { print }
  ' "$root/.github/workflows/${leg%%:*}.yml")
  grep -Fq 'composer install --no-interaction --no-progress --working-dir=packages/civikitchen-scenario-schema' <<<"$steps" \
    || { echo "$job does not install the locked scenario YAML parser" >&2; exit 1; }
done

echo "scenario tests passed"
