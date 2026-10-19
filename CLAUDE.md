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
  own, held by a parent, a slot or a plugin's host.
  `#[Structure(id, access:)]`.
- **Shape**: a named type (Smithy's word), of three kinds: resources,
  structures, and named scalar shapes. The third is `#[Shape(id)]` on a
  class in `src/Shape/` whose static `define()` returns one scalar
  `DataDefinition` (no default, not required), used as
  `$inputs->add('licence', Licence::class, t('Event licence'))`: the key
  may only narrow it (checked at build), a port may read it
  (`reads('code', Sku::class)`, wired only to a key of that shape), an
  `#[Alters(Licence::class)]` tightens it at every key with
  `narrow('<id>')` and `describe('<id>')`, and both documents write it
  once as `$defs.<id>`, each key `$ref` first with what it adds beside
  (`docs/declaring-a-resource.md#named-shapes`).
- **Operation**: a way a resource is asked for, a static `#[Operation]`
  method returning an `OperationContext` whose `id` is the operation's.
- **Blueprint**: what a resource or structure declares its members
  into, handed to `defineInputs()` and `defineOutputs()`
  (`BlueprintInterface`); an alter gets the add-only
  `BlueprintAdditionsInterface`.
- **Storage**: where a resource's values live: `load()`, `prepare()`,
  `commit()` on a `ResourceStorageInterface`.
- **Layout**: how a storage lays accepted values out where that differs
  from how they are asked for, `SettingsLayoutInterface`; an alter hands
  one for its mount with `HasStorageLayoutInterface::storageLayout()`.
- **Alter**: another module's class that adds keys to a resource or
  structure and narrows it, `#[Alters]` in `src/Alter/`.
- **Attach**: a fixed child at a key, `attach()`; an alter attaches only
  at its own mount, `third_party_settings.<module>`, as an adapter.
- **Port**: a value a structure reads from its container, declared under
  a name of its own with `$inputs->reads('capacity', 'integer')` and
  wired by the container with `attach(..., reads: ['capacity' =>
  'seats'])`; a live sibling value, never stored by the structure.
- **Mixin**: a structure whose members are merged into the owner's own
  keys, `$inputs->with(TimestampsStructure::class, reads: [...])`:
  stored flat, refined in the owner's frame, emitted as `allOf` of its
  def and the owner's properties; the owner's only, never an alter's.
  Nest for a thing of its own, mix in for shared members that are part
  of this thing (`docs/resources.md#mixins`).
- **Slot**: a child a sibling key chooses, `attachBy()`.
- **Variant**: a structure or resource that fills a slot for one value,
  `#[Variant(of:, key:, value:)]`.
- **Collection**: a list of child instances at a key, `attachList()`.
- **Contract**: the JSON Schema a built resource or structure emits.
- **Static and runtime access**: an access answer's kind, read from its
  cacheability and never declared. Static varies by the account's
  permissions or roles alone (`user.permissions`, `user.roles`,
  `user.roles:<role>`, no tag, permanent max-age) and is stated in the
  full schema and the tool listing for the account that asks; runtime
  (a tag, the `user` context, max-age 0, or no cacheability at all) is
  marked there, `x-surface.access: "runtime"`, and settled by the schema
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
services, routes and paths, `x-surface`, the contract's `surface` and
`situation` keys, the `surface[...]` form key, React, the `DataSurface*`
engine, tool ids); every concept name uses the new word
(`docs/decisions.md#the-product-keeps-its-name`).

## One spelling

A resource is a class in a module's `src/Resource/` carrying
`#[Resource]`, a structure one in `src/Structure/` carrying
`#[Structure]`; the attribute decides the kind, and either directory
works. Each has a static `defineInputs()` (and, on `HasOutputsInterface`,
a static `defineOutputs()`), static `#[RefinesInput]` methods and
`#[Operation]` static methods; it is never instantiated. Another module
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
a parameter (`ResourceCatalogue::standalone()`). Beside each such
operation that changes a thing that exists it derives a read tool,
`data_surface:<id>:<operation>:read`, taking the parameters and
answering with the stored values less secrets
(`ResourceCatalogue::reads()`, `ResourceOperationReadTool`). Both declare
the operation's permission to the Tool API, unless it has a `%key`
(`ResourceOperationToolDeriver::declaredPermission()`). The field
tools are `data_surface:field.instance:add` / `:reuse` / `:edit`; the
hand-written `field_add` / `field_update` are gone.
`data_surface.resource_catalogue` lists the static layer; `docs/catalogue.md`
is generated from it by `scripts/generate-catalogue.php`.

Attached structures are `attach()` (a fixed child, by class) and
`attachBy()` (a slot a sibling chooses, always open: every `#[Variant]`
for it fills it, and the parent names none). Both return the map at the
key, which the owner labels with the core setters; `describe()` is for
an alter rewording a key it does not own. A child may be a resource with
storage of its own, an attached resource (the field's storage,
`FieldStorageResource`). A service implementing
`ResourceBuild\DerivedVariantsInterface` fills a slot for the values no
variant class fills (`data_surface_tool` derives a field type's
settings, and its storage settings, from its config schema). A child
whose keys cannot be listed at all is a `#[RefinesInput]` method on an
`any` key returning a map. A structure reads a value of its container
through a port it declares, `reads()`, which `attach(..., reads:)` wires
to a key of the container's; what depends on a port is stated at the
site, on the container's `allOf`, never in the structure's def
(`docs/resources.md#ports`). `with()` mixes a structure in: its
members are the owner's own keys, its refiners and rules bound in the
owner's frame on its members and its ports, its access locking or
omitting its members (`docs/resources.md#mixins`). `attachList()` is a collection: a list of
child instances, the list (its Count) the parent's, each item the
child's, its entry a `Collection` (`docs/resources.md#collections`).

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
the parent's entry as an `Attachment`, or a `Slot` of them; the
pipeline, refinement, the form and the tool bridge recurse through those
entries, and nothing in a parent can name a key inside its child (an
alter's glue reads the structure at its own mount, never narrowing it). `docs/resources.md` has the rules.

## Running the suite

The suite runs from `web/` inside the ddev container, which is the only
place that can reach the test database. The browser tests
(`tests/src/FunctionalJavascript`) drive Chrome through the ddev add-on
`ddev/ddev-selenium-standalone-chrome` (`ddev add-on get
ddev/ddev-selenium-standalone-chrome`, then `ddev restart`): a
`selenium-chrome` service with a webdriver on port 4444, and, in the web
container's environment (`.ddev/config.selenium-standalone-chrome.yaml`),
`MINK_DRIVER_ARGS_WEBDRIVER` pointing at it and `SIMPLETEST_BASE_URL=http://web`,
the site under a name both containers reach. The command takes those from
the environment:

```
ddev exec bash -c 'cd /var/www/html/web && SIMPLETEST_DB=mysql://db:db@db/db \
  ../vendor/bin/phpunit -c core modules/custom/data_surface'
```

`http://localhost` still serves kernel and functional tests, but the
browser would look for the site in its own container; leave the base URL
to the environment.

The baseline as of this writing: **1069 tests, 14424 assertions, 0 errors,
0 failures**. The test and assertion counts drift upward as work lands
and are not the thing to check. **No test may error and no test may
fail**, the browser tests included; any error or failure is a real
regression.

The suite needs two packages a site may not have, both listed under
`require-dev` in `composer.json`: the Tool Belt module
(`drupal/tool_belt`), which the two tool comparison tests install, and
`opis/json-schema`, which `ServedContractTest` validates the emitted
schemas with. On a site where this module is a checkout and not a
Composer package, require both at the site root. Without them those
tests error; they do not skip.

There are no open findings. The two that stood here
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

`scripts/check.sh` runs the suite and all three gates below in order,
enforces that rule, and stops at the first failure.

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
`x-surface` keyword per property, each slot an `allOf` of `if`/`then`
on its deciding sibling. That is the schema as it stands, for one set
of values; `emitFull()` is the second document, the full schema, which
reads no value: every key as declared, every listable dependency as
conditionals, the rest `dynamic`, and never stricter than the pipeline
(`docs/decisions.md#two-documents-never-stricter-than-the-runtime`).
It names no widget unless asked
(`emit(..., widgets: TRUE)`); `data_surface_tool`'s contract panel shows
it without, beside what the Tool API can advertise (which flattens a
slot, and is what `tool:info` prints). `modules/data_surface_react`
serves it with the widget hints, through the main module's service:
`/surface-api/{resource}/{operation}` serves it, `/refine` re-narrows it
(the AJAX rebuild's equivalent, emitted from the form builder's `refinementOverlay()`, so an orphan is
held unanswered and listed `stale`), `/validate` runs the pipeline
dry and `/submit` writes, answering a refusal as 200 data
(`committed: false`), a write with the rebuilt contract, and a create
with the operation the created thing now lives at (`created`, from the
outputs or identity, checked by building that operation). The GET
contract carries a `fingerprint` of the stored values; a submit that
sends it back is refused when storage changed since, and one that sends
none is not checked (opt-in; the app sends it).
`/surface-react/{resource}/{operation}` renders it with a React app.
`docs/served-contract.md` has the format, with children and slot variants
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
  to a fresh `refine()` (`docs/refinement.md#enforcing-the-kind`).
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
  by `scripts/generate-comparison.php`, and `docs/catalogue.md` by
  `scripts/generate-catalogue.php`. Never edit any of them by hand.
- Commit messages are one line.

## Naming

- A resource class ends in `Resource`, a structure class in
  `Structure`, and the id on either attribute is dotted (`node.type`,
  `field.instance`); a shape class is named for the value with no
  suffix (`Licence`, `event.licence`); an alter ends in `Alter`, a storage class in
  `Storage`, an access class in `Access`. A deriver is named in the
  plural for what it derives (`ContentEntityResources`,
  `FieldItemStructures`).
- A class-swap adopter prefixes the swapped class with `Surface`
  (`AddressItem` → `SurfaceAddressItem`), and the hook implementation's
  docblock **must name the replacement class**, so grepping the original
  lands on the line that replaces it.
- The catalogue lists a plugin as `<host type>:<plugin id>` —
  `block:my_teaser`, `field_type:address`.
- A context's id is an operation id (`add`, `edit`, `reuse`) or a
  host's own verb (`configure`, `field_settings`) and never carries
  identity. Identity is the `#[Resource(identity:)]` keys the context
  knows.

See `docs/declaring-a-resource.md` for all four in full.

## Where things live

```
src/                     Data surface, builder (internal), definition map, host trait.
src/Resource/            The authoring API and attributes (Attribute/).
src/ResourceBuild/       Discovery pass, registry, build step, adapters,
                         derived variants, catalogue, operation routes.
src/Access/              The operation route's access check.
src/Hook/                #[UsesStructure] into plugin definitions.
src/Pipeline/            Access, accept, validate, prepare, commit; results.
src/Storage/             Where accepted values are written; settings layouts.
src/Contract/            The served contract's emitter (JSON Schema).
src/Form/                Form builder, host traits, the operation form.
src/Widget/ src/Plugin/  Definition to form element; resolvers, hosts, constraints.
src/Options/             Option sets, the resolver plugin base and manager.
src/Refinement/          Narrowing and choice sets.
modules/                 Twelve experimental submodules; each has its own README.
tests/src/               Unit, Kernel, Functional, FunctionalJavascript.
docs/ scripts/           Published documentation; check.sh and the generators.
```

A module that declares its own puts resources in `src/Resource/`,
structures in `src/Structure/`, shapes in `src/Shape/`, alters in
`src/Alter/`, storage classes in `src/Storage/` and access classes in
`src/Access/`; only the first four are scanned, and the rest are
registered because a resource or structure names them.
