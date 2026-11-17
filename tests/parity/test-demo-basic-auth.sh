#!/bin/bash
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
smoke_test="${root}/tests/images/boot-test-demo.sh"

# GNU base64 wraps at 76 columns; a credential this long must not put a
# newline into the header (HTTP 400). Simulate that wrapped output here.
wrapped=$(printf '%s:%s' eventmanager 0123456789abcdef0123456789abcdef0123456789abcdef \
  | base64 | tr -d '\n' | fold -w 76)
if [[ "${wrapped}" != *$'\n'* ]]; then
  echo "fixture does not exercise GNU base64 wrapping" >&2
  exit 1
fi

grep -Fq -- '--user "${api_user}:${api_pass}"' "${smoke_test}" \
  || { echo "demo smoke test must delegate Basic Auth encoding to curl" >&2; exit 1; }

if grep -Fq 'Authorization: Basic' "${smoke_test}"; then
  echo "demo smoke test must not construct a potentially wrapped Basic Auth header" >&2
  exit 1
fi

echo "demo Basic Auth encoding regression test passed"
