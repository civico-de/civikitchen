#!/bin/bash
# End-to-end proof that a generated scenario, including an external profile,
# is directly bootable with a candidate Standalone or buildkit image.
set -euo pipefail

image=${1:?usage: boot-scenario.sh <image>}
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
work=$(mktemp -d)
compose="$work/compose.json"
project="ckscenario-$$-${RANDOM}"

cleanup() {
  docker compose -f "$compose" down -v >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT

"$root/toolbelt/bin/ck" scenario compose "$root/tests/scenario/civikitchen.yaml" \
  | jq --arg image "$image" --arg project "$project" \
      '.name = $project | .services.app.image = $image | .services.app.ports = []' > "$compose"
docker compose -f "$compose" config >/dev/null
docker compose -f "$compose" up -d --wait

app_cv() {
  docker compose -f "$compose" exec -T app bash -lc '
    if [ -d /home/buildkit/buildkit/build/site/web ]; then
      export PATH="/home/buildkit/buildkit/bin:${PATH}"
      cd /home/buildkit/buildkit/build/site/web
    fi
    exec cv "$@"
  ' _ "$@"
}

# Reconciles the managed API users against the profile JSON at container path $1.
reconcile_api_users() {
  docker compose -f "$compose" exec -T -e CK_PROFILE_JSON="$1" -e CK_RECONCILE_API_USERS=1 app bash -lc '
    if [ -d /home/buildkit/buildkit/build/site/web ]; then
      export PATH="/home/buildkit/buildkit/bin:${PATH}"
      cd /home/buildkit/buildkit/build/site/web
    fi
    exec cv scr /opt/civikitchen/docker/profiles/configure-api-users.php
  '
}

# The managed API user as active|inactive:key|nokey, whatever the CMS.
api_user_state() {
  app_cv ev '
    $contact=\Civi\Api4\Contact::get(FALSE)
      ->addWhere("source", "=", "CiviKitchen profile API user")
      ->addSelect("id", "api_key")->execute()->first();
    $active=FALSE;
    if ($contact && CIVICRM_UF === "Standalone") {
      $active=(bool) (\Civi\Api4\User::get(FALSE)->addWhere("contact_id", "=", $contact["id"])
        ->addSelect("is_active")->execute()->first()["is_active"] ?? FALSE);
    }
    elseif ($contact && CIVICRM_UF === "Drupal8") {
      $match=\Civi\Api4\UFMatch::get(FALSE)->addWhere("contact_id", "=", $contact["id"])
        ->addSelect("uf_id")->execute()->first();
      $account=$match ? \Drupal\user\Entity\User::load($match["uf_id"]) : NULL;
      $active=$account && $account->isActive();
    }
    elseif ($contact && CIVICRM_UF === "Backdrop") {
      $match=\Civi\Api4\UFMatch::get(FALSE)->addWhere("contact_id", "=", $contact["id"])
        ->addSelect("uf_id")->execute()->first();
      $account=$match ? user_load($match["uf_id"]) : NULL;
      $active=$account && $account->status == 1;
    }
    elseif ($contact && CIVICRM_UF === "WordPress") {
      $match=\Civi\Api4\UFMatch::get(FALSE)->addWhere("contact_id", "=", $contact["id"])
        ->addSelect("uf_id")->execute()->first();
      $account=$match ? get_userdata($match["uf_id"]) : NULL;
      $active=$account && array_filter($account->roles, fn($r) => str_starts_with($r, "civikitchen_"));
    }
    elseif ($contact && CIVICRM_UF === "Joomla") {
      $match=\Civi\Api4\UFMatch::get(FALSE)->addWhere("contact_id", "=", $contact["id"])
        ->addSelect("uf_id")->execute()->first();
      $account=$match ? \Joomla\CMS\Factory::getContainer()
        ->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($match["uf_id"]) : NULL;
      $active=$account && !$account->block && count(array_diff($account->groups, [2])) > 0;
    }
    echo ($active ? "active" : "inactive") . ":" . (!empty($contact["api_key"]) ? "key" : "nokey");
  ' | tr -d '[:space:]'
}

logs=$(docker compose -f "$compose" logs app 2>&1)
grep -F '[minimal] profile applied' <<<"$logs" >/dev/null \
  || { echo "scenario profile was not applied" >&2; exit 1; }
if grep 'API User Credentials' <<<"$logs" >/dev/null; then
  echo "scenario disclosed credentials in logs" >&2
  exit 1
fi

credential=$(docker compose -f "$compose" exec -T app sed -n '1p' /tmp/civikitchen-api-credentials.txt)
grep -Eq '^smokeapi:[0-9a-f]{48}:[0-9a-f]{32}$' <<<"$credential" \
  || { echo "scenario did not generate bounded random credentials" >&2; exit 1; }
mode=$(docker compose -f "$compose" exec -T app stat -c '%a' /tmp/civikitchen-api-credentials.txt | tr -d '[:space:]')
[ "$mode" = 600 ] || { echo "scenario credentials mode is ${mode}, expected 600" >&2; exit 1; }

status=$(app_cv api4 Extension.get +w key=ckbootfixture +s status | tr -d '[:space:]')
grep -q '"installed"' <<<"$status" \
  || { echo "scenario extension is not installed" >&2; exit 1; }

while IFS= read -r check; do
  [ -n "$check" ] || continue
  echo "==> scenario check: ${check}"
  docker compose -f "$compose" exec -T -w /civikitchen-extension app bash -lc "$check"
done < <("$root/toolbelt/bin/ck" scenario commands "$root/tests/scenario/civikitchen.yaml")

# Ownership is a hard trust boundary. A profile must fail before mutating the
# existing managed set when a desired username belongs to the site's real CMS
# administrator. Then prove exact reconciliation by removing the managed API
# user and checking both identity state and API-key revocation.
docker compose -f "$compose" exec -T app php -r '
  $v=json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  $second=$v["apiUsers"][0];
  $second["username"]="smokeapi2";
  $second["permissions"]=["view all contacts"];
  $v["apiUsers"][]=$second;
  file_put_contents($argv[2], json_encode($v, JSON_THROW_ON_ERROR));
' /civikitchen-profiles/0/minimal/profile.json /tmp/civikitchen-role-union.json
reconcile_api_users /tmp/civikitchen-role-union.json >/dev/null
role_union=$(app_cv ev '
  $wanted=["access CiviCRM", "view all contacts"];
  if (CIVICRM_UF === "Standalone") {
    $role=\Civi\Api4\Role::get(FALSE)->addWhere("name", "=", "civikitchen_smoke_api")
      ->addSelect("permissions")->execute()->first();
    $permissions=$role["permissions"] ?? [];
  }
  elseif (CIVICRM_UF === "Drupal8") {
    $role=\Drupal\user\Entity\Role::load("civikitchen_smoke_api");
    $permissions=$role ? $role->getPermissions() : [];
  }
  elseif (CIVICRM_UF === "Backdrop") {
    $role=user_role_load("civikitchen_smoke_api");
    $permissions=$role ? $role->permissions : [];
  }
  else {
    $permissions=$wanted;
  }
  echo count(array_diff($wanted, $permissions)) === 0 ? "union" : "incomplete";
' | tr -d '[:space:]')
[ "$role_union" = union ] \
  || { echo "same-role API users did not receive their permission union" >&2; exit 1; }

docker compose -f "$compose" exec -T app php -r '
  $v=json_decode(file_get_contents($argv[1]), TRUE, 512, JSON_THROW_ON_ERROR);
  $v["apiUsers"][0]["username"]="admin";
  file_put_contents($argv[2], json_encode($v, JSON_THROW_ON_ERROR));
' /tmp/civikitchen-role-union.json /tmp/civikitchen-collision.json
if reconcile_api_users /tmp/civikitchen-collision.json >"$work/collision.log" 2>&1; then
  echo "scenario profile seized the unmanaged admin username" >&2
  exit 1
fi
grep -q 'belongs to an unmanaged' "$work/collision.log" \
  || { echo "scenario ownership collision did not fail for the expected reason" >&2; exit 1; }

state=$(api_user_state)
[ "$state" = active:key ] \
  || { echo "ownership collision partially mutated the managed API user (${state})" >&2; exit 1; }

docker compose -f "$compose" exec -T app sh -c \
  'printf %s '\''{"apiUsers":[]}'\'' > /tmp/civikitchen-empty-profile.json'
reconcile_api_users /tmp/civikitchen-empty-profile.json >/dev/null
state=$(api_user_state)
[ "$state" = inactive:nokey ] \
  || { echo "exact reconciliation left a stale API identity active (${state})" >&2; exit 1; }
credential_after=$(docker compose -f "$compose" exec -T app sh -c \
  'test ! -s /tmp/civikitchen-api-credentials.txt && echo empty')
[ "$credential_after" = empty ] \
  || { echo "exact reconciliation retained stale credentials" >&2; exit 1; }

echo "scenario boot test passed"
