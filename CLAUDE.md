# Data Surface — working notes

A data surface is an immutable, sealed group of core data definitions,
built once from a resource or structure and read by every caller: a
generated form renders it, a pipeline accepts and validates values
against it, and storage writes them. The rule the whole module turns on
is that refinement may only *narrow*, so what a data surface advertised
when sealed stays true afterwards.

Where to look: **`docs/`** is the current documentation, and the only place
kept true (start at `docs/index.md`; the pattern is `docs/pattern.md`, the
build step `docs/how-it-fits.md`, every decision the pattern left open
`docs/decisions.md`). **`ROADMAP.md`** is what is next.
**`PLAN.md`**, **`ADOPTION.md`**, **`HARDENING.md`** are design history —
read them for why, never as a description of today. **`REWORK.md`** is
the record of the rework that brought the module to the pattern.

## Vocabulary

- **Resource**: a thing with identity and storage that operations read
  and write; the form, the tool and the schema are derived from it. It
  may live in a config object, a config entity, State, a plugin's
  settings or a content entity, and only its storage knows which (the
  registration settings first, a content type, then a basic page,
  `entity.content:node.page`, the same shape applied to content). One
  with no identity keys, such as a settings object, is a singleton
  resource. `#[Resource(id, identity:, storage:, access:)]`.
- **Structure**: a group of keys with no identity or storage of its
  own, held by a parent (attached or as a variant) or a plugin's host.
  `#[Structure(id, access:)]`.
- **Shape**: a named type (Smithy's word), of three kinds: resources,
  structures, and named scalar shapes. The third is `#[Shape(id)]` on a
  class in `src/Shape/` whose static `define()` returns one scalar
  `DataDefinition` (no default, not required), used as
  `$inputs->add('license', License::class, t('Event license'))`: the key
  may only narrow it (checked at build), a port may read it
  (`reads('code', Sku::class)`, wired only to a key of that shape), an
  `#[Alters(License::class)]` tightens it at every key with
  `narrow('<id>')` and `describe('<id>')`, and both documents write it
  once as `$defs.<id>`, each key `$ref` first with what it adds beside
  (`docs/declaring-a-resource.md#named-shapes`).
- **Operation**: a way a resource is asked for, `#[Operation]`, its
  static facts on the attribute: id, label, `description:` (the tool's
  first sentence and both documents' top `description`), permission and
  `creates:`. On the class, repeatable, when the context needs nothing
  more (the default; no method); on a static method returning an
  `OperationContext` when it needs more, the attribute's id and
  `creates` stamped on it and a context saying otherwise refused
  (`docs/decisions.md#an-operation-on-the-class`). It states how a call
  behaves, `readOnly:` and `destructive:`, and idempotence, derived
  from whether it creates unless declared (a create whose caller names
  the thing declares it), and its kind, `create`, `update`, `read` or
  `delete` (`deletes:`, which takes no values), always derived from the
  attribute. The default operation of a kind has the kind's name as its
  id (`create`, `update`, `read` or `list`, `delete`; `configure` for an
  update on a singleton), its label Drupal's word ("Add a venue"); an
  id `add`, `edit`, `remove` or `get` is refused at discovery
  (`docs/decisions.md#the-ids-are-the-standard-five-the-labels-are-drupals`).
  One about the things together names the structure whose keys it
  takes in place of the resource's, `values:` (a listing's page, a
  reorder's list; `docs/resources.md#an-operation-that-takes-keys-of-its-own`).
  A create's tool requires what the resource requires, an update's
  requires nothing at its door
  (`docs/decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door`); a tool
  carries the facts to the Tool API and both documents state them under
  `x-surface.operation`
  (`docs/decisions.md#an-operation-states-how-it-behaves`).
- **Blueprint**: what a resource or structure declares its members
  into, handed to `defineInputs()` and `defineOutputs()`
  (`BlueprintInterface`); an alter gets the add-only
  `BlueprintAdditionsInterface`.
- **Storage**: where a resource's values live: `load()`, `prepare()`,
  `commit()` on a `ResourceStorageInterface`.
- **Layout**: how a storage lays accepted values out where that differs
  from how they are asked for, `StorageLayoutInterface`; an alter hands
  one for its mount with `AltersStorageLayoutInterface::storageLayout()`.
- **Alter**: another module's class that adds keys to a resource or
  structure and narrows it, `#[Alters]` in `src/Alter/`; one that
  implements `AltersAccessInterface` answers access for it too, after
  the owner, the stricter standing
  (`docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands`).
- **Attach**: a fixed child at a key, `attach()`; an alter attaches only
  at its own mount, `third_party_settings.<module>`, as an adapter.
- **Port**: a value a structure reads from its container, declared under
  a name of its own with `$inputs->reads('capacity', 'integer')` and
  wired by the container with `attach(..., reads: ['capacity' =>
  'seats'])`; a live sibling value, never stored by the structure.
- **Mixin**: a structure whose members are merged into the owner's own
  keys, `$inputs->mixIn(TimestampsStructure::class, reads: [...])`:
  stored flat, refined in the owner's frame, emitted as `allOf` of its
  def and the owner's properties; the owner's only, never an alter's.
  Nest for a thing of its own, mix in for shared members that are part
  of this thing (`docs/resources.md#mixins`).
- **Variants**: a tagged union whose tag is a sibling key: structures
  that key chooses between, attached at one key,
  `attachVariants($key, by: $sibling)`. Always open: the parent names
  none, any module may declare one.
- **Variant**: one structure (or resource) among the variants, for one
  value of the deciding key, `#[Variant(of:, at:, for:)]`.
- **Variants from the site**: variants a provider lists rather than
  classes declare, `attachVariants($key, by: $sibling, from:
  Provider::class)`; the provider implements `VariantsFromSiteInterface`,
  whose `variants()` answers a `VariantList` (per value, its label and
  the JSON Schema of what it chooses), asked at every build and refused
  uncacheable or varying by the account. Each variant is a structure read
  from its schema, id `<owner>.<key>.<value>`; a `#[Variant]` wins for a
  value both name; stored open (`type: ignore` in the config schema),
  unless the provider is closed (`ClosedVariantsInterface`, a static
  `structures()` naming every structure it may list), whose variants are
  written as a class's. A deciding key whose variants offer one value is
  drawn as that value and stated `const`, its label under
  `x-surface.fixed`; a secret of a variant not chosen is kept as stored
  (example 14, `docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter`).
  Defs the entries share are listed once, `VariantList::addDefs()`, and
  each read structure is built once per request and context, wherever it
  is attached (`Resources::buildRead()`), so variants nest: a component's
  slots, each holding a component with its own props and slots
  (`docs/resources.md#variants-from-the-site`,
  `docs/decisions.md#a-slot-holds-text-a-component-or-components`).
- **Reading a schema**: `Contract\SchemaReader`
  (`data_surface.schema_reader`), the emitter's mapping run backwards: a
  JSON Schema document (2020-12; draft-04 to -07 converted first,
  `upconvert()`) read into a blueprint, `$inputs->fromSchema($document)`,
  or registered as a structure, `structure($document, $id)`. A def is one
  structure (a read structure, registered by
  `ResourceRegistry::defineRead()` as `schema:<id>:<hash>` and listed by
  nothing), a conditional of the vocabulary a declared rule. What no
  keyword says is a **foreign constraint**: the part left over, a
  `JsonSchema` constraint on the key, or on the frame
  (`DefinitionMap::frameConstraints()`), checked with opis and named
  under `checkedOnServer` with its schema. The full schema of a
  declaration reads back to itself (`SchemaRoundTripTest`)
  (`docs/reading-a-schema.md`,
  `docs/decisions.md#a-schema-can-be-read-back-into-a-structure`).
- **Collection**: a list of child instances at a key, `attachList()`.
- **Keys from the site**: one key per thing the site has, in a map at a
  key, `addEach('forms', 'boolean', from: Provider::class)` (a value of
  a type or shape per thing) or `attachEach('rows', Row::class, from:
  ...)` (a structure per thing); the provider is a service implementing
  `KeysFromSiteInterface`, whose `keys()` answers a `KeyedList` (id,
  label, optional start and lock, and the cacheability of what it read),
  asked at every build. Refused uncacheable or varying by the account; a
  locked entry is held to what is stored; `attachEach(..., reads:
  ['selling' => 'on_sale'])` wires each entry's port to the entry of the
  same id in a sibling map from the same provider
  (`docs/resources.md#keys-that-come-from-the-site`).
- **Reference key**: a key that refers to an entity, declared by the
  data type string of what it refers to, `add('page', 'entity:node:article',
  ...)`, `'entity:user'`, or the bare `'entity'` (two parts attached at
  the key, `entity_type` and `id`, filtered by `DataSurfaceReferenceableType`
  written on the key); it stores the id. Its candidates are core's
  selection handler's for the account (`Reference\References`), listed
  up to `data_surface.settings:reference_enum_limit` and looked up past
  it, with core's entity autocomplete on the form and `GET
  .../{x}:lookup` over HTTP; `x-surface.reference` marks it in both
  documents (example 16, `docs/options.md#a-key-that-refers-to-an-entity`,
  `docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up`).
- **Contract**: the JSON Schema a built resource or structure emits.
- **Version**: `#[Resource(version:)]` or `#[Structure(version:)]`, from
  1, counting the breaking changes made to its stored values; stamped
  `x-surface.version` at the top of both documents and on a def above 1.
  A structure versions itself; a shape has none. Refused above 1 until
  stored-value migrations (`StoredValueMigrationInterface`, designed,
  not run) are built (`docs/evolution.md`,
  `docs/decisions.md#a-resource-evolves-in-the-open`).
- **Contract baseline** and **contract diff**: a module's committed full
  schemas, `tests/fixtures/contracts/<id>.json`, and the lint that
  judges today's against them (`Contract\ContractDiff`,
  `ContractBaselineTest`, `drush data_surface:contract-diff`): a
  breaking change passes only versioned or allowed with a reason.
  Regenerate with `--write` after an accepted change; never by hand.
- **Access answer**: what the operation's permission, then the access
  class, then each alter that answers access, answer for an account,
  combined with `andIf()` so the stricter stands; the access class's is
  an `AccessAnswer` (open, locked or omitted). Its kind, static or runtime,
  is read from its cacheability and never declared. Static varies by the
  account's permissions or roles alone (`user.permissions`,
  `user.roles`, `user.roles:<role>`, no tag, permanent max-age) and is stated in the
  full schema and the tool listing for the account that asks; runtime
  (a tag, the `user` context, max-age 0, or no cacheability at all) is
  marked there, `x-surface.access: "live"`, and settled by the schema
  for these answers. `ResourcesInterface::buildStatic()` builds for the
  account alone; the operation's permission is the first static answer
  (`OperationDefinition::staticPermission()`); narrowing a key's values
  by account is a runtime refiner, not access
  (`docs/decisions.md#access-is-static-or-runtime-read-from-its-cacheability`).

"Data surface" names the project, and the sealed object the build step
makes (`DataSurfaceInterface`); it is not a third kind. "Shape" is the
named type, of which resources, structures and named scalar shapes are
the three kinds; in code `#[Shape]` names the third, and the word never
names the blueprint or the layout. A value that arrives of the wrong
type is a `TypeMismatchException`. Product names
and on-the-wire identifiers keep the word surface (module, namespaces,
services, routes and paths, `x-surface`, the `surface[...]` form key,
React, the `DataSurface*` engine, tool ids; the contract's `surface` and
`situation` keys became `resource` and `operation` with contract 2); every concept name uses the new word
(`docs/decisions.md#the-product-keeps-its-name`).

## One spelling

A resource is a class in a module's `src/Resource/` carrying
`#[Resource]`, a structure one in `src/Structure/` carrying
`#[Structure]`; the attribute decides the kind, and either directory
works. Each has a static `defineInputs()` (and, on `DefinesOutputsInterface`,
a static `defineOutputs()`), static `#[RefinesInput]` methods and
`#[Operation]` on the class or on static methods; it is never
instantiated. Another module
changes either with an alter in `src/Alter/` carrying `#[Alters]`. A
plugin names the resource or structure it holds with `#[UsesStructure]`,
or, with no argument, is its own structure: the plugin class implements
`StructureInterface`, is found through its plugin definition
(`ResourcePlugins::ownStructures()`), gets the id
`<host type>:<plugin id>` unless it carries `#[Structure]`, and alters
name it by the plugin class (the demo formatter). Everything else is
discovered by `ResourceBuild\ResourceCollectorPass` and built by the
`data_surface.resources` service (`ResourcesInterface::build($resource,
$context)`), which writes the blueprint, the alters, the context and the
bound refiners into the engine's `DataSurfaceBuilder` and seals it into a
`DataSurfaceInterface` (`docs/resources.md`). The builder, the definition
map and `DataSurfaceRefinerInterface` are internal: nothing an author
writes touches them. There is no build event, no declaration method, no
provider service, no policy filter and no output refiner; step 5 of
`REWORK.md` deleted them.

Every plugin host reads `#[UsesStructure]` from the plugin definition:
block, formatter, condition, action and field type base classes, and
`DataSurfacePluginForm` for any configurable plugin. A host builds the
named class in a context of its own verb (`configure`, `field_settings`),
supplies the storage (the plugin's configuration array; the field config
for a field type) and answers static defaults from the class's own
declaration (`ResourcesInterface::defaults()`). A storage class has three
verbs, `load()`, `prepare()` (rehearse the write with the store's own
checks, no side effects) and `commit()` of what prepare returned; a dry
run stops after prepare and reports it.

Decisions live in `docs/decisions.md`: one entry per point the pattern
did not settle, with its reason. A new undecided point is added there,
marked open, not left as a code comment; code points at an entry with
`// Decision: see docs/decisions.md#<anchor>.` only where a reader at
that spot needs it.

Operations are what routes and tools are generated from. A route names
`_data_surface_resource` and `_data_surface_operation` and maps its
parameters onto the operation's by name (`OperationRoute`), gated by
`_data_surface_operation_access`; `DataSurfaceOperationForm` serves it.
`data_surface_tool` derives one tool per operation that can be asked
on its own, `data_surface:<id>:<operation>`: its resource names storage,
no plugin uses it, and every `%key` in its permission can be supplied by
a parameter (`ResourceCatalog::standalone()`). Beside each such
operation that changes a thing that exists it derives a read tool,
`data_surface:<id>:<operation>:read`, taking the parameters and
answering with the stored values less secrets
(`ResourceCatalog::reads()`, `ResourceOperationReadTool`). Both declare
the operation's permission to the Tool API, unless it has a `%key`
(`ResourceOperationToolDeriver::declaredPermission()`). A tool's title
is the operation's label (with its resource added where labels are
shared; the read tool's is "Current values:" and that), and its
description the operation's description and one line of call rules
for its kind (`OperationInputs::description()`). The field
tools are `data_surface:field.instance:create` / `:reuse` / `:update`; the
hand-written `field_add` / `field_update` are gone.
`data_surface.resource_catalog` lists the static layer; `docs/catalog.md`
is generated from it by `scripts/generate-catalog.php`.

Attached structures are `attach()` (a fixed child, by class) and
`attachVariants()` (variants a sibling key chooses between, always
open: every `#[Variant]` at the key is one, and the parent names none).
Both return the map at the key, which the owner labels with the core
setters; `describe()` is for an alter rewording a key it does not own.
A child may be a resource with storage of its own, an attached resource
(the field's storage, `FieldStorageResource`). A service implementing
`ResourceBuild\DerivedVariantsInterface` adds variants for the values no
variant class declares (`data_surface_tool` derives a field type's
settings, and its storage settings, from its config schema). A child
whose keys cannot be listed at all is a `#[RefinesInput]` method on an
`any` key returning a map. A structure reads a value of its container
through a port it declares, `reads()`, which `attach(..., reads:)` wires
to a key of the container's; what depends on a port is stated at the
site, on the container's `allOf`, never in the structure's def
(`docs/resources.md#ports`). `mixIn()` mixes a structure in: its
members are the owner's own keys, its refiners and rules bound in the
owner's frame on its members and its ports, its access locking or
omitting its members (`docs/resources.md#mixins`). `attachList()` is a collection: a list of
child instances, the list (its Count) the parent's, each item the
child's, its entry a `Collection` (`docs/resources.md#collections`).

Keys one resource asks per thing the site has (a checkbox per form) are
`addEach()` or `attachEach()` with a provider, not a deriver
(`docs/decisions.md#keys-that-come-from-the-site`); the derived tools are
cleared when a tag any of them was built with is invalidated
(`data_surface_tool`'s `ToolDefinitionsInvalidator`).

A resource or structure whose keys are the site's rather than the
code's (one per bundle, one per field) is derived: a class in `src/Resource/` or `src/Structure/`
carrying `#[DerivesResources(base:)]` and implementing
`ResourceDeriverInterface` returns `DerivedResource` values at
discovery, each `<base>:<derivative>` with no class
(`ResourceDefinition::$class` NULL; every caller asks by `name()`), its
inputs and operations `ResourceCallable`s, cached under the tags of what
it was read from. Its kind follows from what it names: one that names
storage or identity keys is a resource, any other a structure.
`data_surface_entity` derives a resource per content entity bundle
(`entity.content:*`) and a structure per field (`field.item:*`), with
field access as each field child's access (`docs/entities.md`). A
child is built by the same build step in its own frame and sealed into
the parent's entry as an `Attachment`, or among its `Variants`; the
pipeline, refinement, the form and the tool bridge recurse through those
entries, and nothing in a parent can name a key inside its child (an
alter's glue reads the structure at its own mount, never narrowing it). `docs/resources.md` has the rules.

## Running the suite

`scripts/check.sh` runs the suite and the three gates below, in two
lanes; `scripts/check.sh --help` lists them and their variables. Both
print the wall time of each gate and end with a PASS line only when no
test errored or failed and every gate passed.

- **The fast lane**, `scripts/check.sh --fast` (or
  `DATA_SURFACE_CHECK_FAST=1`): unit and kernel tests through phpunit,
  one process per class, `DATA_SURFACE_CHECK_CONCURRENCY` at once (one
  process for all of them takes 15 minutes, since every kernel test
  installs its modules). Each class has its own result cache in the
  container's `/tmp/data_surface-phpunit-cache` and runs with
  `--order-by=defects`, so what failed last runs first; classes with a
  defect last time start first, then the slowest. A class's output is
  printed only when it has a problem. Then phpcs, phpstan and cspell on
  the files changed since HEAD, untracked ones included. phpstan takes the whole module when no
  PHP file changed or a `.neon` or `phpstan-*.php` file did. **Iterate
  on the fast lane.** It runs no functional or browser test, so it is
  never the last run.
- **The full lane**, `scripts/check.sh`: every class through core's
  `run-tests.sh`, each in its own phpunit process with its own database
  prefix and site directory. Unit, kernel and functional classes run
  `DATA_SURFACE_CHECK_CONCURRENCY` at a time (default 8), then the
  browser classes `DATA_SURFACE_CHECK_BROWSER_CONCURRENCY` at a time
  (default 4); then phpcs, phpstan and cspell over the whole module.
  **Run the full lane once before handing work back**; its PASS line is
  the one that counts. `run-tests.sh` builds each class's phpunit command
  itself, so the full lane has no `--order-by=defects` and no result
  cache; it orders classes slowest first.

On this machine (Docker VM of 14 CPUs and 8 GB), as of 2026-10-23: the
full lane takes 5m07s at the defaults (phpunit 4m40s) and 23m03s with
both set to 1 (phpunit 22m36s; a plain phpunit run takes 21m11s); the
fast lane takes 2m31s, 3m30s while its caches are empty. For the first
run, eight classes at once took 3m12s, six 3m54s, ten 3m35s (ddev's
php-fpm serves eight requests at once). The add-on's Chrome takes 12
sessions, but six browser tests at once crash its tabs ("tab crashed")
for want of memory, so the browser classes run apart, four at once (five
was no faster).

Everything runs inside the ddev container, the only place that can
reach the test database; `check.sh` works from either side of it. The
browser tests (`tests/src/FunctionalJavascript`) drive Chrome through
the ddev add-on `ddev/ddev-selenium-standalone-chrome` (`ddev add-on get
ddev/ddev-selenium-standalone-chrome`, then `ddev restart`): a
`selenium-chrome` service with a webdriver on port 4444, and, in the web
container's environment (`.ddev/config.selenium-standalone-chrome.yaml`),
`MINK_DRIVER_ARGS_WEBDRIVER` pointing at it and `SIMPLETEST_BASE_URL=http://web`,
the site under a name both containers reach. `http://localhost` still
serves kernel and functional tests, but the browser would look for the
site in its own container; leave the base URL to the environment. One
class, or the plain phpunit run, from the site root:

```
ddev exec bash -c 'cd /var/www/html/web && SIMPLETEST_DB=mysql://db:db@db/db \
  ../vendor/bin/phpunit -c core modules/custom/data_surface/tests/src/Kernel/SomeTest.php'
```

The full lane's phpunit line reads, as of this writing:

```
phpunit: Tests: 1494, Assertions: 30974, Errors: 0, Failures: 0, Skipped: 0. Classes: 200 of 200, 8 at a time, browser classes 4.
```

the same counts a plain phpunit run of the tree gives. `run-tests.sh`
prints one line per class counting passed tests only, so `check.sh`
reads the counts from the JUnit log each class's phpunit writes, and
checks them against the classes `run-tests.sh` listed. A failure or an
error is printed with its message; a class with no log (it did not run,
or its process died) fails the gate by name, as does `run-tests.sh`
exiting 2 or more with every log clean (an error outside any test). Its
exit 1 with every log clean is a PHPUnit warning or a risky test, which
the gate has never failed on; the classes are named. Deprecations never
fail the gate, in either lane: plain phpunit counts them (42 as of this
writing) and passes, and the full lane passes `--suppress-deprecations`,
without which `run-tests.sh` adds `--fail-on-deprecation` to every class.
The fast lane's line sums phpunit's own lines, one per class:
`Tests: 1374, Assertions: 27106, Deprecations: 95. Classes: 152, 8 at a
time.`; its deprecation count is per process, so it is higher than one
run's.

The test and assertion counts drift upward as work lands and are not the
thing to check. **No test may error and no test may fail**, the browser
tests included; any error or failure is a real regression.

**Which kind of test.** A test that only submits a form and reads config
back is a kernel test; a functional test is for routing, access and
rendering; a browser test is for an AJAX swap. A functional test costs a
site install and a browser test a Chrome session, which is most of the
full lane's time. Existing functional tests convert as units touch them
(ROADMAP.md, Maintenance).

The suite needs two packages a site may not have: the Tool Belt module
(`drupal/tool_belt`, under `require-dev` in `composer.json`), which the
two tool comparison tests install, and `opis/json-schema`, which
`ServedContractTest` validates the emitted schemas with and which the
module itself needs at runtime, under `require`, to check what a
structure read from a JSON Schema keeps of it (the `JsonSchema`
constraint). On a site where this module is a checkout and not a
Composer package, require both at the site root. Without them those
tests error; they do not skip.

There are no open findings. The flaky
`ExamplesEntryPortsTest::testTheFormFollowsEachType` (a race between the
save and the config read) waits for the saved page now, as
`ExamplesSpotlightAjaxTest` does. The two that stood here
(`NodeTypeResourceFormTest::testAddStoresTheTypeAndItsOverrides` and
`NodeTypeResourceFormTest::testDuplicateMachineNameIsRefusedOnTheTypeElement`)
are fixed: the cosmetic layer no longer lets core's machine name
element validate beside the data surface and swallow its violation, and the
test drops the field definitions its own process memoized before the
request wrote them. Both are pinned by kernel tests now. They surfaced
only once node could be installed inside functional tests at all, which
the project-level `web/core/phpunit.xml` and its bootstrap at the site
root (`phpunit-bootstrap.php`) made possible; delete those two files and
the old `node_make_sticky_action` install failure comes back, hiding
these tests again.

## The three gates

Run from the site root (`ROOT` below), which is the parent of `web/`:

```
# phpcs — the stored installed_paths point inside the container.
cd web/modules/custom/data_surface && $ROOT/vendor/bin/phpcs -d memory_limit=1G \
  --runtime-set installed_paths \
  "$ROOT/vendor/drupal/coder/coder_sniffer,$ROOT/vendor/slevomat/coding-standard"

# phpstan — the module's own config; needs the raised memory limit.
cd $ROOT && vendor/bin/phpstan analyse \
  -c web/modules/custom/data_surface/phpstan.neon --no-progress --memory-limit=1G

# cspell — core's .cspell.json with globRoot set to the module, its
# dictionaryDefinitions paths made absolute, and .cspell-project-words.txt
# merged into "words"; then, from the module root:
npx --yes cspell@8 --config /tmp/merged.json --no-progress --no-summary "**"
```

`scripts/check.sh` bakes all four in; read it rather than retyping them.

## The React submodule and its app

The served contract's emitter is the main module's:
`Contract\ContractEmitter` (`data_surface.contract_emitter`) turns a
sealed data surface and its values into JSON Schema 2020-12 with an
`x-surface` keyword per property, variants as an `allOf` of `if`/`then`
on their deciding sibling. That is the schema as it stands, for one set
of values; `emitFull()` is the second document, the full schema, which
reads no value: every key as declared, every listable dependency as
conditionals, the rest `refine` or `live`, and never stricter than the pipeline
(`docs/decisions.md#two-documents-never-stricter-than-the-runtime`).
It names no widget unless asked
(`emit(..., widgets: TRUE)`); `data_surface_tool`'s contract panel shows
it without, beside what the Tool API can advertise (which flattens
variants, and is what `tool:info` prints). `modules/data_surface_react`
serves it with the widget hints, through the main module's service, on
an API where the path is the resource and the method the operation
(contract 3, `ServedPaths`): `GET /surface-api/{resource}[/{id}]` the
envelope, its `ETag` the stored values' fingerprint; `GET .../schema` the
full schema itself; `POST .../schema` the envelope narrowed for values
in progress (the AJAX rebuild's equivalent, emitted from the form
builder's `refinementOverlay()`, so an orphan is held unanswered and
listed `stale`); `PATCH` the default update, `POST` on the collection
the default create (201 with `Location`), `:{operation}` any other,
`?dryRun=true` a run that writes nothing, `If-Match` a 412 when storage
changed since, a refusal 422 problem details, every path a JSON Pointer
and every contract 2 path a 410.
`/surface-react/{resource}/{operation}` renders it with a React app.
`docs/served-contract.md` has the format, with children and variants
as `$defs` that the properties reference; `docs/decisions.md` (Served
contract) has the points it settled.

The app is `modules/data_surface_react/app/`: Vite, React 18 and
TypeScript, every version pinned exactly. Run npm on the host, never
inside ddev:

```
cd modules/data_surface_react/app
npm ci          # node_modules/ is ignored
npm test        # Vitest + React Testing Library
npm run build   # tsc --noEmit, then dist/app.js and dist/app.css
```

**`dist/` is committed**, so the module works on a site with no Node,
and it must be rebuilt and committed with every change under `app/`.
Nothing checks that the two agree (a timestamp test would be fragile),
so the rule is this sentence. `npm test` is not part of
`scripts/check.sh`; run it beside the check when `app/` changed.
`node_modules/` is excluded from phpcs and phpstan, and cspell skips it,
`dist/` and `package-lock.json`.

## Conventions that are load-bearing

- Contracts are objects; payloads are arrays. Never pass a definition as an
  array or a value bag as an object.
- A resource or structure is declared in one place: its `#[Resource]`
  or `#[Structure]` class, or the plugin class that is its own
  structure. A plugin names it with `#[UsesStructure]`; the host builds
  it.
- A resource or structure is static: `defineInputs()`,
  `defineOutputs()` and its `#[RefinesInput]` methods are `public
  static`, called on the class, so it has no constructor, holds no
  service and is never instantiated; a refiner points at a list with a
  constraint and the options resolver fetches. A static refiner is a
  static method, a resource's, a structure's and an alter's alike; only
  a runtime one may be an instance method. Alters, storage classes and
  access classes are autowired services and may hold services
  (`docs/decisions.md#a-resource-or-structure-is-static`).
- A `#[RefinesInput]` method is static, a pure function of what it
  watches that the served contract runs ahead of time, unless it says
  `runtime: TRUE`: a rule no schema can state (a lookup, uniqueness),
  never run ahead, marked `runtime`, applied by the pipeline
  (`docs/decisions.md#static-by-default-runtime-by-declaration`).
- The kind is enforced, not trusted: the build refuses an instance
  refiner not declared runtime; with assertions on, a static refiner runs
  with the container guarded (`Refinement\StaticRefinerContainer`) and the
  emitter asks it twice; `StaticRefinerAgreementTrait` holds conditionals
  to a fresh `refine()`; and the module's PHPStan rule flags a static
  refiner calling `\Drupal::` (`docs/refinement.md#enforcing-the-kind`).
- A rule between keys whose condition and effect a schema can state is
  declared in the blueprint, `$inputs->narrow($key)->when(Condition::...)`
  and its effects: a static refiner, bound after its contributor's coded
  ones, waiting for nothing, only tightening, stated by the full schema
  as written. A refiner is for an effect that must be computed or a
  condition that is runtime (`docs/decisions.md#a-rule-declared-as-data`).
- `#[RefinesInput]` methods narrow a definition, or answer that the key
  does not apply, `DoesNotApply::here()`, which the return type then
  names (`docs/decisions.md#a-key-that-does-not-apply`); an alter adds
  keys and, with `extendChoices()`, offers more values on a fixed choice
  list (the one widening verb). Nothing removes a key or a value: a remove-only
  policy is a later concept (`ROADMAP.md`).
- Requiredness appears only when it says something: `setRequired(TRUE)` on
  the keys that must be configured, nothing at all on the rest, because
  core data definitions are optional by default.
- Violations are message objects end to end, from the pipeline to the form,
  never pre-rendered strings.
- Translatable strings: static context (a resource or structure, a
  static protocol method, an enum, a value object's static helper) calls
  the global `t()` with a literal string (the same lazy
  `TranslatableMarkup`, and extractable); a container-built class of
  ours injects `string_translation` and calls `$this->t()`; `new
  TranslatableMarkup` appears only inside attribute arguments, where a
  call is not allowed.
- No closures anywhere a form array or a data surface carries: both are
  serialized. Use a service, a callable string, or a class.
- Iteration order is load-bearing: the definition map is in declaration
  order, and `FieldToolsComparisonTest`'s drift assertion is the tripwire.
- `modules/data_surface_tool/COMPARISON.md` and
  `modules/data_surface_demo_node_type_tool/COMPARISON.md` are generated
  by `scripts/generate-comparison.php`, `docs/catalog.md` by
  `scripts/generate-catalog.php`, and the contract baselines under
  `tests/fixtures/contracts/` by `drush data_surface:contract-diff
  --write`, and the config schema and install files of every resource
  stored in a `ConfigObjectStorage`, and the schema of a config entity
  type a module declares and stores a resource in, by `drush
  data_surface:config-schema --write` (the test modules' through
  `ConfigSchemaDriftTest` with `DATA_SURFACE_CONFIG_SCHEMA_WRITE=1`), and
  example 15's documents under `tests/fixtures/venues/` by
  `VenueResourceTest` with `DATA_SURFACE_VENUE_WRITE=1`, and example
  16's under `tests/fixtures/references/` by `ReferenceKeysTest` with
  `DATA_SURFACE_REFERENCES_WRITE=1`. Never edit any of them by hand.
- Commit messages are one line.

## Naming

- A resource class ends in `Resource`, a structure class in
  `Structure`, and the id on either attribute is dotted (`node.type`,
  `field.instance`); a shape class is named for the value with no
  suffix (`License`, `event.license`); an alter ends in `Alter`, a storage class in
  `Storage`, an access class in `Access`. A deriver is named in the
  plural for what it derives (`ContentEntityResources`,
  `FieldItemStructures`).
- A class-swap adopter prefixes the swapped class with `Surface`
  (`AddressItem` → `SurfaceAddressItem`), and the hook implementation's
  docblock **must name the replacement class**, so grepping the original
  lands on the line that replaces it.
- The catalog lists a plugin as `<host type>:<plugin id>` —
  `block:my_teaser`, `field_type:address`.
- A context's id is an operation id (`create`, `update`, `reuse`) or a
  host's own verb (`configure`, `field_settings`) and never carries
  identity. Identity is the `#[Resource(identity:)]` keys the context
  knows.

See `docs/declaring-a-resource.md` for all four in full.

## Where things live

```
src/                     Data surface, builder (internal), definition map, host trait.
src/Resource/            The authoring API and attributes (Attribute/).
src/ResourceBuild/       Discovery pass, registry, build step, adapters,
                         derived variants, catalog, operation routes.
src/Access/              The operation route's access check.
src/Hook/                #[UsesStructure] into plugin definitions.
src/Pipeline/            Access, accept, validate, prepare, commit; results.
src/Storage/             Where accepted values are written; settings layouts.
src/Contract/            The served contract's emitter (JSON Schema); the contract diff.
src/Drush/               The contract diff's Drush command.
src/Form/                Form builder, host traits, the operation form.
src/Widget/ src/Plugin/  Definition to form element; resolvers, hosts, constraints.
src/Options/             Option sets, the resolver plugin base and manager.
src/Variants/            Variants from the site: ComponentProps and ComponentSlots.
src/Refinement/          Narrowing and choice sets.
modules/                 Eighteen experimental submodules; each has its own README.
tests/src/               Unit, Kernel, Functional, FunctionalJavascript.
docs/ scripts/           Published documentation; check.sh and the generators.
```

A module that declares its own puts resources in `src/Resource/`,
structures in `src/Structure/`, shapes in `src/Shape/`, alters in
`src/Alter/`, storage classes in `src/Storage/` and access classes in
`src/Access/`; only the first four are scanned, and the rest are
registered because a resource or structure names them.
