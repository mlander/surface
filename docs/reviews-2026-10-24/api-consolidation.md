# API consolidation: a decision list, 2026-10-24

Taken on `next` at 890230e. Nothing here is built until the owner
decides it, item by item. Section 1 is the inventory, section 2 the
findings, section 3 the recommendation in the order an owner approves
things: A renames, B merges, C drops, D wire changes, E keep.

**How the counts were taken.** A script read every `.php`, `.module` and
`.install` file of the module with doc comments and line comments removed,
so prose does not count as use. "Uses" is `submodules/tests`:
`submodules` is `modules/*` outside their `tests/`, `tests` is `tests/`
and `modules/*/tests/`. The engine's own uses (`src/`) are left out
unless the engine is the only user, which the row says. An attribute's
arguments are counted by name, positional ones by position. "First
example" is the lowest example number in `Series.php` whose files carry
the use; `first` is `data_surface_first`; a dash means no numbered
example does. "Runs" names who used it: **N1** to **N4** are the
fresh-eyes runs of 2026-10-18, 10-19, 10-19b and 10-21, **A** the
pathauto and honeypot ports of 2026-10-19 and their re-port in
[keys that come from the site](../decisions.md#keys-that-come-from-the-site).

The fresh-eyes logs counted the concepts each run needed: N1 20 for its
first step and 33 for the whole task, N2 19, N3 20, N4 21. The adopter
report's "earned their place" list is resource, operation,
`ConfigObjectStorage`, `LabeledChoice`, `Range`, `Length`, the declared
`doesNotApply()`, a runtime refiner, a resolver, a custom widget,
derived structures and the cosmetic layer; its "never needed" list is
identity and known keys, shapes, mixins, ports, variants, collections,
alters, access classes, child operations, outputs, secrets, examples,
layouts and panels.

## 1. The inventory

### 1.1 Attributes and their arguments

All in `src/Resource/Attribute/` but the last two, in `src/Attribute/`.

| Name | Documented | Uses | First example | Runs |
| --- | --- | --- | --- | --- |
| `#[Resource]` | [resources](../resources.md#the-pattern) | 21/49 | first, 1 | N1 to N4, A |
| `id` | [declaring](../declaring-a-resource.md#names) | 21/46 | first, 1 | all |
| `identity:` | [declaring](../declaring-a-resource.md#operations-and-identity) | 5/12 | 15 | none |
| `storage:` | [storage](../storage.md#a-simple-config-object) | 21/39 | first, 1 | all |
| `access:` | [resources](../resources.md#static-and-runtime-access) | 3/4 | 15 | none |
| `version:` | [evolution](../evolution.md#the-version) | 0/0 | - | none |
| `#[Structure]` | [resources](../resources.md#children) | 19/142 | 3 | N1 to N4 |
| `id` | as above | 19/137 | 3 | N1 to N4 |
| `access:` | [resources](../resources.md#three-answers) | 1/10 | 10 | none |
| `version:` | [evolution](../evolution.md#the-version) | 0/0 | - | none |
| `#[Shape]` | [declaring](../declaring-a-resource.md#named-shapes) | 2/12 (7 engine) | 4 | N3, N4, A |
| `id` | as above | 2/8 | 4 | N3, N4 |
| `#[Operation]` | [resources](../resources.md#operations-create-update-and-the-rest) | 32/80 | first, 1 | all |
| `id`, `label`, `permission`, `description:` | as above | 31/77, 31/77, 31/38, 31/9 | first, 1 | all |
| `of:` | the attribute's docblock only | 0/3 | - | none |
| `default:` | [resources](../resources.md#child-operations) | 0/3 | - | none |
| `destructive:` | [decisions](../decisions.md#an-operation-states-how-it-behaves) | 2/4 | 15 | none |
| `readOnly:` | as above | 2/5 | 10 | none |
| `idempotent:` | as above | 5/2 | 15 | none |
| `creates:` | [decisions](../decisions.md#whether-an-operation-creates) | 6/11 | 15 | none |
| `deletes:` | [resources](../resources.md#operations-create-update-and-the-rest) | 2/3 | 15 | none |
| `values:` | [resources](../resources.md#an-operation-that-takes-keys-of-its-own) | 2/0 | 15 | none |
| `#[Variant]` | [resources](../resources.md#variants) | 5/17 | 3 | none |
| `of:`, `at:`, `for:` | as above | 5/17 each | 3 | none |
| `label:` | as above | 4/9 | 3 | none |
| `#[Alters]` | [resources](../resources.md#alters) | 7/29 | 4 | N1 to N4 |
| `of` | as above | 7/29 | 4 | N1 to N4 |
| `operations:` | as above | 1/4 | 10 | none |
| `#[RefinesInput]` | [refinement](../refinement.md#refining) | 15/66 | first, 2 | N1, N4, A |
| `key` | as above | 15/64 | first, 2 | N1, N4, A |
| `watches:` | [refinement](../refinement.md#refining) | 1/2 (1 engine) | - | none |
| `runtime:` | [refinement](../refinement.md#static-and-runtime-refiners) | 2/9 (2 engine) | 4 | N1, N2, A |
| `#[UsesStructure]` | [declaring](../declaring-a-resource.md#on-a-plugin) | 6/11 | 13 | none |
| `structure` | as above | 3/10 | 13 | none |
| `#[DerivesResources]` | [resources](../resources.md#derived-resources) | 2/0 | - | A (first port) |
| `base`, `overriddenBy:` | as above | 2/0 each | - | none |
| `#[DataSurfaceOptionsResolver]` | [options](../options.md#writing-a-resolver) | 5/0 (9 engine) | 2 | A |
| `id`, `label`, `constraint` | as above | 5/0 each | 2 | A |
| `deriver:` | core's plugin convention | 0/0 | - | none |
| `exact:` | [options](../options.md#writing-a-resolver) | 1/0 (1 engine) | 2 | none |
| `#[DataSurfaceWidget]` | [widgets](../widgets.md#writing-a-widget) | 1/0 (10 engine) | - | A |
| `id`, `label`, `weight`, `deriver:` | as above | 1/0, `deriver:` 0/0 | - | A |

### 1.2 Blueprint methods and their arguments

`BlueprintAdditionsInterface` (an alter's, add-only) declares the first
ten, `BlueprintInterface` (the owner's) adds the rest. Both in
`src/Resource/`.

| Method (arguments) | Documented | Uses | First example | Runs |
| --- | --- | --- | --- | --- |
| `add(key, type, label, default)` | [first resource](../first-resource.md), [declaring](../declaring-a-resource.md#the-class) | 129/314; `default:` 59/114 | first, 1 | all |
| `addList(key, item, label, default)` | [declaring](../declaring-a-resource.md#maps-and-lists) | 6/15; `default:` 4/6 | 11 | N4 |
| `addDefinition(key, definition, default)` | [declaring](../declaring-a-resource.md#maps-and-lists) | 3/3 | - | N2 |
| `describe(key, label, description, after, arrangement)` | [resources](../resources.md#alters) | 6/10; `label:` 5/2, `description:` 1/5, `after:` 2/1, `arrangement:` 0/3 | 4 | N1, N2, N4 |
| `attach(key, child, reads)` | [resources](../resources.md#children) | 7/40; `reads:` 3/11 | 3 | N1 to N4 |
| `attachList(key, child, min, max)` | [resources](../resources.md#collections) | 4/10; `min:` 1/1, `max:` 2/4 | 8 | none |
| `mixIn(structure, reads)` | [resources](../resources.md#mixins) | 1/21; `reads:` 0/4 | 8 | none |
| `with(structure, reads)`, deprecated | none | 0/0 | - | none |
| `extendChoices(key, choices)` | [options](../options.md), [refinement](../refinement.md#the-two-roles) | 1/0 (`data_surface_demo_extras`) | - | none |
| `narrow(key)` | [refinement](../refinement.md#declaring-a-rule) | 9/47 | 4 | N2, N3, N4, A |
| `attachVariants(key, by, from)` | [resources](../resources.md#variants), [from the site](../resources.md#variants-from-the-site) | 11/13; `from:` 7/5 | 3; `from:` 12 | none |
| `fromSchema(document)` | [reading a schema](../reading-a-schema.md) | 0/0 | - | none |
| `addEach(key, type, from, default)` | [resources](../resources.md#keys-that-come-from-the-site) | 1/14; `default:` 1/2 | 9 | N3, A |
| `attachEach(key, structure, from, reads)` | as above, [ports on an entry](../resources.md#ports-on-an-entry) | 1/12; `reads:` 1/6 | 9 | none |
| `reads(port, type, required)` | [resources](../resources.md#ports) | 3/6; `required:` 1/3 | 4 | N2, N4 |
| `derive(key, type, label, from, using, when)` | [declaring](../declaring-a-resource.md#computed-keys) | 1 resource (example 15's slug)/8; `when:` 0/2 | 15 | none |
| `addCacheableDependency(dependency)` | [declaring](../declaring-a-resource.md#nothing-live-in-the-blueprint) | 2/0 | - | none |

What `narrow()` returns, `Refinement\Rule`, and the conditions it takes:

| Method | Uses | First example | Runs |
| --- | --- | --- | --- |
| `when(condition)` | 10/40 | 4 | N2, N3, A |
| `unless(condition)` | 0/10 | - | N4 |
| `constrain(constraint, options)` | 2/35 (10 engine, the reader) | 4 | N3 |
| `require()` | 2/13 | 8 | N4 |
| `doesNotApply()` | 4/14 | 7 | N2, N3, N4, A |
| `because(text)` | 4/9 | 4 | none |
| `Condition::present`, `absent`, `equals` | 3/14, 1/8, 5/26 | 8, 4, 7 | N3, N4 |
| `Condition::in`, `atLeast`, `atMost`, `above`, `below`, `not`, `allOf`, `anyOf` | 0 each in submodules; 2 to 3 tests each; the reader uses all | - | N4 (`above`) |

### 1.3 Provider interfaces and their list types

All in `src/Resource/` but the last two, in `src/ResourceBuild/`.

| Name | Documented | Implementations | First example | Runs |
| --- | --- | --- | --- | --- |
| `KeysFromSiteInterface::keys(): KeyedList` | [resources](../resources.md#keys-that-come-from-the-site) | 1/8 (`TicketTypes`) | 9 | N3, A |
| `KeyedList` (`add(key, label, default, locked)`, `fromLabels()`) | as above | `new` 1/5; `fromLabels()` 0/5 | 9 | N3 |
| `VariantsFromSiteInterface::variants(): VariantList` | [resources](../resources.md#variants-from-the-site) | 2/3, and 2 engine (`ComponentProps`, `ComponentSlots`) | 12 | none |
| `VariantList` (`add(value, label, schema)`, `addStructure()`, `addDefs()`) | as above | `addStructure()` 4/0; `addDefs()` engine only | 12, 14 | none |
| `ClosedVariantsInterface::structures()` | [decisions](../decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter), [storage](../storage.md#a-schema-generated-from-the-resource) | 1/0 (`CredentialSources`) | 14 | none |
| `ResourceDeriverInterface::derivatives()` | [resources](../resources.md#derived-resources) | 2/0 (`data_surface_entity`) | - | A (first port) |
| `KnowsDerivativeListTagsInterface` | as above | 2/0 | - | none |
| `DeriverInterface::derive()` (a computed key's) | [declaring](../declaring-a-resource.md#computed-keys) | 1/4 (`Slugify`) | 15 | none |
| `CatalogDoorsInterface` | [catalog command](../catalog-command.md#the-doors) | 1/0, 1 engine | - | none |
| `DerivedVariantsInterface` | [resources](../resources.md#derived-variants) | 1/0 (`data_surface_tool`, two services of one class) | - | none |
| Options resolver plugin and its `exact` flag | [options](../options.md#the-resolver-plugin-type) | 5 submodule, 9 engine resolvers; `exact` 1 and 1 | 2 | A |

### 1.4 Storage interfaces

| Name | Declared | Documented | Implementations | First example | Runs |
| --- | --- | --- | --- | --- | --- |
| `ResourceStorageInterface` | `src/Resource/` | [storage](../storage.md#writing-a-storage-class) | 4/15 directly; every `ConfigObjectStorage` and `ConfigEntityStorage` subclass | - | N1 |
| `ConfigObjectStorage` (base) | `src/Storage/` | [storage](../storage.md#a-simple-config-object) | 15/18 subclasses | first, 1 | N2 to N4, A |
| `ConfigEntityStorage` (base) | `src/Storage/` | [storage](../storage.md#a-config-entity) | 3/1 | 15 | none |
| `DeletableStorageInterface` | `src/Resource/` | [resources](../resources.md#operations-create-update-and-the-rest) | `ConfigEntityStorage` only | 15 | none |
| `KnowsClearableKeysInterface` | `src/Resource/` | [storage](../storage.md#a-schema-the-module-did-not-generate) | the two bases only | - | none |
| `HeldToConfigSchemaInterface` | `src/Resource/` | as above | the two bases only | - | none |
| `StorageLayoutInterface` | `src/Storage/` | [storage](../storage.md#storagelayoutinterface) | 2/0, 1 engine | - | none |
| `AltersStorageLayoutInterface` | `src/Resource/` | [storage](../storage.md#a-layout-for-settings-another-module-mounted) | 1/0 | - | none |
| `StoredValueMigrationInterface` | `src/Resource/` | [evolution](../evolution.md#stored-value-migrations-as-they-will-be) | 0/0, designed, not run | - | none |

### 1.5 Access interfaces

| Name | Declared | Documented | Uses | First example | Runs |
| --- | --- | --- | --- | --- | --- |
| `OperationAccessInterface::access()` | `src/Resource/` | [resources](../resources.md#three-answers) | 6/13 | 10 | none |
| `AltersAccessInterface::access()`, the same signature | `src/Resource/` | [resources](../resources.md#an-alters-answer) | 2/8 (`data_surface_examples_workflow`) | 10 | N4 |
| `AccessAnswer::open()`, `locked()`, `omitted()` | `src/Resource/` | [resources](../resources.md#three-answers) | 5/21, 5/25, 3/16 | 10 | N4 |

### 1.6 Context methods

`OperationContext`, `src/Resource/`, documented in
[resources](../resources.md#operations-create-update-and-the-rest) and
[child operations](../resources.md#child-operations).

| Method or argument | Uses | First example | Runs |
| --- | --- | --- | --- |
| `new OperationContext(...)` | 21/124 | 15 | N1 |
| constructor `known:` | 11/21 | 15 | none |
| constructor `constraints:`, `defaults:`, `children:`, `childOperations:` | 0/1, 0/0, 0/0, 0/0 | - | none |
| `withKnown()` | 2/1 | 15 | none |
| `withConstraint()` | 4/3 | - | none |
| `withId()` | 2/2 | - | none |
| `withChild()` | 2/5 | - | none |
| `withDefaults()` | 0/4 | - | none |
| `stamped()`, `said()`, `childOperation()`, `forChild()` | engine only | - | none |

### 1.7 Settings

`config/schema/data_surface.schema.yml`, `data_surface.settings`.

| Setting | Shipped value | Documented | Uses | Runs |
| --- | --- | --- | --- | --- |
| `reference_enum_limit` | 50 | [options](../options.md#a-key-that-refers-to-an-entity) | 6/1, 1 engine | none |
| `site_list_limit` | 20 | [options](../options.md#a-list-the-site-decides-past-its-budget) | 5/3, 1 engine | none |

Beside them, one bound that is a constant, not a setting:
`ContractEmitter::ENUMERATION_CAP`, 64 combinations per key.

### 1.8 `x-surface` keywords

All written by `Contract\ContractEmitter` (each name appears there) and
documented in [the `x-surface` table](../served-contract.md#x-surface).
"In baselines" is how many of the 33 committed contract baselines
(`tests/fixtures/contracts/`) carry it, then how many fixture documents
of any kind under `tests/fixtures/`.

| Keyword | In baselines / all fixtures | First example | Runs |
| --- | --- | --- | --- |
| `contract`, `operation`, `version` | 33 / 52 to 53 | 1 | N2 |
| `widget` | 0 / 4 (only with the widget hints) | 6 | none |
| `locked` | 33 / 53; 381 times `false`, 4 times `true` | 1 | none |
| `access` (only `"live"`) | 2 / 2 | 10 | none |
| `dependsOn` | 33 / 53; 297 of 385 are `[]` | 1 | N4 |
| `refined`, `stale`, `emptyOption`, `chosen` | 0 / 11, 11, 5, 4 (the schema for these answers) | 2 | N1 |
| `multiple`, `arrangement` | 0 / 0 | 11, - | none |
| `by`, `variants` | 7 / 17, 6 / 12 | 3 | none |
| `fixed` | 0 / 0 | 14 | none |
| `reference` | 1 / 6 | 16 | none |
| `computed` | 1 / 5 | 15 | none |
| `group`, `after` | 2 / 2 | 4 | none |
| `checkedOnServer` | 5 / 13 | 14 | N4 |
| `patternMessage` | 10 / 18 | 4 | none |
| `enumerated` | 5 / 10 | 2 | N1 |
| `refine` | 1 / 1 | 4 | N4 |
| `deferred`, `fetch`, `entries` | 0 / 0 (a site past the limit only) | 12 | none |
| `list` | 6 / 16 | 8 | none |
| `declared`, `rules` | 11 / 21 each, the same 11 baselines | 4 | none |
| `within` | 1 / 7 | 9 | none |
| `ports`, `reads` | 7 / 7, 4 / 4 | 4 | none |
| `live` | 1 / 1 | 4 | N1, N4 |
| `doesNotApply` | 0 / 5 | 7 | none |

### 1.9 Markers

[What a key marked says](../served-contract.md#what-a-key-marked-says-and-what-to-do)
lists five: `enumerated`, `deferred`, `refine`, `live`,
`checkedOnServer`. Two more keywords act as markers and are not in that
table: `declared` (a declared rule narrows the key) and `access: "live"`
(a runtime access answer was set aside). The heading above the first
table is still "Three markers".

### 1.10 Result codes

`Pipeline\Violation`'s constants, plus `has_data` raised by
`data_surface_tool`, documented in [codes](../semantics.md#codes). The
first column counts raise sites in `src/` and `modules/`.

| Code | Raise sites | Note |
| --- | --- | --- |
| `required`, `does_not_apply`, `unknown_key`, `type_mismatch` | 6, 2, 5, 7 | |
| `wrong_variant`, `no_variant` | 2, 1 | |
| `cannot_clear`, `secret_in_item`, `no_schema`, `has_data` | 1 each | no test names `no_schema` or `has_data` by its code |
| `not_valid`, `unresolved` | 1 each | no test names either by its code |
| `access`, `permission`, `locked` (whole call), `not_found`, `conflict` | 3, 2, 2, 5, 1 | |
| `taken`, `computed`, `stale`, `locked` (a note per key) | 5, 3, 2, (as above) | `locked` names two cases |

Beside them, twelve constraint codes read from Symfony (`not_in_range`
to `is_blank`).

### 1.11 Widget words

Three vocabularies name the same elements.

| Element | Plugin id | `x-surface.widget` | `setSetting('widget', ...)` | Uses of the setting |
| --- | --- | --- | --- | --- |
| a select | `options` | `select` | `select` | 0/1 |
| radios | `radios` | `radios` | `radios` | 1/3 (11) |
| checkboxes | `checkboxes` | `checkboxes` | `checkboxes` | 0/3 |
| a time input | `time` | `time` | `time` | 1/2 (11) |
| a checkbox | `boolean` | `checkbox` | none | |
| a fieldset, a details | `map` | `fieldset` | none | |
| a text field, an email, a text area | `string` | `text`, `email`, `textarea` | none | |
| a number | `number` | `number` | none | |
| rows of free values, a collection | `list` | `list` | none | |
| an entity autocomplete | `autocomplete` | `autocomplete` | none | |
| one value shown, a key with variants | none | `fixed`, `variants` | none | |

Runs: N3 and N4 used `radios` and `time`; A wrote a widget of its own.

### 1.12 The count

| Kind | Count today |
| --- | --- |
| Attributes | 11 (9 authoring, 2 plugin) |
| Attribute arguments | 42 (33 authoring, 9 plugin) |
| Blueprint methods | 17, with 52 arguments; and 6 rule methods, 11 conditions |
| Interfaces an author implements or names | 24 |
| Value classes an author builds | 10 (`KeyedList`, `VariantList`, `AccessAnswer`, `OperationContext`, `DerivedResource`, `DerivedOperation`, `ResourceCallable`, `Derive`, `Arrangement`, `ArrangementGroup`) |
| `OperationContext` public methods | 9, constructor arguments 7 |
| Settings | 2 |
| `x-surface` keywords | 35 |
| Markers | 5 in the table, 7 in effect |
| Module result codes | 20 (21 rows: `locked` twice) |
| Widget words | 18 distinct across the three vocabularies |
| Concepts, rows of [concepts](../concepts.md) | 49, four marked for the first page |

## 2. Findings

### 2.1 "A list the site decides" is said seven ways

| What the site lists | Who lists it | Interface and list type | Past its limit |
| --- | --- | --- | --- |
| the values a key allows | an options resolver plugin | `DataSurfaceOptionsResolverInterface`, `OptionSet` | no limit: always a `oneOf` |
| the entities a reference key allows | core's selection handler | none of the module's | `reference_enum_limit`: an id, looked up |
| one key per thing | a provider named by `from:` | `KeysFromSiteInterface`, `KeyedList` | `site_list_limit`: `deferred` |
| one variant per value | a provider named by `from:` | `VariantsFromSiteInterface`, `VariantList` | `site_list_limit`: `deferred` |
| one variant per value, every structure named ahead | the same, closed | `ClosedVariantsInterface` | as above |
| one variant per value, from config schema | a tagged service that names the key from outside | `DerivedVariantsInterface` | not counted |
| one resource per bundle or field | a deriver at discovery | `ResourceDeriverInterface` | not counted |

The two provider interfaces have one method each, the same contract
(asked at every build, cacheable, not varying by account, refused
otherwise) and doc comments that differ only in their nouns. Their list
types differ in what an entry carries: an id, label, start and lock for
keys, a value, label and schema or structure for variants. The
budget already treats them as one (`site_list_limit` counts both), and
the decisions call both "a list the site decides".
`DerivedVariantsInterface` is the same idea as variants from the site,
pushed in from outside (`variantsAt()` names the owner's class and key)
rather than named by the owner's `from:`, and it escapes the budget
([the budget's open note](../decisions.md#a-budget-for-what-the-site-decides)).
`BlockPluginStructures` already does what it does, through the provider:
it reads `block.settings.<id>` config schema into a variant's JSON Schema.

A closed provider names each structure twice: in its static
`structures()` and again in `variants()` through `addStructure()`
(`CredentialSources`, lines 61 to 76).

### 2.2 One word, two senses; a name against its siblings

- **"Deriver" names two interfaces.** `DeriverInterface` computes a
  key's value; `ResourceDeriverInterface` makes resources. The same page
  calls both "a deriver"
  ([declaring](../declaring-a-resource.md#names) and
  [computed keys](../declaring-a-resource.md#computed-keys)). The blueprint
  verb is `derive()`, its constant class `Derive`; the engine's class is
  `Computed`, the wire says `x-surface.computed`, the code is `computed`,
  and the concept row and the vocabulary say "computed key". "Derived"
  also names derived resources, derived variants, derived tools and a
  kind "derived, never declared".
- **`#[Operation(values:)]` holds a structure class.** `values` is
  already the tool's input, the envelope's key and the result's key.
  The argument names a structure whose keys a call takes; the decision
  calls it "keys of its own".
- **`reference_enum_limit` beside `site_list_limit`.** Two patterns for
  two siblings, and "enum" names a keyword the module never writes:
  a list of candidates is a `oneOf` ([the schema](../served-contract.md#the-schema),
  "never `enum`").
- **Widget plugin ids against the hint words.** `options` draws `select`,
  `map` draws `fieldset`, `boolean` draws `checkbox` (section 1.11). A
  module's own widget answers to its plugin id, so the stock ones are
  the exceptions.
- **`list` is two things on the wire.** `x-surface.widget: list` is rows
  of free values or a collection; `x-surface.list: true` marks a
  collection.
- **The Rule's docblock names a method that is gone.** `Rule` lists
  "`describe($text)`: says why"; the method is `because()` since the
  2026-10-20 rename.
- **Stale words left by today's change.** `config/install/data_surface.settings.yml`
  says a list past `site_list_limit` is "marked refine"; it is `deferred`.
  The served contract's heading "Three markers" stands over four, with a
  table of five below it.
- **Concept rows that name what no example shows.** Row 8 names
  `DoesNotApply::here()`, which no submodule calls: examples 7 and 14
  use the declared rule's `doesNotApply()`. Row 22 cites examples 4 and
  14 for `describe()` and `extendChoices()`; both call the first, and
  only `data_surface_demo_extras` calls the second.
  Row 45 cites example 12 for `fromSchema()`, which example 12 does not
  call. Row 27 is "Settings layout"; the glossary word is Layout.
- **"Closed" beside "always open".** The glossary says variants are
  always open; `ClosedVariantsInterface` closes the set of structures a
  provider may list, not the key (a `#[Variant]` still wins there).
- **The glossary and the working notes diverged.** The README glossary
  has "Keys from the site" and no "Variants from the site", no
  "Computed key", no "Reference key", no "Markers"; the working notes'
  vocabulary has all of them.

### 2.3 No shipped use outside a fixture

| Item | Submodules/tests | Note |
| --- | --- | --- |
| `fromSchema()` | 0/0 | Untested public method. Its page shows a snippet no test pins. |
| `with()` | 0/0 | Deprecated alias of `mixIn()`. |
| `#[Operation(of:)]` | 0/3 | An alter adding an operation to a resource it does not own. |
| `#[Operation(default:)]` | 0/3 | A child's default operation; the ids rule now names a default per kind. |
| `#[RefinesInput(watches:)]` | 1/2, 1 engine | Both shipped uses restate their parameter names (`DemoBlockStructure::fieldOf()`, `EntityReferenceStructure`). |
| `OperationContext::withDefaults()` and the constructor's `constraints:`, `defaults:`, `children:`, `childOperations:` | 0/4, 0/1, 0/0, 0/0, 0/0 | The withers are the spelling every caller uses. |
| `AltersOutputsInterface` | 0/1 | The `third_party_outputs` mount. |
| `describe(arrangement:)` | 0/3 | An alter placing an owner's key in a group. |
| `mixIn(reads:)` | 0/4 | |
| `derive(when:)`, `Derive::ON_CHANGE` | 0/2, 0/1 | |
| `Rule::unless()` | 0/10 | N4 used it, from the recipes. |
| `KeyedList::fromLabels()` | 0/5 | |
| `#[Resource(version:)]`, `#[Structure(version:)]`, `StoredValueMigrationInterface` | 0/0 | Refused above 1 until migrations are built. |
| `#[DataSurfaceOptionsResolver(deriver:)]`, `#[DataSurfaceWidget(deriver:)]` | 0/0 | Core's plugin attribute convention. |

### 2.4 What another concept absorbs with no loss

- **Derived variants** into variants from the site (2.1).
- **Reading a schema in a class**, `fromSchema()`, into variants from
  the site, whose entries are read from a schema each; the reader stays
  as `SchemaReader::structure()` and through `VariantList`.
- **`AltersAccessInterface`** into `OperationAccessInterface`: the same
  method and signature. The role, owner or alter, is what `#[Alters]` on
  the class already says; only how a core result is read differs by
  role (neutral is open for an alter), and the engine knows the role.
- **`#[Operation(default:)]`** into the standard ids rule: a child is
  invoked through the operation its context names, else the one with
  its parent's id, else, today, `default:`. With the ids standard
  (`create`, `update`, `read`, `delete`, `configure`) the last step can
  be "the default of the parent's kind".
- **`watches:`** into the parameter names, which seal already holds to
  declared keys.
- **Concept rows.** Row 42 ("Child operations, `withChild()`") repeats
  row 11, which names `withChild()`. Row 29 ("Transformations") says of
  itself "No concept of its own".

### 2.5 Wire keywords that say nothing, or say it twice

- **`locked` beside `readOnly`.** Every locked key is `readOnly: true`;
  the one `readOnly` key that is not locked is computed, which
  `x-surface.computed` marks. `locked` is written on every key: 381 of
  385 times in the baselines it says `false`.
- **Always written when empty.** `dependsOn: []` 297 times, and in the
  schema for these answers `refined: false` and `stale: false` on every
  key, where `enumerated`, `refine`, `deferred` and `live` are written
  only when true. The module's own rule for requiredness ("appears only
  when it says something") is not applied to its keywords.
- **`declared` beside `rules`.** The two appear in exactly the same 11
  baselines. `rules` on the frame lists the conditionals that state a
  declared rule; `declared` on the key repeats that a rule narrows it.
  The React app tests `enumerated || declared` together in three places
  and calls `rulesOf()` once it sees `declared`; `SchemaResolver` already
  rebuilds a conditional's own `declared` from `rules`.
- **`deferred`, `fetch`, `entries`.** `fetch` and `entries` appear only
  beside `deferred`. Three keywords for one state, added today, in no
  committed baseline.
- **`access: "live"` beside `live: true`.** One keyword with one value,
  and the same word as the runtime refiner's marker. Both tell a client
  the key's final state is per request and the schema for these answers
  settles it.
- **Kept on purpose.** `by` and `variants` say different things (the
  deciding key, the value-to-def map) and `by` stands alone past the
  limit. `fixed` carries the label a `const` cannot. `ports` and `reads`
  are the two ends of a wiring.

### 2.6 The candidates, one by one

| Candidate | Recommendation | Cost | Wire under contract 3? |
| --- | --- | --- | --- |
| One provider interface for keys and variants, one list type | **Merge** (B1) | M | No |
| `ClosedVariantsInterface` folded into it as an optional method | **Merge as a sub-interface** (B1): PHP has no optional interface method; a closed list's entries name a value and label, the structure read from `structures()` | in B1 | No |
| `addEach`, `attachEach`, `attachVariants(from:)` as one verb | **Keep three** (E1): they are `add()`, `attach()` and `attachVariants()` with `from:`, and `from:` is the one shared spelling; one verb with a mode would undo the add and attach split the first page teaches | none | No |
| The two limits as one setting | **Keep two, one name pattern** (A4, E2): a reference past its limit is looked up by text, a list past its limit is fetched per choice | S | No |
| `exact` on resolvers versus the marker | **Keep** (E3): only the resolver knows its list is the whole constraint; on the wire it is the absence of a `checkedOnServer` entry, not a marker | none | No |
| `values:` versus a structure attached | **Keep the argument, rename it** (A3, E4): attaching would store a page's paging keys with the venue | S | No |
| `derive` as `add` with `computed:` | **Rename, do not merge** (A2): three more arguments on the verb the first page teaches, with refusals of its own | S to M | No |
| `describe` on the blueprint versus on a rule | **Keep both, fix the docblock** (A1, E5): the rule's is `because()`, for while its condition holds | S | No |
| `because` and `unless` | **Keep** (E6): `because()` has 4 shipped uses; `unless()` saves a double negative and N4 reached for it | none | No |
| `addDefinition` versus `add` | **Keep** (E7): folding widens `add()`'s return to the interface, which has no setters | none | No |
| `extendChoices` | **Keep** (E8): the one widening verb, decided | none | No |
| `withDefaults` | **Drop** (C5) | S | No |
| `overriddenBy` | **Keep** (E9): 2 shipped uses, read the right way since 2026-10-20 | none | No |
| `#[Alters(of:)]` | **Keep** (E9): matches `#[Variant(of:)]` | none | No |
| `UsesStructure` versus `Structure` on a plugin | **Keep** (E10): `#[UsesStructure]` is what the plugin definition carries; `#[Structure]` there only gives an id | none | No |
| `DerivesResources` versus the providers | **Keep** (E11): discovery-time resources with operations against build-time keys, decided | none | No |
| `KnowsClearableKeysInterface` as part of `ResourceStorageInterface` | **Keep optional** (E12): folding adds a method answering NULL to 4 direct and 15 test storages | none | No |
| Access classes versus `AltersAccessInterface` | **Merge** (B3) | S | No |
| The widget words | **Rename three plugin ids** (A5) | S | No |
| `locked` beside `readOnly` | **Drop `locked`** (D3) | M | Yes |
| `enumerated` and `declared` | **Drop `declared`** (D1); `enumerated` stays, it says something `rules` does not | S to M | Yes |
| `variants` and `by` | **Keep** (E15) | none | |
| `entries` (and `fetch`) | **Fold into `deferred`** (D2) | S | Yes, but added today |
| Result codes that overlap | **Keep** (E14): `locked` is a whole-call refusal or a note, told apart by where it sits, as `computed` and `stale` are; `access` and `permission` tell a caller different things | none | |

### 2.7 Names the 2026-10-20 synthesis deferred, today

| Synthesis row | Status | Still stands? |
| --- | --- | --- |
| `ResourcesInterface::buildStatic()` to `buildForAccountAlone()` ("do") | not done, 61 references | Weakly: "static" now also names the static access answers it builds with. Internal. |
| `Rule::require()`, `requires()`, `rulesOut()` to `setRequired()`, `isRequired()`, `isRuledOut()` ("do") | not done | The verb, no: `->when()->require()` reads as the sentence the other effects make. The two getters, yes, internal. |
| Output `committed` labeled "Written" to "Committed" ("do") | not done (`OperationInputs.php:815`) | Yes (A6). |
| contract-diff `--dir` to `--baselines` ("do") | not done | Yes (A6). |
| `_data_surface_operation_access: 'TRUE'` implied by `_data_surface_resource` ("do") | not done; 43 routing entries carry it, the first page among them | Yes (B5). |
| `PlainTextMarkup` to `TranslatablePlainText` ("do") | not done | Yes, internal, low. |
| `Venues::VENUES` to `venues()` with `t()` ("do") | `venues()` added, the constant kept | Yes, example only. |
| `childrenMayAnswer()`, `ENUMERATION_CAP`, `remember()` renames ("do") | not done | Yes, internal, low. |
| Glossary: Variant, Owner, Child, Mount ("do") | Variants added; Owner, Child, Mount not | Partly: B1 changes the glossary anyway. |
| `DoesNotApply::here()`, `NotEmitted::value()` as enum cases ("do later") | not done | Low. |
| `AccessAnswer`, `kindOf()`, `OperationDefinition::kind()` as backed enums ("do later") | `getAnswer()` done | Low. |
| `DefinitionMetadata::setSecret()` to `setWriteOnly()` ("do later") | not done | No: the concept, the code `secret_in_item` and the docs say secret. Withdraw. |
| `DataSurfaceAccess::decisive()` to `refuseNeutral()` ("do later") | not done | Internal, low. |
| `ContractEmitter::emit()` to `emitForAnswers()` ("do later") | not done | No: `emit()` and `emitFull()` read as a pair. Withdraw. |
| `#[UsesStructure]` also accepting `self::class` ("do later") | not done | No: a second spelling, against this pass. Withdraw. |

Done since: `mixIn()`, `#[Variant(of:, at:, for:)]`, the storage and
layout renames, `addDefinition()` returning the definition, License, the
catalog's spelling, `NotEmitted`, `withDefaults()`, `#[Alters(of:)]`,
`Rule::because()`, `overriddenBy:`, the pipeline storages moved,
`x-surface.operation.readOnly` dropped, `checkedOnServer` items, no
`@access` path, `resource` and `operation` for the contract keys.

## 3. The recommendation

**Concepts.** 49 rows of [concepts](../concepts.md) today, 44 if every
item below is approved. The first page meets four before and after:
resource, operation, storage and `add()`. No item touches
`docs/first-resource.md` but B5, which takes one route requirement line
off it and adds nothing.

**Public names.** 195 today, 178 after (177 with D5):

| Kind | Before | After |
| --- | --- | --- |
| Attributes | 11 | 11 |
| Attribute arguments | 42 | 39 |
| Blueprint methods | 17 | 15 |
| Interfaces | 24 | 20 |
| `OperationContext` public methods | 9 | 8 |
| Settings | 2 | 2 |
| `x-surface` keywords | 35 | 31 (30 with D5) |
| Module result codes | 20 | 20 |
| Rule methods, conditions | 17 | 17 |
| Widget words | 18 | 15 |
| Total | 195 | 178 (177 with D5) |

Each item: what, why, cost, and the concept count once it and every
item above it are approved.

### A. Renames, no behavior change

**A1. Make the docs say what the code does.**
What: fix the `Rule` docblock (`because()`), the install file's comment
(`deferred`), the heading "Three markers" (to "Markers"), concept rows 8
(lead with `narrow()->...->doesNotApply()`), 22 (no numbered example
calls `extendChoices()`), 27 ("Layout") and 45 (example 12 does not call
`fromSchema()`).
Why: each says something false today.
Cost: S, docs and one comment; no test pins any of it.
Concepts after: 49.

**A2. `derive()` to `compute()`.**
What: `BlueprintInterface::derive()` to `compute()`, `DeriverInterface`
to an interface named for computing (for example `ComputesValueInterface`
with a static `compute(array $from)`), `Derive::ON_CREATE` and
`ON_CHANGE` to `Compute::ON_CREATE` and `ON_CHANGE`,
`PipelineGuard::guardDeriver()` to match.
Why: "deriver" then means one thing, a class that makes resources at
discovery; the verb agrees with the engine (`Computed`), the wire
(`x-surface.computed`), the code (`computed`) and the concept row.
Cost: S to M. One shipped resource (example 15) and `Slugify`, 8 test
call sites and 4 test classes, the PHPStan rule that flags `\Drupal::`
in a deriver, [declaring](../declaring-a-resource.md#computed-keys),
[transformations](../transformations.md#a-computed-key-a-value-of-the-resource-that-follows-others),
concept row 24 and the Namespaces table, the working notes, one
decision entry. Not a wire change: no keyword or code moves.
Concepts after: 49.

**A3. `#[Operation(values:)]` to `#[Operation(structure:)]`.**
What: rename the argument, `Operation::$values` and
`OperationDefinition::$values`.
Why: `values` is the tool's input and the envelope's key; the argument
holds a structure, the glossary's word for a group of keys held by
something else.
Cost: S. Two shipped uses (example 15's listing and reorder), no test
fixture, [resources](../resources.md#an-operation-that-takes-keys-of-its-own),
concept row 12, the working notes, one decision entry. Not wire.
Concepts after: 49.

**A4. `reference_enum_limit` to `reference_list_limit`.**
What: rename the setting, with a post-update that moves the stored
value, beside `site_list_limit`.
Why: one pattern for the two siblings, and no "enum" for a list the
schema states as `oneOf`.
Cost: S. Config schema, install file, a post-update, 6 submodule and 1
engine reference, [options](../options.md#a-key-that-refers-to-an-entity),
the served contract's schema table, concept row 34, three decision
entries. Not wire: no document names the setting; the tool text says
the number.
Concepts after: 49.

**A5. Three widget plugin ids to the hint words.**
What: `options` to `select`, `map` to `fieldset`, `boolean` to
`checkbox`.
Why: one word per element across plugin id, hint and setting; a
module's own widget already answers to its id. `string` stays: it draws
three hints.
Cost: S. Three plugin attributes and `answersTo()`, tests that name
the ids, [the stock widgets](../widgets.md#the-stock-widgets); an
adopter's `hook_data_surface_widget_info_alter()` keyed by an old id
breaks. Not wire.
Concepts after: 49.

**A6. The two synthesis labels still standing.**
What: the tool's `committed` output labeled "Committed"; contract-diff
`--baselines`, `--dir` kept.
Why: name and label agree; the option says what it holds.
Cost: S. Not wire (a label, an option).
Concepts after: 49.

### B. Merges, behavior preserved, fewer names

**B1. One provider for a list the site decides.**
What: `KeysFromSiteInterface` and `VariantsFromSiteInterface` become
one interface (for example `SiteListInterface::entries(): SiteList`),
`KeyedList` and `VariantList` one list type carrying, per entry, an id,
a label, and optionally a start, a lock, a schema or a structure, and
the defs; `addEach()`, `attachEach()` and `attachVariants(from:)` each
refuse what they do not read, in the words they use today.
`ClosedVariantsInterface` becomes the closed form of the one interface
(for example `ClosedSiteListInterface`), keeping its static
`structures()`; its entries name a value and a label, the structure read
from `structures()`, so it is named once.
Why: one interface, one list type and one row for one idea, which the
budget, the decisions and `site_list_limit` already treat as one; the
closed provider stops repeating each structure.
Cost: M. Three interfaces become two, two list types one. Six shipped
providers (`TicketTypes`, `ComponentProps`, `ComponentSlots`,
`BlockPluginStructures`, `ConditionPluginStructures`,
`CredentialSources`) and 11 test providers;
[keys](../resources.md#keys-that-come-from-the-site) and
[variants](../resources.md#variants-from-the-site) from the site, the
recipes, the glossary (one row for both), concept rows 43 and 44 (one
row), the Namespaces table, the working notes, two decision entries.
What is lost: a type-level split between a keys entry and a variants
entry, which becomes a build refusal. Not wire.
Concepts after: 48.

**B2. Derived variants through the provider.**
What: `data_surface_tool`'s `FieldSettingsSchemaVariants` becomes a
provider named by `attachVariants('settings', by: ..., from: ...)` on
`FieldInstanceResource` and `FieldStorageResource`, reading config
schema into JSON Schema as `BlockPluginStructures` does;
`DerivedVariantsInterface`, `DerivedVariants` and the
`data_surface.derived_variants` tag go.
Why: a second mechanism for the same idea, named from outside the
owner, and not counted by the budget.
Cost: M, and **not behavior-free**: the field types become subject to
`site_list_limit`, so a site offering more than 20 field types in the
UI would see the field tools' settings `deferred`; the variants become
read structures with def ids of their own, so the field tool listing
and `COMPARISON.md` move. Decide whether that is wanted before approving.
Concepts after: 47 (row 47 folds into the row B1 makes).

**B3. One access interface.**
What: an alter that answers access implements `OperationAccessInterface`;
`AltersAccessInterface` goes. A class carrying `#[Alters]` is read as an
alter's answer (neutral is open, forbidden is omitted), as today; one
named on `access:` that also carries `#[Alters]` is refused.
Why: the same method and signature under two names; the role is what
`#[Alters]` says.
Cost: S. Two shipped alters (`data_surface_examples_workflow`), 8 test
classes, the discovery check, [an alter's answer](../resources.md#an-alters-answer),
concept rows 13 and 20, the glossary's Alter and Access answer, the
working notes, one decision entry. Not wire.
Concepts after: 47.

**B4. Two concept rows that are not concepts of their own.**
What: fold row 42 (child operations) into row 11, which names
`withChild()`; move row 29 (transformations) into the page's opening
paragraph as a pointer.
Why: row 42 repeats row 11; row 29 says it has no concept of its own.
Cost: S, `docs/concepts.md` only.
Concepts after: 45.

**B5. The operation route's access requirement implied.**
What: `OperationAccessCheck` applies to every route naming
`_data_surface_resource`; the explicit `_data_surface_operation_access:
'TRUE'` stays accepted.
Why: 43 routing entries carry a line whose value says nothing; the
first page shows it and explains it.
Cost: S. A route that names a resource and meant to skip the check
(none found) would gain it. `docs/first-resource.md` loses two lines and
its pin test changes with it; the first page's concepts stay four.
Concepts after: 45.

### C. Drops, no shipped use

Each is additive to bring back later, so dropping before a first tag
costs nothing outside this repository.

**C1. `BlueprintAdditionsInterface::with()`.**
What: remove the deprecated alias of `mixIn()`.
Why: no caller anywhere, tests included.
Cost: S, one method and its implementation.
Concepts after: 45.

**C2. `BlueprintInterface::fromSchema()`.**
What: remove it, and the "In a class" way in on
[reading a schema](../reading-a-schema.md); fold row 45 into the row B1
makes. Or, if the owner wants it, keep it and add the test it lacks.
Why: no caller and no test; the reader stays reachable through a list
entry's schema and `SchemaReader::structure()`.
Cost: S.
Concepts after: 44.

**C3. `#[RefinesInput(watches:)]`.**
What: remove the argument; the parameter names are the one spelling.
Why: both shipped uses restate their parameter names, and seal already
refuses a parameter naming no declared key.
Cost: S. Two shipped and two test uses, [refinement](../refinement.md#refining),
[declaring](../declaring-a-resource.md), [pattern](../pattern.md). A key
whose name is no PHP identifier could then not be watched; none is.
Concepts after: 44.

**C4. `#[Operation(default:)]`.**
What: remove it; a child with no operation named for it and none with
its parent's id is invoked through the default of the parent's kind,
by the standard ids rule, else sees its parent's context.
Why: no shipped use; the ids decision made the default of a kind a rule.
Cost: S to M. `DerivedOperation::$default` with it, three fixtures, the
child operations section, one decision entry. A behavior change only for
a child whose default is a custom id; no shipped one is.
Concepts after: 44.

**C5. `OperationContext::withDefaults()` and four constructor arguments.**
What: remove `withDefaults()`; keep the constructor to `id`, `creates`
and `known`, the rest reached through the withers.
Why: no shipped use of any of them; no shipped operation starts a new
thing from values (a clone).
Cost: S. Four test uses, concept row 11, three decision entries
("Starting values ...").
Concepts after: 44.

**C6. `#[Operation(of:)]`.**
What: operations stand only on their resource's or structure's class.
Why: no shipped module adds an operation to a resource it does not own.
Cost: S. The alter path in `ResourceRegistry::discover()`, three
fixtures, the attribute's docblock.
Concepts after: 44.

**C7. `AltersOutputsInterface` and the `third_party_outputs` mount.**
What: remove them.
Why: no shipped use, one test.
Cost: S to M: `describe()`'s output path, the emitter's outputs mount,
[contributing an output](../outputs.md#contributing-an-output). Marked
undecided below.
Concepts after: 44.

### D. Wire changes, batched into contract 4

Contract 3 was amended in place today (`deferred` replaced `refine`
before any tag). If no release has carried contract 3, the owner may
amend it in place again for D2, which is younger than any client; the
rest belong in one contract 4 with `ContractDiff` taught each move.

**D1. Drop `x-surface.declared`.**
What: the frame's `rules` alone says which conditionals state a
declared rule; a client finds the keys each narrows in its `then`.
Why: the two always travel together; the app already evaluates
`enumerated || declared` as one case.
Cost: S to M. The emitter (`ContractEmitter::frame()` writes it),
the app's `contract.ts`, 11 baselines regenerated, the `x-surface` table, [reading a
schema](../reading-a-schema.md#our-own-keywords).
Concepts after: 44.

**D2. `fetch` and `entries` inside `deferred`.**
What: `"deferred": {"fetch": "...", "entries": [...]}` in place of three
keywords.
Why: neither appears without `deferred`; one state, one keyword.
Cost: S. The emitter, `SiteListBudget`, the app, `SiteListBudgetTest`,
the served contract's table and its fetch section; no baseline carries
them.
Concepts after: 44.

**D3. Drop `locked`; write the rest only when they say something.**
What: `readOnly` says locked, `computed` the one exception;
`dependsOn`, `refined` and `stale` are written only when non-empty or
true, as `enumerated`, `refine` and `live` are.
Why: 381 `"locked": false` and 297 `"dependsOn": []` in 33 baselines say
nothing.
Cost: M. The emitter, the reader, the OpenAPI document, the app's types
(`locked`, `dependsOn`, `refined`, `stale` become optional) and every
read of them, every baseline and fixture document regenerated, tests
that assert `locked: false`.
Concepts after: 44.

**D4. `x-surface.list` to `collection`.**
What: rename the collection's marker.
Why: `list` is also the widget hint for rows of free values.
Cost: S. The emitter, the reader, the app, 6 baselines.
Concepts after: 44.

**D5. `access: "live"` into `live`.**
What: one marker for "settled per request", whether a runtime refiner or
a runtime access answer set it.
Why: a keyword with one value, beside the same word.
Cost: S. The app branches on each today. Marked undecided below.
Concepts after: 44.

### E. Leave as is

- **E1.** `addEach()`, `attachEach()`, `attachVariants(from:)` stay three
  verbs (2.6).
- **E2.** Two limits stay two settings, under one pattern after A4.
- **E3.** `exact` on resolvers stays (2.6).
- **E4.** `values:` stays an operation argument, renamed by A3.
- **E5.** `describe()` and `because()` stay: a permanent rewording, and
  words while a condition holds.
- **E6.** `because()` and `unless()` stay.
- **E7.** `addDefinition()` stays beside `add()`.
- **E8.** `extendChoices()` stays, the one widening verb.
- **E9.** `overriddenBy:`, `#[Alters(of:)]` and `operations:` stay.
- **E10.** `#[UsesStructure]` stays beside `#[Structure]` on a plugin.
- **E11.** `#[DerivesResources]` stays beside the providers.
- **E12.** The storage interfaces stay optional and apart.
- **E13.** `version:` and `StoredValueMigrationInterface` stay: removing
  the argument and adding it back would break every class that says it.
- **E14.** The result codes stay.
- **E15.** `by` and `variants` stay.
- **E16.** `describe(arrangement:)`, `mixIn(reads:)`, `derive(when:)`
  and `KeyedList::fromLabels()` stay: no shipped use, but each is the
  decided spelling of a case its decision names, and none is a word a
  newcomer meets.
- **E17.** The conditions with no submodule use stay: the reader maps
  JSON Schema conditionals onto them.

### The order, by payoff over cost

1. A1, the docs say what the code does.
2. C1, `with()`.
3. A2, `derive()` to `compute()`.
4. D2, `fetch` and `entries` inside `deferred`, while nothing reads them.
5. B1, one provider for a list the site decides.
6. C2, `fromSchema()`.
7. D1, `declared`.
8. A3, `values:` to `structure:`.
9. B3, one access interface.
10. A4, `reference_list_limit`.

Then A5, A6, B4, B5, C3 to C6, D3, D4; B2, C7 and D5 once decided.

### Not decided here

- **B2's behavior change**: whether the field types should count
  against `site_list_limit`.
- **C7**: whether alters adding outputs is a capability to keep without
  a user.
- **D5**: the app treats a live access answer and a live refiner
  differently today; merging is right only if no client needs to tell
  "may vanish" from "may narrow".
- **The names** in A2 and B1 (`ComputesValueInterface`,
  `SiteListInterface`, `ClosedSiteListInterface`) are placeholders for
  the owner's choice; what matters is the stem.
- **Resolver lists have no budget.** A resolver's list is always a full
  `oneOf` (a country list is some 250 entries), while references and
  site lists have limits. Whether a resolver's list should count against
  `site_list_limit` is outside this pass.
