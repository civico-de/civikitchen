#!/usr/bin/env bash
# Resolve upstream stable plus the extra standalone minors (versions.env, or
# $EXTRA_MINORS for one run, plus the phpstan catalog's) to exact releases.
# Emits stable/minor/standalone_versions.
set -euo pipefail

if [ -z "${EXTRA_MINORS:-}" ]; then
  # shellcheck source=/dev/null
  source toolbelt/versions.env
  EXTRA_MINORS="${CK_STANDALONE_EXTRA_MINORS:-}"
fi

# The phpstan APIv4 catalog's minor is always built: the standalone boot test
# checks the catalog against live getFields only on that release.
CATALOG_MINOR=$(sed -n "s/^ *public const CORE_VERSION = '\([0-9]*\.[0-9]*\)\.[0-9]*';\$/\1/p" \
  toolbelt/phpstan/src/Api4Catalog.php)
[[ "${CATALOG_MINOR}" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "no CORE_VERSION in Api4Catalog.php" >&2; exit 1; }
EXTRA_MINORS="${EXTRA_MINORS:+${EXTRA_MINORS},}${CATALOG_MINOR}"

STABLE=$(curl -fsS https://latest.civicrm.org/stable.php)
echo "upstream stable: ${STABLE}"
if ! [[ "${STABLE}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "unexpected version string from stable.php: '${STABLE}'" >&2
  exit 1
fi
MINOR=${STABLE%.*}
# Exact versions: each one names the release tarball the standalone image installs.
VERSIONS_ARR=("${STABLE}")
IFS=',' read -ra EXTRAS <<< "${EXTRA_MINORS:-}"
for v in "${EXTRAS[@]}"; do
  v="${v// /}"
  [ -z "${v}" ] && continue
  if ! [[ "${v}" =~ ^[0-9]+\.[0-9]+$ ]]; then
    echo "invalid extra standalone minor: '${v}'" >&2
    exit 1
  fi
  [ "${v}" = "${MINOR}" ] && continue
  [[ ",${SEEN:-}," == *",${v},"* ]] && continue
  SEEN="${SEEN:-},${v}"
  exact=$(curl -fsS https://latest.civicrm.org/versions.json \
    | jq -re --arg m "${v}" '.[$m].releases[-1].version')
  if ! [[ "${exact}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "could not resolve minor ${v} to a release via versions.json" >&2
    exit 1
  fi
  VERSIONS_ARR+=("${exact}")
done
# Fail here on a missing tarball, not later in the Dockerfile.
for v in "${VERSIONS_ARR[@]}"; do
  curl -fsIL --retry 2 -o /dev/null \
      "https://download.civicrm.org/civicrm-${v}-standalone.tar.gz" \
    || { echo "no standalone tarball for ${v} on download.civicrm.org" >&2; exit 1; }
done
VERSIONS=$(printf '"%s",' "${VERSIONS_ARR[@]}")
VERSIONS="[${VERSIONS%,}]"
echo "standalone versions: ${VERSIONS}"
{
  echo "stable=${STABLE}"
  echo "minor=${MINOR}"
  echo "standalone_versions=${VERSIONS}"
} >> "$GITHUB_OUTPUT"
