# Seed snapshots

A profile that seeds data can opt in to snapshots. Its scripts then run once,
through the API, so core writes every derived row itself. The resulting
database is stored as a compressed dump, and every later fresh boot restores
that dump, moves its dates to today and rebuilds what depends on them. The
decision and its alternatives are in
[ADR-0018](adr/0018-seed-data-is-generated-once-and-restored.md).

Snapshots work on the standalone image. On the other flavors a profile with a
`snapshot` block runs its scripts as before, and the apply log says so in one
line.

## Opting in

Add a `snapshot` object to the profile's `profile.json`:

```json
{
  "description": "Members with a year of fees",
  "dependencies": [],
  "snapshot": {"calendar": "year", "sizes": ["demo", "bench"], "after_restore": ["scripts/90-refresh.php"]},
  "scripts": ["scripts/10-members.php"]
}
```

| Key | Default | Meaning |
|---|---|---|
| `calendar` | `year` | The coarsest calendar period the data relies on: `year`, `month` or `none`. See [Dates](#dates). |
| `sizes` | `["demo"]` | The sizes the scripts can generate. `CK_SEED_SIZE` picks one; a size no selected snapshot profile lists is an error. |
| `after_restore` | `[]` | Scripts inside the profile directory that run after a restore, for example to fill reporting tables. |

A profile without `snapshot` behaves as before.

## What a fresh boot does

The selected profiles (`CIVIKITCHEN_PROFILE` with its dependencies) share one
snapshot. It holds every profile up to the last one with a `snapshot` block,
expires with the finest `calendar` among them, and is named after them
(`fundraising-data+events-data`).

On a database that has not been provisioned yet, the profile driver runs
`ck seed restore <profile>` before each profile's config, seed and scripts.
At the first snapshot profile it decides for the whole set:

```text
snapshot for this key in the store, valid today, core not older than the dump?
  ├─ yes ─▶ load dump ─▶ shift dates ─▶ upgrade:db if needed ─▶ after-restore
  └─ no  ─▶ apply every profile ─▶ ck seed save after the last snapshot profile
```

After a restore the profiles up to the last snapshot profile are skipped;
profiles before the first one and after the last one apply as usual. Keep a
profile whose effect lies outside the database (files, external services)
outside that range.

The after-restore steps run in one CiviCRM process: pending extension
upgrades, triggers, caches and managed entities, invalidation of every smart
group cache, a refresh of every SearchKit DB-entity display, then the
profile's `after_restore` scripts.

A database that was provisioned before is never replaced: a restarted or
recreated app container on an existing database applies the profile as usual
and neither restores nor stores.

A failed save does not fail the boot. The site is complete, and the next fresh
boot generates again.

### The key

A snapshot is valid for one key, the first 16 hex digits of a SHA-256 over:

- every file in each selected profile's directory, in apply order (all
  profiles of `CIVIKITCHEN_PROFILE`, not only those with a `snapshot` block);
- the shared helpers `docker/profiles/lib.php`, `seed-lib.php` and the
  calendar code;
- the size;
- for each mounted extension: key, its `info.xml` (version and requires), its
  `civikitchen.yaml` (dependency pins), and a hash of its
  `schema/`, `xml/schema/` and `sql/` directories;
- the boot settings written before any profile runs: components, locales,
  demo user, enabled and extra extensions, SMTP;
- the set's calendar unit and the period of today (`2026` for `year`,
  `2026-10` for `month`).

The core version is not part of the key. A dump from an older core is restored
and then upgraded with `cv upgrade:db`, as a real site would be. A dump from a
newer core is refused, and the boot generates a new one.

### What a dump holds

The whole site database, without triggers. Only the rows of runtime tables are
left out, their structure stays: caches, sessions, `civicrm_queue*`, job and
system logs, `log_*`, `civicrm_tmp_*`, and the SearchKit DB-entity tables
`civicrm_sk_*`, which the after-restore step refreshes.

## Dates

A dump holds fixed dates. On restore, every `DATE`, `DATETIME` and `TIMESTAMP`
column of the dumped `civicrm_*` tables moves forward by the days between the
generation day and today, with the time of day kept. Columns come from
`information_schema`, so custom value tables move too, and birth dates move
with everything else, so ages stay the same. Windows relative to a day ("the
last 90 days") match exactly after the shift.

Calendar windows ("last year", "this month") need two rules:

- A snapshot is restored only while today lies in the period of its
  generation day, and never more than 364 days (`year`) or 27 days (`month`)
  after it. In the next period the boot generates again. `calendar: none`
  never expires.
- A record that belongs to an earlier period lies no later in that period
  than the generation day minus the remaining validity. The biggest shift a
  restore can apply then never pushes it into the next period.
  `ck_seed_date_in()` draws dates that way.

The same seed and the same generation day give the same data, and the number
of records per period does not depend on the day, so tests can pin counts.

## Writing a generator

Snapshot scripts include `docker/profiles/seed-lib.php` (which includes
`lib.php`) and never read the wall clock. A bundled profile includes it as
`__DIR__ . '/../../seed-lib.php'`; an extension's own profile under
`.docker/profiles/` includes `/opt/civikitchen/docker/profiles/seed-lib.php`.

| Helper | Returns |
|---|---|
| `ck_seed_today()` | The generation day from `CK_SEED_TODAY`. Fails when it is unset. |
| `ck_seed_rng(int $seed = 1)` | A seeded `Random\Randomizer`. |
| `ck_seed_date_in($rng, 'year', $ago)` | A datetime in the period `$ago` periods back, placed so a restore keeps it there (`'month'` works the same). |
| `ck_seed_time($rng, $day)` | A datetime on `$day` at a working hour. |
| `ck_seed_contribution($contact, $amount, $date, $status, $type, $values)` | A contribution booked through `Order.create`, `Payment.create` and the status change the back office uses, so core writes every financial row. `$status` is `Completed`, `Pending`, `Partially paid`, `Refunded`, `Cancelled` or `Failed`. Receipts stay off. |

A date relative to today (`$today->modify('-2 months')`) needs no helper: the
shift keeps its distance to today.

`CK_SEED_SIZE` (default `demo`) tells a script how much to generate. A large
size such as `bench` is meant for local runs: CI never sets `CK_SEED_SIZE`, so
it restores or generates `demo` only.

## `ck seed`

| Command | Does |
|---|---|
| `ck seed restore <profile-dir>` | Run before a profile's content. At the set's first snapshot profile it restores the snapshot for the current key; exits 3 when the profile has to be applied (no snapshot, or the profile lies outside it). |
| `ck seed save <profile-dir>` | Run after a profile's content. After the last snapshot profile of a set generated on this boot it dumps the site into the store and keeps the newest two per set and size. |
| `ck seed status [--generated]` | Key, generation day, validity and dump of the snapshot this site was seeded from, and whether the current key differs. `--generated` prints the key alone, and only when this boot generated and stored the snapshot. |
| `ck seed rebuild` | Removes this site's snapshot from the store, so the next fresh boot generates it again. |
| `ck seed prune [--keep=N]` | Keeps the newest N (default 2) per set and size. |
| `ck seed today` | The generation day in force. |

| Variable | Default | Meaning |
|---|---|---|
| `CK_SEED_STORE` | `/var/lib/civikitchen/snapshots` | The store directory. |
| `CK_SEED_SIZE` | `demo` | The size to restore or generate. |
| `CK_SEED_TODAY` | the container's date at provisioning | The day to generate for and shift to, as `Y-m-d`. |

## Where snapshots are kept

- **Dev stack:** the template's `.docker/docker-compose.yml` mounts
  `.docker/snapshots/` (gitignored) at the store, so `docker compose down -v`
  keeps them and the next boot restores. `ckup` creates the directory open to
  the image's web user. Without `ckup` on a Linux host, create it with
  `mkdir -m 0777 .docker/snapshots` before the first boot.
- **CI:** the `ci` and `playwright` jobs of the extension workflow cache the
  store under the snapshot key, per job, directory and image. A miss generates
  once and saves.
- **Never in git.** Only the generators are committed.

Each dump is named `<set>-<size>-<key>.sql.zst`, with a `.json` metadata
file beside it that is written last; a dump without one is incomplete and
ignored.

## Headless tests

The headless test database `<db>_test` is copied at first boot before the
profile applies, so it holds no seed data. `cktestreset` copies the whole site
database, seed data included, and Civi\Test then drops and rebuilds the schema
on its first run. The copy costs time and disk in proportion to the seed and
nothing else; a seed of bench size makes `cktestreset` slow.

## Measurements (2026-10-20)

The numbers the design rests on, recorded once. The spike's volume driver
and its date-shift script were replaced by `ck seed` and `docker/profiles/seed-lib.php`;
file names below are the ones the spike used.

### Setup

- **Image:** `ghcr.io/civico-de/civikitchen:v1` (standalone, CiviCRM 6.19.1, PHP 8.4)
  with MariaDB 11.4.
- **Host:** colima VM on a laptop, 6 vCPU and 12 GiB. About ten other stacks
  were running on the same VM, so single figures vary by up to ±30 %.
- **API generation (A):** a fresh stack with nothing else in its database.
- **Dump and restore (B, D):** an extension dev stack whose bulk-SQL
  seeder fills realistic volume.
- **Generator:** a spike version of the seed helpers (`CK_SEED_TODAY`, a
  seeded `Random\Randomizer` per worker, bucket dates, creators for people,
  contributions, memberships, participants and activities) and a volume driver
  that took counts, worker shard and transaction batch from the environment.
- **Contribution statuses:** Completed 80 %, Pending 6 %, Partially paid 4 %,
  Refunded 5 %, Cancelled 5 %. Every contribution starts with `Order.create`.
  - Completed adds `Payment.create` for the full amount.
  - Partially paid adds `Payment.create` for half.
  - Refunded adds a full payment, then a status change to Refunded.
  - Cancelled adds a status change to Cancelled.
- **Rates:** records per second, timed in-process around the creation loop.
  For parallel runs, the aggregate rate is the total count divided by the
  slowest worker's time.

### A. Generation rate through the API

**Single worker, defaults**, measured with 2,000 contacts and 10,000
contributions. In these defaults every completing payment renders a receipt.

| Entity | Rate per second | Bench count | Bench time |
|---|---:|---:|---:|
| Contact with email and address | 40.6 | 200,000 | 1.4 h |
| Contribution (status mix) | 7.9 | 1,000,000 | 35 h |
| Membership | 80 | — | — |
| Participant | 81 | — | — |
| Activity with one target | 69 | 1,500,000 | 6.1 h |

That is about 43 h for the bench, so single-threaded API generation is out.

**Where a contribution's time goes**, measured over 40 calls each:

| Call | ms each |
|---|---:|
| `Order.create` | 34 |
| `Payment.create`, receipt notification on (the default) | 155–180 |
| `Payment.create` with `is_send_contribution_notification=0` | 64–84 |
| `Contribution.create` with status Completed, no Order | 22 |

- With outbound mail disabled, the receipt is still rendered, at about 54
  queries per payment, but never sent. `receipt_date` stays NULL either way.
- On a stack with SMTP to maildev, every payment would send a mail and set
  `receipt_date`.
- A seed sends no receipts, so the helper passes
  `is_send_contribution_notification=0`. That setting changes nothing core
  writes when mail is off.

**Settings that speed generation up**, single worker, 2,000 contributions:

| Variant | Contributions per second |
|---|---:|
| Receipts off | 12.3 |
| + `CRM_Core_Transaction` around every 50 records | 14.8 |
| Receipts off + `innodb_flush_log_at_trx_commit=2` | 18.9 |
| Both | 17.3 |

- The flush setting is a server variable for the generation run. It changes
  durability, not what core writes, and needs the database root user.
  Transaction batching adds nothing on top of it.
- `logging` is already off in the dev image (`0`), so there is nothing to
  gain there.
- Smart group and ACL caches are not touched by these creates, so no setting
  is needed for them.

**Parallel workers.** Each worker runs its own `cv scr` process with
`CK_SEED_WORKER`/`CK_SEED_WORKERS`, creates its share of contacts and attaches
its records only to its own contacts. Totals per run: 800 contacts, 4,000
contributions, 400 memberships, 400 participants, 1,200 activities. Receipts
off, flush setting 2.

| Workers | Contacts/s | Contributions/s | Activities/s | Errors |
|---:|---:|---:|---:|---|
| 1 | 40 | 17–19 | 117 | none |
| 2 | 60 | 38 | 226 | none |
| 4 | 157 | 63 | 311 | none |
| 8 | 172 | 86 | 403 | none |
| 2/4/8 with a 50-record transaction batch | — | — | — | 1/2, 3/4 and 5/8 workers died with a deadlock |

- The deadlocks came from `INSERT INTO civicrm_address` while other workers
  held long transactions. With one transaction per API call, no worker hit a
  deadlock. Parallel generation must therefore not batch transactions.
- Eight workers give about 4.5× on 6 vCPU.

**Bench extrapolation, 8 workers:**

| Entity | Time |
|---|---:|
| 200,000 contacts | 19 min |
| 1,000,000 contributions | 3.2 h |
| 1,500,000 activities | 1.0 h |
| Memberships and participants, tens of thousands each | a few minutes |

That is about 4.5 h in all.

- The rates were stable from 50 to 37,000 contributions in the database. Index
  growth towards 1 million was not measured.
- Using `Contribution.create` Completed for the plain Completed history (22 ms
  against about 110 ms) would bring the contribution part down further. It is
  a core write path too, but it writes a payment trxn from NULL instead of
  from Accounts Receivable and leaves `receipt_date` NULL. It was measured
  single-threaded only.

**Correctness checks:**

- Per status, core's own balance is: Completed 0, Partially paid the rest,
  Pending, Cancelled and Refunded the full amount. Refunded and Cancelled have
  `cancel_date` set.
- A refunded contribution therefore shows its full amount as balance in core
  too, through both the status-change path and the negative-payment path. The
  "Integrity after restore" test must compare against the balance core
  computes, not against 0.
- `country_id:name` takes the ISO code (`DE`), not the country name.
  `'Germany'` is silently written as NULL. `ck_seed_individual` and
  `ck_seed_org` in `docker/profiles/lib.php` had that bug; they pass `DE` now.

**Determinism:**

- Each worker draws from its own fixed RNG stream, so the counts per bucket are
  deterministic.
- Ids interleave between workers depending on scheduling. Snapshot comparisons
  must key on content, not on ids.
- Generators draw whole days, or a time of day from the seeded RNG, relative to
  `CK_SEED_TODAY`. They never draw "up to now" from the wall clock. A draw
  bounded by the current second makes the RNG stream depend on when the run
  started.

### B. Dump, restore and date shift at bench size

Measured at 20,000 contacts and **extrapolated linearly** to the bench size.
The bench size itself was not run.

**Data set:** 20,000 contacts, 66,787 contributions with line items, financial
items and trxns, 127,511 activities with 269,066 activity contacts, 12,801
participants and 5,235 memberships. That is 234 MB on disk, and 478,813 rows
carry at least one date. The plan's bench (200,000 contacts, 1 M contributions,
1.5 M activities) is 10–15 times that, so the factor below is 12–15.

| Step | 20k measured | Bench, extrapolated |
|---|---:|---:|
| Dump with zstd | 3.3 s | 40–50 s |
| Dump size, zstd | 11.1 MB | 135–170 MB |
| Dump size, raw SQL | 96 MB | 1.2–1.4 GB |
| Restore into an empty database | 18.5 s | 3.7–4.6 min |
| Restore over the site database | 22.7–26.8 s | 4.5–6.7 min |
| Date shift, 67 tables, 155 columns | 8.9–10.5 s | 1.8–2.6 min |
| `cv flush --triggers` (26 triggers back) | 6.5–8.9 s | constant |
| `cv upgrade:db`, same core | 10.3 s | constant |
| `cv ext:upgrade-db` | 5.4 s | constant |
| `Managed.reconcile` | 1.8 s | constant |
| `cv flush` | 6.4 s | constant |
| Smart groups: flush, then `loadAll()` for 7 groups | 3.5 s | 45–55 s |
| Refresh of 6 SearchKit DB-entity displays | 1.3 s | 16–20 s |

- **Bench restore end to end:** about 9–12 min, against about 4.5 h to
  generate.
- **Cost of the `cv` steps:** about 30 s that do not grow with volume. Each
  step pays a full CiviCRM boot. `ck seed restore` should run them in one
  process where it can.
- **Shift check** (on the demo-size copy, see D): every shifted value moved by
  exactly Δ days with the time of day kept. That covers 9,643 receive dates,
  every `modified_date` and every birth date.

The volume came from a bulk-SQL seeder that writes rows before the contacts
they reference. Its insert session therefore ran with foreign-key checks off.
A count afterwards found no orphans.

### C. Headless test DB versus profile apply

- On standalone, the test DB is copied from the main DB **before** the profile
  is applied:
  - `docker/standalone/entrypoint.sh:176` runs `ck_post_install_config`, which
    calls `ck_setup_test_db` (`docker/runtime/provision.sh:1039`). That
    function copies the main DB into `<db>_test` (`provision.sh:223-230`).
  - Only then does `entrypoint.sh:177` run `ck_post_install_provision`, which
    applies the profile (`provision.sh:1071` deps, `:1074` content).
- Both bundles are marker-gated, so a later boot does not copy again.
- On buildkit there is no copy at all: `docker/buildkit/entrypoint-common.sh:71`
  leaves the test DB to civibuild.
- `cktestreset` (`toolbelt/bin/cktestreset:46-48`) copies the main DB **at
  any time**. After a restore it would copy the seed, a bench seed included.
- Headless suites never see that data, though. `Civi\Test\CiviEnvBuilder::apply()`
  (core `Civi/Test/CiviEnvBuilder.php:253`) rebuilds whenever the
  `civitest_revs` signature differs, which it always does in a fresh copy.
  `Civi\Test::headless()` starts that rebuild with `Schema::dropAll()`
  (`Civi/Test.php:122`).
- The only cost is copy time and disk.
- **Consequence:** snapshot restore runs inside `ck_apply_profile content`,
  after the test-DB copy, so first boot copies no seed data. `cktestreset`
  keeps copying the whole database; see [Headless tests](#headless-tests).

### D. Demo-size dump and restore

- **Data set:** the extension's demo seed. It has 1,002 contacts and 3,686
  contributions, and the schema is 26 MB on disk.
- **Command:** `mariadb-dump --single-transaction --quick --routines --skip-triggers`
  with `--ignore-table-data` for the 11 runtime tables (cache, queue, job and
  system log, `log_*`, `civicrm_sk_*`), piped into `zstd -3 -T0`.
- **Dump:** 0.45–0.65 s. The dump is 0.82 MB compressed and 6.5 MB as raw SQL.
- **Restore into an empty database:** 2.7–5.0 s.
- **End to end, about 43 s:**
  - restore over the site database, 7.2 s;
  - shift by 30 days, 0.3 s;
  - `cv flush --triggers`, 7.6 s;
  - `cv upgrade:db`, 11.0 s;
  - `cv ext:upgrade-db`, 5.6 s;
  - `Managed.reconcile`, 2.9 s;
  - `cv flush`, 3.5 s;
  - smart groups, 4.0 s;
  - SearchKit refresh, 0.8 s.

  The `cv` boots dominate.
- **Date shift of the restored copy by 10 days:** 0.3 s for 67 tables, 155
  columns and 27,178 rows.
  - All 9,643 `receive_date` values moved by exactly 10 days with the time of
    day kept.
  - Every `modified_date` and `birth_date` moved by exactly 10 days as well.
- `zstd` is in the MariaDB image but **not in the app image**. The app image
  needs it for the store.

### Date shift: what the spike changed in the design

- Core installs triggers on the tables the shift updates:
  - `civicrm_email`, `_address`, `_phone`, `_im` and `_website` set
    `civicrm_contact.modified_date = CURRENT_TIMESTAMP` on UPDATE.
  - `civicrm_activity` touches `civicrm_case.modified_date`.
  - `civicrm_relationship` rewrites `civicrm_relationship_cache`.
- Shifting with the triggers in place would therefore overwrite or double-shift
  values.
- The spike's date-shift script, now `DateShift` behind `ck seed restore`, handles this:
  - It refuses a schema that has triggers.
  - The dump is made with `--skip-triggers`. The restore loads it, the shift
    runs, and `cv flush --triggers` rebuilds the triggers.
  - It runs one `UPDATE` per table that sets all of the table's date columns at
    once, so `ON UPDATE CURRENT_TIMESTAMP` never fires.
  - It runs in UTC, so a TIMESTAMP keeps its time across a DST change.
  - It needs no CiviCRM boot, so it can run before `cv upgrade:db`.

### What the numbers mean for the bench size

- API generation is fine for `demo`. About 5,000 contributions take roughly
  7 min single-threaded and under 2 min on 8 workers.
- For `bench` it takes about 4.5 h on 8 workers on a laptop. That is
  acceptable as a once-a-year local run per key, because every later boot
  restores in about 10 min (extrapolated) and the snapshot is about 150 MB.
- It does not fit a CI cache miss: a 2–4 vCPU runner and GitHub's 6 h job
  limit rule it out.
- So the bench size does need its own path, but not a SQL one:
  - Keep the API generator, run it parallel with
    `innodb_flush_log_at_trx_commit=2` and receipts off, and without
    transaction batching.
  - Mark `bench` as local-only in `sizes`, so CI restores or generates `demo`
    only.
  - If even the local time matters, generate the plain Completed history with
    `Contribution.create` (about 5× cheaper per record, still core-written).
