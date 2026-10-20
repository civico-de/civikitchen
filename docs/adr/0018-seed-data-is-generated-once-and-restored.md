# ADR-0018: Seed data is generated once and restored from snapshots

*Status: accepted · 2026-10-20*

## Context

Profiles seed demo, development and browser-test data at first boot. Written
through APIv4, the data is correct, because core writes every derived row:
line items, financial items and transactions, caches. It is also slow: about
eight contributions a second on one worker with core's defaults. Written as
bulk SQL, the data is fast but drifts from what core writes. A refunded
contribution with hand-written financial rows is one example; its books
differ from the ones core writes for the same refund.

Every fresh stack, every CI run and every `docker compose down -v` pays the
generation again.

## Decision

A profile opts in with a `snapshot` block. The selected profiles share one
snapshot: their scripts run once through the API, and `ck seed save` stores
the whole database as a zstd-compressed dump after the last snapshot profile,
keyed by everything that shapes it. Every later fresh boot runs
`ck seed restore`: it loads the dump, moves every date column forward by the
days since generation, and rebuilds triggers, caches, smart groups and
SearchKit DB entities.

Generators compute dates relative to `CK_SEED_TODAY` and draw from a seeded
RNG. A profile declares the coarsest calendar period its data relies on, and
a snapshot expires with that period.

Raw SQL on CiviCRM tables lives in one reviewed place, `toolbelt/`, and only
for loading, dumping and shifting. Extensions keep writing generators against
the API.

## Rationale

Restoring is cheap where generating is not: a demo-size seed restores in
under a minute, and a bench-size one in about ten minutes against about four
and a half hours to generate on eight workers.

The whole database is dumped, so there is no table list to keep in sync with
core or with extensions. A dump from an older core is upgraded with
`cv upgrade:db`, which is the path a real site takes.

Shifting dates instead of generating per day keeps the data identical across
days, so tests can pin counts. Expiring a snapshot with its calendar period,
and placing records of earlier periods early enough, keeps "last year" and
"this month" windows correct after the shift.

## Consequences

The key covers profiles, shared helpers, size, mounted extensions' versions
and schema, boot settings and the period. A change to any of them generates
again, so a stale snapshot cannot be restored, at the cost of a regeneration
after every edit to a seeding script.

A restore replaces the whole database. It runs only on a database that was
never provisioned, so a recreated container never wipes a developer's data.

The standalone image ships `zstd` and a store directory; the dev template
mounts a host directory there, and the extension workflow caches it in CI.

Snapshots work on the standalone image only. On the other flavors the scripts
run as before.
