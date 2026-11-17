# Known upstream bugs

The profile browser suites in
[tests/e2e/profiles/](https://github.com/civico-de/civikitchen/tree/main/tests/e2e/profiles/)
run the bundled extensions against every CiviCRM version an image ships,
including the next release. A check that fails because of a defect in core or
in a foreign extension is neither deleted nor loosened: it is marked, and
listed here until the fix has reached every image.

[The flow](#the-flow) gives the steps from diagnosis to removal;
[Open](#open) lists the bugs currently marked.

## The flow

1. **Diagnose.** Reproduce the failure on a booted site and find the faulty
   layer. A defect in a profile, a seed or an image is fixed here and never
   marked.
2. **Report.** Open the pull request or issue in the upstream repository.
3. **Mark.** Call `upstreamBug()` as the first line of the affected test, with
   the upstream link and the conditions that trigger the bug:

    ```ts
    upstreamBug({ url: 'https://github.com/civicrm/civicrm-core/pull/38110', since: '6.21', profile: 'dach' });
    ```

    `since` is the first affected CiviCRM minor, `profile` the profile whose
    extension triggers it; leave out what does not narrow the bug. On an
    affected site the test is expected to fail. Everywhere else it runs as
    before.
4. **Record.** Add a row to the table below.
5. **Close the upper bound.** Once the fix reaches an image, the marked test
   passes there, and a marked test that passes is reported as a failure. Set
   `fixedIn` to the first minor that carries the fix. A beta counts as its
   release: `6.21.beta1` is `6.21`.
6. **Remove.** When no image ships a version below `fixedIn`, delete the
   marker and the row.

A marked test still runs. It turns red when the fix arrives, and when the bug
starts to appear outside the declared conditions.

## Open

| Bug | Affected | Marked tests | Upstream |
|---|---|---|---|
| `CRM_Core_DAO::writeRecord()` passes `''` instead of `NULL` as id to `hook_civicrm_pre` when APIv4 saves a record with `id: null`. A hook typed `?int $id`, as CiviSEPA's, throws a `TypeError`. | every version for the API call; from 6.21 the event registration form saves this way | `stack.spec.ts` (save with an explicit null id), `events.spec.ts` (anonymous registration), both with `dach` | [civicrm-core#38110](https://github.com/civicrm/civicrm-core/pull/38110), open |
