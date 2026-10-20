# @civico-de/civicrm-profile-schema

Validate a profile without installing a package or accessing the network:

```bash
php validate.php path/to/profile.json
# or from a CiviKitchen checkout/image
ck profile validate path/to/profile-directory
```

The dependency-free validator implements the JSON Schema vocabulary used by
`profile.schema.json`; images use this exact file to validate mounted external
profiles before applying any profile in the selected list. Shape validation is
not a sandbox: consumers that execute `scripts` or profile drivers must
separately establish trust in that code. `ck profile validate` also rejects a
`scripts` entry that is no file inside the profile directory, and a `seeds/`
directory: only the files `scripts` lists run.

JSON Schema (draft 2020-12) for the civikitchen CiviCRM **profile** format — the
declarative description of a scenario: `dependencies` (extensions), `authx`,
`apiUsers`, and declarative content (`config` + `seed`). This package is the
single source of truth any tool validates these profiles against.

Reusable `$defs` for consumers that only need a slice:

- `#/$defs/dependency` — one extension entry (an extension manifest).
- `#/$defs/contentProfile` — a standalone `authx` + `apiUsers` + `config` +
  `seed` (+ optional `scripts`) document (e.g. a seeding profile). A full
  profile embeds the same shape inline.
- `#/$defs/apiUser` — one API user (username, role, permissions). Credentials
  are never part of a profile: the applying tool issues random passwords and
  API keys. Usernames and roles follow a bounded cross-CMS identifier grammar.

`config` covers settings, core components, the domain organization,
from-address, mailbox domain, groups and membership types
(`membership_types`, asserted by name).

`include` names profiles that apply before this one, so a profile can be
composed from others: for example a data profile that needs core only, plus
the extensions that work on that data. Includes nest depth-first, each profile
applies once, and a cycle is an error.

A profile applies in this order: `dependencies` → `config` → `seed` →
`scripts`.

- **`config`** is asserted on every apply: whatever differs is written back.
  `settings` go first, then `components`, so both count for the rest.
  `settings.enable_components` is the whole list; `components` only switches
  components on, which keeps composed profiles from undoing each other. A
  component name core does not know is an error.
- **`seed`** creates each record once and never touches it again, so edits
  made in the UI survive a re-apply. Contacts are matched by
  `external_identifier` = `ref` and contributions by `trxn_id`
  `profile-seed-<ref>` (or `profile-seed-<contact_ref>-<n>` without a `ref`).
  Deleted records count as existing.
- **`scripts`** are PHP files run via `cv scr` in list order, for volume or
  complex data that declarative JSON can't express.
- **`snapshot`** (optional) marks the result of `config`, `seed` and
  `scripts` as storable: `calendar` (`year`, `month` or `none`; default
  `year`) is the coarsest calendar period the data relies on, `sizes` (default
  `["demo"]`) the sizes the scripts can generate, and `after_restore` the
  scripts inside the profile directory to run after the stored state is
  restored.

A missing prerequisite is an error, never a skip. Examples are membership types
without CiviMember, an unknown financial type, setting, field or group, or a
`contact_ref` no contact carries. The apply lists every such problem before it
writes anything. The exceptions are `settings` and `components`: they are
written first, because the APIs of a component only exist once it is on.

## Use

```ts
import schema from "@civico-de/civicrm-profile-schema/profile.schema.json";
import Ajv2020 from "ajv/dist/2020";
import addFormats from "ajv-formats";

const ajv = new Ajv2020({ allErrors: true });
addFormats(ajv);
ajv.addSchema(schema);

// whole profile:
const validateProfile = ajv.getSchema(schema.$id);
// just one extension dependency (e.g. an extension manifest entry):
const validateDependency = ajv.getSchema(`${schema.$id}#/$defs/dependency`);
```

Published to GitHub Packages under the `@civico-de` scope. Installing requires a
`read:packages` token (GitHub Packages has no anonymous npm access, even for
public packages) — put it in your user `~/.npmrc`:

```
//npm.pkg.github.com/:_authToken=<PAT with read:packages>
```

and route the scope in the consuming repo's `.npmrc`:

```
@civico-de:registry=https://npm.pkg.github.com
```
