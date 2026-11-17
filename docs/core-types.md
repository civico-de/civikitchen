# Typing CiviCRM core from outside

CiviCRM core declares few types for what its APIs return: an APIv4 row is a
bare array, `Result::first()` returns an array or null, and a database read is
whatever the DAO hands back. This page is the plan, and the state, of adding
the missing types for mago, the static analyser behind `ckanalyze`. It changes
nothing in core and adds no code that runs on a site. The types live in this
repository for good, and nothing waits on core adopting them.

The plan targets mago only; phpstan is to leave the images
([ADR-0017](adr/0017-php-quality-tools-consolidate-on-mago.md),
[Consolidating on mago](mago-consolidation.md)). While `ckanalyze` runs as a
shadow gate, a new type changes no consumer's verdict.

| Piece | Where | State |
|---|---|---|
| Generic `Result` replacement | `toolbelt/mago/stubs/Civi/Api4/Generic/Result.php` | [In place](#1-declare-what-core-leaves-untyped) |
| `execute()` returns `Result<array<string, mixed>>` | `Api4ExecuteRowsHook` in `toolbelt/mago-ext` | In place |
| `civicrm_api4()`, `getInfo()`, `addJoin()` patches | `toolbelt/mago/patches/` | In place |
| Filter hooks for three false findings | `toolbelt/mago-ext` | In place |
| Stub parity test | `toolbelt/mago/stub-parity.php` | In place, in `make test` and the image tests |
| `CRM_Core_DAO` query patches | `toolbelt/mago/patches/` | Planned |
| Row shapes for APIv4 `get` | `toolbelt/mago-ext` and the catalog | [Planned](#2-row-shapes-for-apiv4-get) |
| Settings | — | [Planned, measurement first](#3-settings) |

## Why

Extension code receives mixed values from these calls and narrows them by
hand. Across 39 extension repositories (grep, 2026-10-11, outside tests and
vendor directories):

| Call | Sites | Repos |
|---|---|---|
| `->execute()` | 1896 | 29 |
| `->first()`, `->single()`, `->last()` | 741 | 29 |
| `->column()` | 72 | 15 |
| `civicrm_api4()` | 114 | 17 |
| `Civi::settings()->get()` | 165 | 28 |
| `CRM_Core_DAO::executeQuery()` | 111 | 18 |
| `CRM_Core_DAO::singleValueQuery()` | 53 | 16 |

The patterns for execute and first also match calls that are not APIv4. About
two dozen of these repositories carry their own coercion helpers to turn such
values into scalars. In the first shadow run of `mago analyze`, the
mixed-assignment code and its mixed-* siblings made up most findings. The root
of many of them is that core declares `Civi\Api4\Generic\Result` as an
ArrayObject without type arguments, so mago reads every row as mixed and
rejects index access.

## What goes where

| Kind of type | Home |
|---|---|
| A core declaration that is missing or too wide, such as `@return array` on a method that returns rows | A mago patch file in this repository, a replacement declaration where a patch cannot express the type, or a type provider where a patch would bind the overrides |
| A type that follows from metadata, such as the row shape of an APIv4 `get` from its select list | A type provider in `toolbelt/mago-ext` |
| A value whose type core does not guarantee: a setting written by different code paths, decoded JSON, stored configuration | No static type. The extension narrows it at runtime. |
| An extension's own classes and values | The extension |

A type here states what core returns at runtime, measured on the images'
cores, not what core's metadata or SQL type suggests. Where the runtime type
is not certain, core's own declaration stays
([ADR-0011](adr/0011-checks-report-only-what-they-are-sure-of.md)). A wrong
type is worse than none, because mago then flags healthy code or hides a
defect.

## What mago offers

The behaviour below was measured on 2026-10-11 with mago 1.55.0; the images'
mago pin is in `toolbelt/versions.env`.

### Patches

The `[source] patches` setting lists PHP files that redeclare a symbol of an
included or built-in file. mago reads only their types: signatures,
`@return`, `@throws`, class-level `@template`, `@method` and `@property`. A
patch refines a symbol. It adds no real members and changes no hierarchy.
`ckanalyze` writes the configuration of each run, including the patch
directory.

Patched `@return` on `Result::first()` and on `AbstractAction::execute()`
takes effect, as does a conditional `@return ($index is null ? … : …)` on the
function `civicrm_api4()` and a class-level `@template` on `DAOGetAction`.
mago ignores `@extends` and `@implements` in patches altogether
([mago#2469](https://github.com/carthage-software/mago/issues/2469)), so a
patched `@extends \ArrayObject<…>` on Result does nothing. mago also checks
overrides against a patched signature: with execute patched, each action
whose execute declares a Result subclass gets an incompatible-return-type
finding.

### Replacement declarations

A declaration under `includes` takes the class's place when core's own file is
excluded. `@extends \ArrayObject<array-key, array<string, mixed>>` in such a
declaration types foreach loops, index access like `$result[0]` and first() as
rows, and the invalid-array-index finding on Result disappears. The cost is
that a replacement has to declare every member of the class it replaces.

### Type providers

The analyzer SDK registers `MethodReturnTypeProvider`,
`FunctionReturnTypeProvider` and `PropertyTypeProvider` implementations for
named targets. Its Type class builds literal strings, keyed arrays and generic
objects through `Type::namedObject($class, ...$typeArguments)`.

- A target matches by the method's declaring class, and also when that class
  is a subclass of the target. The target `DAOEntity::get` matches
  `Contact::get()`, while the target `Contact::get` does not. The target
  `AbstractAction::execute` matches an action's own execute too. The receiver
  type names the called class, for example `Civi\Api4\Contact`.
- mago keeps the type arguments a provider returns through methods declared
  `@return $this`, such as addWhere, and through a variable that is assigned
  once.
- A provider on execute that returns `Result<array{id: int, …}>` types
  foreach, first, single and index access with that row, given the generic
  replacement from step 1.

### The limit

A provider sees the invocation, its receiver type and its arguments, not the
syntax tree. The entity and select list of
`Contact::get()->addSelect('id')->execute()` therefore have to travel in the
receiver's type, one call at a time. A call that stands as its own statement,
such as `$api->addSelect('x');`, returns a type that nothing keeps; the
variable's type stays as it was.

## Steps

Each step ships fixtures in `toolbelt/mago-ext/tests/cases` that pin the exact
findings. Every type gets a healthy example among them that must produce no
finding.

### 1. Declare what core leaves untyped

Done, except the DAO patches and their image tests at the end of this
section.

**Result.** `toolbelt/mago/stubs/Civi/Api4/Generic/Result.php` replaces core's
Result for `ckanalyze`, which excludes core's file. The replacement is generic
over its row type, declared as `@template-covariant TRow = mixed` with
`@extends \ArrayObject<array-key, TRow>`. Iteration, index access, first,
last, itemAt and single return TRow. The rekey method renames the keys within
each row, so it returns the same class with array rows that have no shape.
Step 2 fills TRow with a row shape.

- The template is covariant because with an invariant one a Result of rows
  did not narrow to a subclass such as ReplaceResult: code after
  `instanceof ReplaceResult` read a never type.
- Rows default to mixed because an action may fill its Result with anything.
  Custom actions write `$result['run_id'] = $id` in `_run(Result $result)`,
  and mago types reads and writes of an ArrayAccess with the same template,
  so a bound of array turned each such write into an
  invalid-array-access-assignment-value finding. The default also lets an
  extension class extend Result without a type argument, which phpstan
  requires: it rejects `@extends Result<…>` when core's Result declares no
  template.

**Rows from execute.** `Api4ExecuteRowsHook`, a return-type provider in
`toolbelt/mago-ext`, makes `AbstractAction::execute()` return
`Result<array<string, mixed>>`. It is a provider rather than a patch because a
provider leaves alone the signature that overrides are checked against. The
rows are keyed by `string`: with array-key keys, a row handed to a parameter
declared `array<string, mixed>` produced a false finding.

An action that overrides execute keeps its own return type. Core's replace,
autocomplete and getSearchTasks actions and SearchKit's run, download and
saveFile actions return subclasses of Result with untyped rows; SearchDisplay
run with `return: 'id'`, for instance, fills its Result with IDs. An
extension's action keeps its own Result subclass in the same way.

**`civicrm_api4()`.** A patch in `toolbelt/mago/patches/` types it the same way
when `$index` is NULL. With an index, the Result holds one row's fields or one
column, so it keeps untyped rows. The patch also admits NULL for `$index`,
core's default, which core's `@param` leaves out. A patch cannot tell which
action class the names resolve to, so the actions that override execute get
rows here too: `civicrm_api4('SearchDisplay', 'run', ['return' => 'id'])`
returns IDs that mago reads as arrays.

**Known misses.** Eight actions fill the Result that execute returns with
something other than rows. PaymentProcessor.refund fills it with the
processor's response map, so `$result['refund_status']` is a string.
Contact.getMergedTo returns NULL for a contact that was never merged.
RecentItem.delete and Pledge.cancel return NULL. Contribution.continueCheckout,
User.login, User.passwordReset and Totp.confirmSeed return a status map. mago
types those values as arrays and flags code that reads them correctly, as
core's own first and single already do. No extension repository calls any of
them outside its tests, which `ckanalyze` leaves out (grep over 64
repositories). An extension's action that inherits execute is typed the same
way. Typing only the get, create, save, update and delete actions instead left
22 reads of real rows mixed, in 12 of 38 extension repositories.

**Result without a row type.** A plain Result returns mixed from first and
single, where core declares array. This applies to a parameter declared as
plain Result and to the ReplaceResult, AutocompleteResult and
SearchDisplayRunResult of the actions that override execute. Code that hands
a Result on declares its rows with `@return Result<array<string, mixed>>`. A
default of array instead of mixed would turn each scalar write in `_run()`
into a false finding: over 38 extension repositories, mixed gave 10 findings
on mixed rows in 3 of them, array 4 false findings in 3.

An annotation such as `Result<array{id: int}>` accepts only a Result of that
row type. Until step 2 gives execute row shapes, passing what execute returns
to a parameter with that annotation is a possibly-invalid-argument finding,
and returning it under such a return type is an invalid-return-statement
finding.

**Stub parity.** `toolbelt/mago/stub-parity.php` compares each stub with
core's file by reflection, ignoring docblocks. `make test` runs it against the
pinned core, and the image tests run it against each image's core. A member
that core declares and the stub lacks, or declares differently, fails the
test, because mago would report its callers. A member that only the stub has
is listed but passes. Cores before 6.18 lack the error methods such as
getErrors and addError, so mago accepts calls to them there: a missed finding
on those cores, never a false one.

Once mago honors `@extends` in patches (mago#2469), the replacement becomes a
patch of the generics and the row-returning methods, and the parity test goes
away. A patch refines core's own class, so members that core adds stay
visible; mago's documentation rules out a patch adding a member that core
lacks.

**Widened declarations.** Patches widen two declarations that core documents
too narrowly. `AbstractEntity::getInfo()` returns `array<string, mixed>`: core
documents a closed key set with type as a string, but returns type as a list
and adds keys per entity. `DAOGetAction::addJoin()` takes `$bridge` as
`string|array|null`, because core defaults it to NULL and reads an array as
the first join condition.

**Filter hooks** in `toolbelt/mago-ext` drop three false findings:

| Hook | Drops | Because |
|---|---|---|
| `DynamicPropertiesFilterHook` | missing-magic-method on a documented `@property` of a class that allows dynamic properties | Every DAO does so through DB_DataObject (mago#2483). |
| `Api4ResultFilterHook` | invalid-type-cast on `(array) $result` | Casting an ArrayObject returns its rows, not its properties, measured on PHP 8.5, also for a subclass with a public property. |
| `GenericHookEventFilterHook` | non-documented-property on `GenericHookEvent` itself | It carries every hook parameter as a magic property, as phpstan's `universalObjectCratesClasses` handles it. A subclass documents its fields with `@property` and keeps the finding. |

**Image tests.** Each patched method gets an image test that calls it on the
image's core and checks the value against the declared type. The standalone
boot test does so for execute, `civicrm_api4()`, `getInfo()` and `addJoin()`.
A core release that changes a return type then fails the image build rather
than a consumer's CI.

**Still open.** Patches for `CRM_Core_DAO::executeQuery()`, fetchAll and
singleValueQuery, carrying the types those methods return on the images,
measured first.

### 2. Row shapes for APIv4 `get`

**Catalog.** The generated APIv4 catalog lists field names only. It gains four
facts per field: the data type, whether the value is serialized, whether it
can be null, and its pseudoconstant suffixes. An extension's own entities come
from its `schema/` directory, as their field names do.

**Measured value types.** For each data type (Integer, Boolean, Float, Money,
String, Text, Date, Timestamp and serialized values), the PHP type an APIv4
get returns is measured on each image core. The measured table goes into this
document. The catalog maps through it and never from the SQL type.

**Carrying the select.** A patch declares two templates on DAOGetAction,
`TEntity of string` and `TSelect of string`. The provider on
`DAOEntity::get()` returns `DAOGetAction<'Contact', ''>`. addSelect and
setSelect return it with the literal names appended, and execute returns a
Result of the row those names describe. A joined field such as
`contact_id.display_name` resolves through the catalog's joins. A `:label` or
`:name` suffix resolves to a string. This covers every entity that inherits
get from DAOEntity: 77 of the 95 entity classes in the image's core, by
reflection on 2026-10-11. Of the rest, 15 declare their own get, one inherits
`BasicEntity::get()` and two have none. They get no shape until a provider
covers them.

**Open rows.** A row shape is open, as in
`array{id: int, display_name: string|null, ...<string, mixed>}`, and a key
outside it reads as mixed. A builder changed by a statement of its own, or by
a function it is passed to, can select more than its type shows; a closed
shape turns reads of those fields into undefined-string-array-index findings,
while an open one stays silent. Catching misspelled field names remains the
fluent field rule's job.

**No receiver assertion.** An assertion provider on `$this` does carry
`$api->addSelect('x');` into the variable, and a field selected in one branch
becomes an optional key. But it works only while the action class declares no
templates, because mago then keeps the type arguments without checking them.
With the templates above, mago reports the same assertion as
impossible-type-comparison and the variable turns mixed. In 58 extension
repositories, addSelect or setSelect stands as its own statement once and is
part of a chain 1472 times, so the plan does not rely on that undocumented
behaviour.

**Accepted gap.** A select narrowed by a later statement of its own, such as
`$api->setSelect(['id']);`, leaves keys in the type that the row lacks. The
same grep found no such call.

**No shape** is given when the select list is empty or not fully literal,
contains `*`, a function or an alias, or names a custom field or a field the
catalog does not know. The row then stays `array<string, mixed>` from step 1.

**Then** the same shape code serves `civicrm_api4('X', 'get', ['select' =>
[…]])` through a function provider, and create, update and save, whose rows
hold the written values.

### 3. Settings

`Civi::settings()->get()` keeps mixed unless measurement shows otherwise. For
Integer and Boolean settings, the plan compares the PHP type after three kinds
of write: through the settings form, through Setting.create and through
`Civi::settings()->set()`. If the type depends on the writer, a static type
would be a guess.

## Rollout

Types land while `ckanalyze` is a shadow gate, so no consumer turns red. The
findings that change are part of the shadow-phase triage: the mixed-*
findings disappear, and the new ones are mostly guards that the types make
redundant. Over 45 repositories with PHP code, step 1 removed 488 findings and
added 194. 178 of the new ones sit at 128 places such as `is_array($row)`,
`(array) $row` or `@var array<string, mixed> $row` on rows that step 1 types;
14 of the rest read a Result that a helper returns as plain Result.

A provider that crashes mago fails `ck ci` even as a shadow gate, so providers
ship only with their fixtures green on the pinned mago.

When mago becomes the gate in step 3 of the consolidation, the types start to
count. The changelog of that release names them.

## Risks

A core release can change what a method returns. The tests of step 1 and the
measured table of step 2 run against every core the images build, so the
change shows in the image build.

Providers depend on mago's young SDK. Every mago bump runs their fixtures.

An extension that supports an older core than the images do gets the images'
types. Patches and shapes are tested on supported cores only.

## Not covered

Psalm's stubs in `toolbelt/psalm/stubs` model taint sources and sinks for
`cktaint`. They do not describe return types, and they stay separate.

APIv3 and the dynamic `$dao->column` properties after a raw query are out of
scope. Neither has a shape the analyzer can know.
