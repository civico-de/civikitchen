# Seed snapshots

A profile that seeds data can opt in to snapshots. Its scripts then run once,
through the API, so core writes every derived row itself. The resulting
database is stored as a compressed dump, and every later fresh boot restores
that dump, moves its dates to today and rebuilds what depends on them. The
decision and its alternatives are in
[ADR-0018](adr/0018-seed-data-is-generated-once-and-restored.md).

Snapshots work on the standalone image. On the other flavors a profile with a
`snapshot` block runs its scripts on every fresh boot, and the apply log says
so in one line.

| Section | Covers |
|---|---|
| [Opting in](#opting-in) | The `snapshot` block in `profile.json`: `calendar`, `sizes`, `after_restore` |
| [What a fresh boot does](#what-a-fresh-boot-does) | Restore or generate, [the key](#the-key), [what a dump holds](#what-a-dump-holds) |
| [Dates](#dates) | The date shift and the calendar rules that keep period windows correct |
| [Writing a generator](#writing-a-generator) | The `seed-lib.php` helpers, sizes, correctness rules |
| [`ck seed`](#ck-seed) | `restore`, `save`, `status`, `rebuild`, `prune`, `today`, and the `CK_SEED_*` variables |
| [Where snapshots are kept](#where-snapshots-are-kept) | Dev stack, CI cache, file names |
| [Headless tests](#headless-tests) | How snapshots meet the `<db>_test` database |
| [How restore works](#how-restore-works) | Dump command, date shift and triggers |
| [Costs](#costs) | Measured generation, dump and restore times |

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

A profile without `snapshot` applies its scripts on every fresh boot.

## What a fresh boot does

The selected profiles (`CIVIKITCHEN_PROFILE` with its dependencies) share one
snapshot. It holds every profile up to the last one with a `snapshot` block,
expires with the finest `calendar` among them, and is named after them
(`fundraising+events`).

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

Rules that keep generated data correct:

- **Draw from `CK_SEED_TODAY` and the seeded RNG only.** Draw whole days, or a
  time of day from the RNG. A draw bounded by the current second makes the RNG
  stream depend on when the run started.
- **Compare balances with core's.** Core's balance is 0 for Completed, the
  rest for Partially paid, and the full amount for Pending, Cancelled and
  Refunded; Refunded and Cancelled have `cancel_date` set. A test of a
  refunded contribution checks the balance core computes, not 0.
- **Pass ISO country codes.** `country_id:name` takes the ISO code (`DE`), not
  the country name; `'Germany'` is written as NULL without an error.
  `ck_seed_individual` and `ck_seed_org` in `docker/profiles/lib.php` pass
  `DE`.
- **Key comparisons on content, not ids.** Parallel workers interleave ids
  depending on scheduling, while each worker's own RNG stream keeps the counts
  per period deterministic.
- **Receipts.** `ck_seed_contribution` passes
  `is_send_contribution_notification=0`. With the default notification on, a
  payment renders a receipt even with outbound mail disabled, and on a stack
  with SMTP to maildev it sends one and sets `receipt_date`.

To generate a large size faster:

- Run several workers, each its own `cv scr` process that creates its share of
  contacts and attaches records only to its own contacts. Eight workers gave
  about 4.5 times the single-worker rate on 6 vCPU.
- Set the server variable `innodb_flush_log_at_trx_commit=2` for the
  generation run. It changes durability, not what core writes, and needs the
  database root user.
- Do not batch records into one transaction when workers run in parallel:
  `INSERT INTO civicrm_address` then deadlocks against other workers' long
  transactions. One transaction per API call does not.
- `logging` is already off in the dev image, and these creates do not touch
  smart group or ACL caches, so neither needs a setting.
- For a plain Completed history, `Contribution.create` with status Completed
  is about five times cheaper than Order plus Payment. It is a core write path
  too, but writes the payment transaction from NULL instead of from Accounts
  Receivable and leaves `receipt_date` NULL.

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

On standalone, the headless test database `<db>_test` is copied from the site
database at first boot before the profile applies, so it holds no seed data:
`ck_post_install_config` copies it (`ck_setup_test_db` in
`docker/runtime/provision.sh`), and only then does `ck_post_install_provision`
apply the profile, where the restore runs. Both steps are marker-gated, so a
later boot does not copy again. The buildkit images make no copy; civibuild
handles their test database.

`cktestreset` copies the whole site database at any time, seed data included.
Headless suites still never see that data: `Civi\Test::headless()` rebuilds
the schema with `Schema::dropAll()` whenever the `civitest_revs` signature
differs, which it always does in a fresh copy. The copy costs time and disk in
proportion to the seed and nothing else; a seed of bench size makes
`cktestreset` slow.

## How restore works

- **Dump.** `ck seed save` pipes `mysqldump --single-transaction --quick
  --routines --skip-triggers --no-tablespaces --skip-dump-date`, with
  `--ignore-table-data` for each runtime table, into `zstd -3 -T0`. The
  standalone image ships `zstd` for this.
- **Load.** `ck seed restore` drops everything in the site database and loads
  the dump.
- **Shift.** Core installs triggers on the tables the shift updates:
  `civicrm_email`, `_address`, `_phone`, `_im` and `_website` set
  `civicrm_contact.modified_date` on UPDATE, `civicrm_activity` touches
  `civicrm_case.modified_date`, and `civicrm_relationship` rewrites
  `civicrm_relationship_cache`. With them in place the shift would overwrite
  or double-shift values. The shift therefore refuses a schema that has
  triggers, runs one `UPDATE` per table that sets all of the table's date
  columns at once, so `ON UPDATE CURRENT_TIMESTAMP` never fires, and runs in
  UTC, so a TIMESTAMP keeps its time across a daylight-saving change. It needs
  no CiviCRM boot, so it runs before `cv upgrade:db`.
- **Rebuild.** `cv upgrade:db` runs when the dump's core is older than the
  site's; the after-restore step then rebuilds the triggers with the caches.

## Costs

Measured on 2026-10-20 on the standalone image with MariaDB 11.4, in a 6-vCPU
laptop VM shared with other stacks; single figures vary by up to 30 %. The
bench figures are extrapolated linearly from a 20,000-contact data set.

| What | Time |
|---|---:|
| Demo seed (about 1,000 contacts, 3,700 contributions): restore end to end | about 45 s, mostly `cv` boots |
| Bench seed (200,000 contacts, 1 M contributions, 1.5 M activities): restore end to end | about 10 min |
| Bench seed: generate through the API on 8 workers | about 4.5 h |
| Bench seed: dump size with zstd | about 150 MB |
| One worker with core's defaults: contacts / contributions | about 40 / 8 per second |
| One worker, receipts off and `innodb_flush_log_at_trx_commit=2`: contributions | about 19 per second |

A bench generation does not fit a CI cache miss on a 2–4 vCPU runner within
GitHub's 6-hour job limit, which is why CI stays on `demo`.
