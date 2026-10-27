# shellcheck shell=bash
# Shared by the image boot tests; sourced, not run.

# Waits up to $2 seconds for container $1 to report healthy. Fails with its
# last log lines when it exits first or the time runs out.
wait_healthy() {
    local container=$1 timeout=$2 elapsed=0 state
    while [ "$(docker inspect -f '{{.State.Health.Status}}' "${container}" 2>/dev/null)" != healthy ]; do
        state=$(docker inspect -f '{{.State.Status}}' "${container}" 2>/dev/null || echo gone)
        if [ "${state}" = exited ] || [ "${state}" = gone ] || [ "${elapsed}" -ge "${timeout}" ]; then
            echo "!! ${container} not healthy (${state} after ${elapsed}s) — last logs:"
            docker logs --tail 40 "${container}" 2>&1 || true
            return 1
        fi
        sleep 5
        elapsed=$((elapsed + 5))
    done
    echo "    healthy after ~${elapsed}s"
}

# check <label> <shell condition> — prints the verdict; a failed one sets fail=1.
fail=0
check() { if eval "$2"; then echo "  ✓ $1"; else echo "  ✗ $1"; fail=1; fi; }

# verdict <label> — the run's last line; exits 1 when a check failed.
verdict() { if [ "${fail}" = 0 ]; then echo "==> PASS: $1"; else echo "==> FAIL: $1"; exit 1; fi; }
