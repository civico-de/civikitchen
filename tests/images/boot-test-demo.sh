#!/bin/bash
# Boot test for the single-container DEMO images (civikitchen:*-demo). Unlike
# tests/images/boot-test.sh (which spins up an external MariaDB sidecar), the
# demo image is self-contained: `docker run` it, wait for the HEALTHCHECK, and
# assert the baked site + embedded DB come up. This guards the build-time bake
# of the embedded DB and the demo entrypoint. An optional second argument
# boots with CIVIKITCHEN_PROFILE=<profile>[,<profile>...] and additionally
# asserts the runtime profile apply worked (each profile's extension
# installed, no credentials in the logs, one credentials line per apiUser even
# when combined profiles share usernames).
#
# Usage:
#   bash tests/images/boot-test-demo.sh <image> [profile[,profile...]]
#   bash tests/images/boot-test-demo.sh civikitchen:drupal10-demo
#   bash tests/images/boot-test-demo.sh civikitchen:drupal10-demo verein
#   bash tests/images/boot-test-demo.sh civikitchen:drupal10-demo verein,mailing
set -euo pipefail

IMAGE="${1:?usage: boot-test-demo.sh <image> [profile]}"
PROFILE="${2:-}"

# Same name-length cap as boot-test.sh: keep container names DNS-label-safe
# (<63 chars) even with a 40-char sha in the CI image tag.
RAW="$(echo "${IMAGE}${PROFILE:+-${PROFILE}}" | tr -c 'a-z0-9' '-')"
SLUG="demotest-$(echo "${RAW}" | cut -c1-32)$(echo "${RAW}" | cksum | cut -d' ' -f1)"
APP="${SLUG}-app"
if [ -n "${PROFILE}" ]; then
    HEALTH_TIMEOUT=900   # profile apply = git clones + seeds at first boot
else
    HEALTH_TIMEOUT=300   # baked site → first boot is fast; allow slack
fi

cleanup() { docker rm -f "${APP}" "${APP}-url" >/dev/null 2>&1 || true; rm -f "${LOGFILE:-}" "${URLPAGE:-}"; }
trap cleanup EXIT

echo "==> boot-test (demo) ${IMAGE}${PROFILE:+ profile=${PROFILE}}"
docker rm -f "${APP}" >/dev/null 2>&1 || true
docker run -d --name "${APP}" \
    ${PROFILE:+-e CIVIKITCHEN_PROFILE=${PROFILE}} \
    "${IMAGE}" >/dev/null

echo "==> waiting for healthy (embedded MariaDB + provisioning)..."
elapsed=0
while :; do
    health=$(docker inspect -f '{{.State.Health.Status}}' "${APP}" 2>/dev/null || echo gone)
    state=$(docker inspect -f '{{.State.Status}}' "${APP}" 2>/dev/null || echo gone)
    [ "${health}" = "healthy" ] && { echo "    healthy after ~${elapsed}s"; break; }
    if [ "${state}" = "exited" ] || [ "${state}" = "gone" ]; then
        echo "!! container ${state} before healthy — last logs:"; docker logs --tail 40 "${APP}" 2>&1 || true
        exit 1
    fi
    if [ "${elapsed}" -ge "${HEALTH_TIMEOUT}" ]; then
        echo "!! not healthy within ${HEALTH_TIMEOUT}s — last logs:"; docker logs --tail 40 "${APP}" 2>&1 || true
        exit 1
    fi
    sleep 5; elapsed=$((elapsed + 5))
done

fail=0
check() { if eval "$2"; then echo "  ✓ $1"; else echo "  ✗ $1"; fail=1; fi; }

# CiviCRM's HTTP API entry differs per CMS: Standalone/Drupal/WP expose the
# clean /civicrm route, but on Joomla CiviCRM has no clean URL — requests go
# through Joomla's component router (index.php?option=com_civicrm&task=...).
# Detect the framework so the api-auth checks below hit the right endpoint.
WEB=/home/buildkit/buildkit/build/site/web
UF=$(docker exec -u buildkit -w "${WEB}" "${APP}" \
    bash -lc 'export PATH=/home/buildkit/buildkit/bin:$PATH; cv ev "echo CIVICRM_UF;" 2>/dev/null' | tr -d '[:space:]' || true)
if [ "${UF}" = Joomla ]; then
    API_URL='http://localhost/index.php?option=com_civicrm&task=civicrm/ajax/api4/Contact/get'
else
    API_URL='http://localhost/civicrm/ajax/api4/Contact/get'
fi

# 1) The site serves 200 (follow the standalone bare-/ -> /civicrm/login redirect).
code=$(docker exec "${APP}" curl -s -o /dev/null -w '%{http_code}' -L http://localhost/ 2>/dev/null || echo 000)
check "site serves HTTP 200 (got ${code})" "[ '${code}' = '200' ]"

# 2) CiviCRM is live against the embedded DB.
ver=$(docker exec -u buildkit -w /home/buildkit/buildkit/build/site/web "${APP}" \
    bash -lc 'export PATH=/home/buildkit/buildkit/bin:$PATH; cv api4 Domain.get +s version 2>/dev/null' \
    | tr -d '[:space:]' || true)
check "CiviCRM responds via cv (Domain version: ${ver:-none})" "echo '${ver}' | grep -q 'version'"

# 3) Demo data is baked in (the -demo civibuild types seed sample contacts).
contacts=$(docker exec -u buildkit -w /home/buildkit/buildkit/build/site/web "${APP}" \
    bash -lc 'export PATH=/home/buildkit/buildkit/bin:$PATH; cv api4 Contact.get +s id 2>/dev/null' \
    | grep -c '"id"' || true)
check "demo data present (${contacts} contacts)" "[ '${contacts:-0}' -gt 1 ]"

# Snapshot the container logs once for the log-based checks below: piping
# `docker logs` straight into `grep -q` dies of SIGPIPE under pipefail when
# grep exits on an early match, turning real matches into false negatives.
LOGFILE="$(mktemp)"
docker logs "${APP}" >"${LOGFILE}" 2>&1

# 4) Embedded DB started clean from the baked data dir (no InnoDB crash recovery).
if grep -qiE 'InnoDB.*(crash recovery|Starting crash recovery|Recovering)' "${LOGFILE}"; then
    echo "  ✗ embedded MariaDB did InnoDB crash recovery (baked data dir not cleanly shut down)"; fail=1
else
    echo "  ✓ embedded MariaDB started clean (no InnoDB recovery)"
fi

# 5+6) Runtime profile apply worked, per profile in the (possibly
# comma-separated) list: its first enabled extension is installed. Across all
# of them: credentials stayed out of the logs, and the credentials file has
# exactly one line per declared apiUser — combined profiles sharing a
# username (e.g. "readonly") must upsert, not duplicate or drop.
if [ -n "${PROFILE}" ]; then
    IFS=',' read -ra PROFILES <<< "${PROFILE}"
    for prof in "${PROFILES[@]}"; do
        ext_key=$(docker exec "${APP}" \
            jq -r '[.dependencies[] | select(.enable)][0].name // empty' \
            "/opt/civikitchen/docker/profiles/${prof}/profile.json" 2>/dev/null || true)
        if [ -n "${ext_key}" ]; then
            status=$(docker exec -u buildkit -w /home/buildkit/buildkit/build/site/web "${APP}" \
                bash -lc "export PATH=/home/buildkit/buildkit/bin:\$PATH; cv api4 Extension.get +w key=${ext_key} +w status=installed +s key 2>/dev/null" || true)
            check "profile extension ${ext_key} installed (${prof})" "echo '${status}' | grep -q '${ext_key}'"
        else
            echo "  ✗ could not read profile.json for '${prof}' from the image"; fail=1
        fi
        while IFS= read -r included; do
            [ -n "${included}" ] || continue
            check "included profile ${included} applied (${prof})" "grep -qF '==> [${included}] profile applied' '${LOGFILE}'"
            # The demo image has no snapshot store: a snapshot profile runs its scripts.
            if docker exec "${APP}" jq -e '.snapshot' "/opt/civikitchen/docker/profiles/${included}/profile.json" >/dev/null 2>&1; then
                check "snapshot profile ${included} ran its scripts (${prof})" \
                    "grep -qF '==> [${included}] snapshot: restored on the standalone image only' '${LOGFILE}'"
            fi
        done < <(docker exec "${APP}" jq -r '.include[]?' \
            "/opt/civikitchen/docker/profiles/${prof}/profile.json" 2>/dev/null || true)
        while IFS=$'\t' read -r git_key git_version; do
            [ -n "${git_key}" ] || continue
            git_path=$(docker exec -u buildkit -w "${WEB}" "${APP}" bash -lc \
                "export PATH=/home/buildkit/buildkit/bin:\$PATH; cv ev 'echo CRM_Extension_System::singleton()->getFullContainer()->getPath(\"${git_key}\");'" \
                2>/dev/null || true)
            actual_commit=$(docker exec -u buildkit "${APP}" git -C "${git_path}" rev-parse HEAD 2>/dev/null || true)
            check "profile extension ${git_key} is exact pinned commit" \
                "[ '${actual_commit}' = '${git_version}' ]"
        done < <(docker exec "${APP}" jq -r --arg uf "${UF}" \
            '.dependencies[] | select(.repo) | select((.skipUf // []) | index($uf) | not) | [.name,.version] | @tsv' \
            "/opt/civikitchen/docker/profiles/${prof}/profile.json" 2>/dev/null || true)
    done
    check "API credentials are absent from docker logs by default" \
        "! grep -q 'API User Credentials' '${LOGFILE}'"
    cred_mode=$(docker exec "${APP}" stat -c '%a' /home/buildkit/api-credentials.txt 2>/dev/null || true)
    check "credentials file is mode 0600 (got ${cred_mode:-missing})" "[ '${cred_mode}' = 600 ]"
    creds_all=$(docker exec "${APP}" cat /home/buildkit/api-credentials.txt 2>/dev/null || true)
    for prof in "${PROFILES[@]}"; do
        while IFS= read -r u; do
            [ -z "${u}" ] && continue
            n=$(printf '%s\n' "${creds_all}" | grep -c "^${u}:" || true)
            check "credentials file has exactly one line for '${u}' (${prof})" "[ '${n}' = 1 ]"
        done < <(docker exec "${APP}" jq -r '.apiUsers[].username' \
            "/opt/civikitchen/docker/profiles/${prof}/profile.json" 2>/dev/null || true)
    done
    # A script failure aborts provisioning and therefore prevents a healthy
    # container. Keep the log assertion as defense in depth against a future
    # driver accidentally swallowing the error again.
    if grep -q 'ERROR: profile script failed:' "${LOGFILE}"; then
        echo "  ✗ profile script failure in logs:"; grep 'ERROR: profile script failed:' "${LOGFILE}"; fail=1
    else
        echo "  ✓ no profile script failures in logs"
    fi

    # 7) An API user can actually authenticate and call the API (authx basic
    # auth). This exercises the per-CMS user/role/permission wiring end to
    # end — an install-only check can't see a broken permission mapping.
    cred=$(docker exec "${APP}" cat /home/buildkit/api-credentials.txt 2>/dev/null | head -n 1 || true)
    api_user="${cred%%:*}"
    api_pass="$(echo "${cred}" | cut -d: -f2)"
    if [ -n "${api_user}" ] && [ -n "${api_pass}" ]; then
        # Each call asserts BOTH the body and the HTTP status. The body alone
        # is not enough: a fatal during request shutdown (e.g. the standalone
        # session writer failing after output was sent — exactly what the
        # remoteevent civicrm_session table collision caused) still produces
        # a complete JSON body, but with HTTP 500 on every request. A
        # body-only grep shipped that breakage as a green test.
        #
        # api_key (X-Civi-Auth: Bearer) is the canonical credential and works on
        # every flavor, Joomla included. It also has its own failure mode
        # (civicrm_contact.api_key is varchar(32) — an oversized generated key
        # gets mangled on save and only this check would catch it).
        api_key="$(echo "${cred}" | cut -d: -f3)"
        key_out=$(docker exec "${APP}" curl -s -w '\n%{http_code}' -X POST "${API_URL}" \
            -H "X-Civi-Auth: Bearer ${api_key}" \
            -H 'X-Requested-With: XMLHttpRequest' \
            --data-urlencode 'params={"limit":1}' 2>/dev/null || true)
        key_code="${key_out##*$'\n'}"
        key_body="${key_out%$'\n'*}"
        check "API user '${api_user}' authenticates via authx api_key" \
            "echo '${key_body}' | grep -q '\"values\"'"
        check "api_key API call returns HTTP 200 (got ${key_code})" \
            "[ '${key_code}' = '200' ]"

        # Basic/password auth: authx's password flow needs the CMS's full auth
        # stack, which Joomla doesn't run for a headless API request, so it only
        # works on Standalone/Drupal/WP. (api_key above is Joomla's path.)
        if [ "${UF}" != Joomla ]; then
            auth_out=$(docker exec "${APP}" curl -s -w '\n%{http_code}' -X POST "${API_URL}" \
                --user "${api_user}:${api_pass}" \
                -H 'X-Requested-With: XMLHttpRequest' \
                --data-urlencode 'params={"limit":1}' 2>/dev/null || true)
            auth_code="${auth_out##*$'\n'}"
            auth_body="${auth_out%$'\n'*}"
            check "API user '${api_user}' authenticates via authx basic auth" \
                "echo '${auth_body}' | grep -q '\"values\"'"
            check "basic-auth API call returns HTTP 200 (got ${auth_code})" \
                "[ '${auth_code}' = '200' ]"
        else
            echo "  ⊘ basic-auth skipped on Joomla (authx password flow unsupported; api_key covers it)"
        fi
    else
        echo "  ✗ no API credentials file in the container"; fail=1
    fi
fi

# 8) CIVIKITCHEN_SITE_URL rewrite (no-profile runs only): the demo must also
# work on a non-80 host port when CIVIKITCHEN_SITE_URL is set — the entrypoint
# rewrites the baked http://localhost base at boot. This must be asserted from
# the HOST side (the in-container checks above always see port 80), so a second
# container runs with a mapped port. Opt out with CK_SKIP_SITE_URL_TEST=1
# (e.g. when testing an image that predates the rewrite).
if [ -z "${PROFILE}" ] && [ "${CK_SKIP_SITE_URL_TEST:-0}" != "1" ]; then
    echo "==> site-url leg: boot with CIVIKITCHEN_SITE_URL on a non-80 host port"
    # Find a free host port; a connect on /dev/tcp succeeding means taken.
    URLPORT=""
    for p in $(seq 8180 8199); do
        if ! (exec 3<>"/dev/tcp/127.0.0.1/${p}") 2>/dev/null; then URLPORT="${p}"; break; fi
    done
    if [ -z "${URLPORT}" ]; then
        echo "  ✗ no free host port in 8180-8199 for the site-url leg"; fail=1
    else
        # Use 127.0.0.1 (not localhost) end-to-end for this leg. Two reasons:
        #  - docker -p publishes on IPv4 (0.0.0.0) by default; on a runner where
        #    `localhost` resolves to ::1 first, `curl localhost:PORT` hits an
        #    unbound IPv6 address and fails (000). 127.0.0.1 is always the IPv4
        #    publish, and matches the free-port probe above (/dev/tcp/127.0.0.1).
        #  - the base URL must match the request Host, or WordPress issues a
        #    canonical 301 to its configured host — a curl -L to that would then
        #    chase the very ambiguity we are avoiding.
        docker run -d --name "${APP}-url" -p "${URLPORT}:80" \
            -e "CIVIKITCHEN_SITE_URL=http://127.0.0.1:${URLPORT}" \
            "${IMAGE}" >/dev/null
        elapsed=0
        while :; do
            health=$(docker inspect -f '{{.State.Health.Status}}' "${APP}-url" 2>/dev/null || echo gone)
            state=$(docker inspect -f '{{.State.Status}}' "${APP}-url" 2>/dev/null || echo gone)
            [ "${health}" = "healthy" ] && break
            if [ "${state}" = "exited" ] || [ "${state}" = "gone" ] || [ "${elapsed}" -ge 300 ]; then
                echo "  ✗ site-url container not healthy (state=${state}) — last logs:"
                docker logs --tail 20 "${APP}-url" 2>&1 || true
                fail=1; break
            fi
            sleep 5; elapsed=$((elapsed + 5))
        done
        if [ "${health:-}" = "healthy" ]; then
            # Retry: healthy can precede the log flush by a moment on fast runners.
            rewrote=1
            for _ in 1 2 3 4 5 6; do
                if url_logs="$(docker logs "${APP}-url" 2>&1)" \
                    && grep -q 'Rewriting site base URL' <<<"${url_logs}"; then
                    rewrote=0; break
                fi
                sleep 5
            done
            check "entrypoint rewrote the base URL" "[ '${rewrote}' = 0 ]"
            URLPAGE="$(mktemp)"
            # Healthy != reachable from the host: the published-port forward can
            # lag the container healthcheck by a few seconds (seen on GH
            # runners as an immediate 000/connection refused). Retry briefly.
            url_code=000
            for _ in 1 2 3 4 5 6; do
                url_code=$(curl -s -o "${URLPAGE}" -w '%{http_code}' -L "http://127.0.0.1:${URLPORT}/" 2>/dev/null) || url_code=000
                [ "${url_code}" = "200" ] && break
                sleep 5
            done
            check "site serves HTTP 200 on the mapped port (got ${url_code})" \
                "[ '${url_code}' = '200' ]"
            # Any http://localhost/ (old base) left in the page means some URL
            # was still composed from the bake-time base — assets would 404.
            if grep -Eq "http://localhost(/|[\"'])" "${URLPAGE}"; then
                echo "  ✗ page still references the baked base URL:"
                grep -Eo "http://localhost[^\"' ]*" "${URLPAGE}" | head -3
                fail=1
            else
                echo "  ✓ no stale references to the baked base URL"
            fi
        fi
        docker rm -f "${APP}-url" >/dev/null 2>&1 || true
    fi
fi

if [ "${fail}" = 0 ]; then echo "==> PASS: ${IMAGE}"; else echo "==> FAIL: ${IMAGE}"; exit 1; fi
