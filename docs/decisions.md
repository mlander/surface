# Decisions

<!-- cspell:ignore nmae -->

[The pattern](pattern.md) says what a resource, a structure, an alter,
an operation, storage and an access class are. Building it meant
deciding points the pattern did not cover. Each is here, once, grouped
by topic: what was decided, in one or two sentences, and why. They are
written for the module's owner to accept or change; changing one is a
code change at the place its entry names.

A point that comes up undecided while working on the module is added
here, as an entry marked *open*, rather than as a comment in the code.

## Vocabulary

### Resources, structures and operations

What the module first called a surface is now one of two kinds, and
what it called a situation is an operation. A **resource** is a thing
with identity and storage that operations read and write; the form, the
tool, the route and the schema are derived from it
(`#[Resource(id, identity:, storage:, access:)]`, `ResourceInterface`).
A resource may live in a config object, a config entity, State, a
plugin's settings or a content entity, and only its storage
(`ResourceStorageInterface`: `load()`, `prepare()`, `commit()`) knows
which. The examples' registration settings are one config object and
name no identity keys: a resource with no identifiers, such as a
settings object, is a singleton resource. A content type is a config
entity whose identity is its machine name, and a basic page,
`entity.content:node.page`, is the same shape applied to content. A
**structure** is a group of keys with no identity or storage of its
own: it is attached to a parent, is a variant at a key, or is the
configuration of the plugin that names it (`#[Structure(id, access:)]`,
`StructureInterface`). Both extend `DefinesInputsInterface`, whose static
`defineInputs()` is all they share by contract, and one
`ResourceDefinition` describes either and says its `kind`. A way a
resource is asked for is an **operation** (`#[Operation]`, returning an
`OperationContext` whose `id` is the operation's).

The words are Smithy's, where every named type is a shape, a structure
is a shape composed of members, a resource is a shape with identifiers
and the lifecycle operations that act on it, and an operation is what a
caller invokes. They say what the old word did not: whether a class can
be reached on its own. A resource can, through its operations; a
structure only through what holds it. Operation is also the word Drupal
already asks access by: an entity's access is asked per operation, and
here an operation's permission and then the access class are asked the
same way. A structure may still declare operations, but only for its
container to choose between (the larder's spice rack, invoked through
`update` or `restock`); asked on its own, it is the container that is
reached.

The attribute decides the kind, not the directory: a module's
`src/Resource/` and `src/Structure/` are scanned as one list, so a class
moved between them changes nothing, and the two directories are for the
reader. Alters are in `src/Alter/` and reach both kinds. Discovery
refuses a class carrying both attributes, one that does not implement
its kind's interface, and a `#[Resource]` that names no storage, no
identity and no plugin that hosts it, since everything it says a
structure says. A `#[Structure]` cannot name identity or storage: its
attribute has no such arguments. A derived resource is of the kind its
values say: storage or identity makes it a resource (a content entity
bundle), neither a structure (one of its fields, stored by the entity).

Which names kept the old word is the next entry,
[The product keeps its name](#the-product-keeps-its-name).
(`ResourceRegistry::assertResourceClass()`, `ResourceRegistry::discover()`,
`ResourceCollectorPass`, `DerivedResource::kind()`)

### The product keeps its name

The product is Surface API. A name that is the product's, or that is
on the wire, keeps the word surface; a name for a concept uses the
concept's word. Renaming a product name tells a reader nothing the docs
do not, and breaks every site, client and tool that reads it. Renaming
a concept name costs only this repository, and an old concept word left
in code teaches the wrong model.

These keep the word, as product names or on-the-wire identifiers:

- the module and submodule machine names and PHP namespaces
  (`surface`, `Drupal\surface\...`), the test modules'
  `surface_` prefix, the project name, the `surface.` service
  prefix, the logger channel and cache ids;
- route names, the route keys (`_surface_resource`,
  `_surface_operation`, `_surface_operation_access`,
  `_surface_panel`, `_surface_cosmetics`,
  `_surface_fixed`, `_surface_defaults`,
  `_surface_query`, `_surface_served_access`) and URL paths
  (`/surface-api/...`, `/surface-react/...`, `/surface-examples/...`);
- config names and State keys;
- the served contract's `x-surface` keyword, because clients already
  read it; the document's own keys for the resource's id and the
  operation's id, in the document, in `created` and in the React page's
  `drupalSettings`, are `resource` and `operation` since
  [contract 2](#contract-2), which moved them with the other wire
  changes, once (they were `surface` and `situation`);
- the `surface[...]` form key;
- the React app's identifiers (`SurfaceForm`, the `surfaceReact`
  settings namespace);
- the engine: what the build step seals is a *data surface*
  (`SurfaceInterface`), and the classes around it keep the name
  (`SurfaceBuilder`, `SurfacePipeline`, `SurfaceFormBuilder`,
  `SurfaceOperationForm`, the `Surface*` host bases and plugin
  types), as do the hosts' `surface*()` methods (`surfaceAccess()`,
  `buildSurfaceForm()`), named so beside core's methods of the same
  verb;
- tool ids, `surface:<id>:<operation>`;
- the `Surface` prefix of a class-swap adopter (`SurfaceAddressItem`).

Everything that names a concept uses the new word: resource, structure,
shape, operation, storage, blueprint, layout, child. In prose, "the
surface" for a resource or a structure is wrong; "data surface" is the
sealed object and nothing else; "shape" is Smithy's, a named type, of
which resources, structures and named scalar shapes are the three kinds
([A shape is a named definition](#a-shape-is-a-named-definition)); in
code `#[Shape]` names the third, and the word never names the blueprint
or the layout.

Renamed under this decision (2026-10-18), URL paths and wire formats
unchanged:

- the React routes' placeholders, `{surface}` and `{situation}`, are
  `{resource}` and `{operation}`, with the controllers' arguments, the
  access check's raw parameters and the examples' links;
- a variable holding a `ResourceDefinition` is `$resource`, not
  `$surface` (the tool bridge, the catalog and its script), as is the
  tool base's `$resourceRegistry`, and a translatable placeholder
  `@surface` is `@resource` (or `@child` for a collection's item);
- `BlueprintAdapterBase::reserveSubsurface()` is `reserveChild()`, and
  `ResourceCollectorPass::SHAPES` is `DECLARATIONS`;
- the test modules `data_surface_target_test` and
  `data_surface_surface_test` are `surface_storage_test` and
  `surface_resource_test`, their ids `resource_test.*`; the
  fixtures `BothKindsShape` and `InstanceShapeStructure` are
  `BothKindsClass` and `InstanceDefineInputsStructure`;
  `OutputSurfaceTest` is `OutputsTest`; test variables and methods
  that said target or shape for storage or a blueprint say storage or
  build;
- the examples' config schema panel counts a slot's variants, not its
  shapes;
- the decisions "A shape is static" and "Cacheability on the shape" are
  [A resource or structure is static](#a-resource-or-structure-is-static)
  and [Cacheability on the blueprint](#cacheability-on-the-blueprint);
- with named scalar shapes, which give the word its code meaning
  (2026-10-18): `ShapeMismatchException` is `TypeMismatchException`, and
  the pipeline's `shapeMismatchViolations()` is `typeMismatchViolations()`.
  What it refuses is a value of a type its definition cannot hold (a
  list where a string was wanted), which its own message already said;
  the semantics page's section is "Type mismatches".

<!-- cspell:disable -->
Renamed 2026-10-20, each concept name to its word and to US spelling,
no wire format changed but the examples' own `license`; old → new, to
grep:

- `attachBy()` → `attachVariants()`, the blueprint's and the engine builder's;
- `#[Variant(of:, key:, value:)]` → `#[Variant(of:, at:, for:)]`;
- `Slot` → `Variants`, `Entry::$slot` → `Entry::$variants`;
- `ContractEmitter::slot()` → `ContractEmitter::variants()`;
- `DerivedVariantsInterface::slot()` → `DerivedVariantsInterface::variantsAt()`;
- `DefinitionMetadata::setSlot()`, `slotOf()` → `setVariantsBy()`, `variantsBy()`;
- `DefinitionMap::withSlotsResolved()` → `withVariantsResolved()`;
- `InputDefinitions::fromSlot()` → `InputDefinitions::fromVariants()`;
- the glossary's Slot → Variants ([Variants, not slots](#variants-not-slots));
- the decisions "A slot nothing fills" and "No OpenAPI discriminator on a slot" → [Variants nothing declares](#variants-nothing-declares) and [No OpenAPI discriminator for variants](#no-openapi-discriminator-for-variants), and the served contract's "A slot is a conditional" → [Variants are conditionals](served-contract.md#variants-are-conditionals);
- `BlueprintAdditionsInterface::with()` → `mixIn()`, `with()` deprecated;
- `addDefinition()` returning the blueprint → returning the definition;
- `HasOutputsInterface` → `DefinesOutputsInterface`;
- `HasStorageLayoutInterface` → `AltersStorageLayoutInterface`;
- `SettingsLayoutInterface` → `StorageLayoutInterface`;
- `EncryptedSettingsLayout` → `EncryptedStorageLayout`;
- `AddressSettingsLayout` → `AddressStorageLayout`;
- `OperationContext::withStarting()`, `$starting` → `withDefaults()`, `$defaults`;
- `Rule::describe()` → `Rule::because()`;
- `#[Alters(resource:)]` → `#[Alters(of:)]`;
- `#[DerivesResources(overrides:)]` → `#[DerivesResources(overriddenBy:)]`;
- `KeyedList::add($id, ...)` → `KeyedList::add($key, ...)`;
- `Pipeline\Omitted` → `Pipeline\NotEmitted`;
- `AccessAnswer::answer()` → `AccessAnswer::getAnswer()`;
- `Storage\StateStorage` → `Pipeline\Storage\StateStorage`;
- `Storage\ConfigEntityStorage` → `Pipeline\Storage\ConfigEntityStorage` (back in `Storage\` on 2026-10-23, a resource's storage: [A placed block is a resource](#a-placed-block-is-a-resource));
- `Storage\FieldSettingsStorage` → `Pipeline\Storage\FieldSettingsStorage`;
- `Storage\BaseFieldOverrideStorage` → `Pipeline\Storage\BaseFieldOverrideStorage`;
- `Storage\PluginConfigurationStorage` → `Pipeline\Storage\PluginConfigurationStorage`;
- `Storage\CompositeStorage` → `Pipeline\Storage\CompositeStorage`;
- `Licence` → `License`, "Event licence" → "Event license";
- `event.licence` → `event.license`, the examples' def id;
- `licence` → `license`, the examples' stored key;
- `ResourceCatalogue` → `ResourceCatalog`;
- `surface.resource_catalogue` → `surface.resource_catalog`;
- `docs/catalogue.md` → `docs/catalog.md`, `scripts/generate-catalogue.php` → `scripts/generate-catalog.php`;
- "labelled", "honour", "colour", "behaviour" → "labeled", "honor", "color", "behavior".
<!-- cspell:enable -->

Deferred:

- The contract's `surface` and `situation` keys: a wire change, so it
  waited for a version of the contract. They moved with
  [contract 2](#contract-2) on 2026-10-20, to `resource` and
  `operation`, as did the `x-surface.widget: "slot"` hint, now
  `variants`.
- The resource test module's `/surface-test/...` paths and the entity
  test module's "bypass surface entity test field access" permission:
  only tests read them, and URL paths and permission names follow the
  product rule above.
- Dated records (`REWORK.md`, `PLAN.md`, `ADOPTION.md`, `HARDENING.md`,
  the fresh-eyes log, the never-stricter audit) keep the words of their
  day.

### The surface was counted and cut

Decided 2026-10-24, by the owner, from the API consolidation review
([the decision list](reviews-2026-10-24/api-consolidation.md)), which
counted every public name an author writes or a client reads and asked
of each whether it is used, whether it says what it does, and whether
another name already says it. Groups A (renames), B (merges) and C
(drops) were approved and built; group D, the wire changes, is contract
4, built apart the same day
([Contract 4](#contract-4-fewer-keywords-nothing-new-on-the-wire)):
`locked`, `declared` and `access` gone, `fetch` and `entries` inside
`deferred`, `list` renamed `collection`, which makes the total 177. No emitted document of the examples moved but one
label, the `committed` output's title, now "Committed" (A6).

| Kind | Before | After |
| --- | --- | --- |
| Attributes | 11 | 11 |
| Attribute arguments | 42 | 39 |
| Blueprint methods | 17 | 15 |
| Interfaces an author implements or names | 24 | 20 |
| `OperationContext` public methods | 9 | 8, beside `constraints()`, the build's; 9 since 2026-10-28 |
| Settings | 2 | 2 |
| `x-surface` keywords | 35 | 35, 30 since contract 4 |
| Module result codes | 20 | 20 |
| Rule methods and conditions | 17 | 17 |
| Widget words | 18 | 15 |
| Total | 195 | 182 |
| Rows of [concepts](concepts.md) | 49 | 44 |

*Grown 2026-10-24,* by the alter-pattern survey's three gaps: two
attribute arguments, `#[SurfaceWidget(claims:, priority:)]`
([A widget is chosen from the definition](#a-widget-is-chosen-from-the-definition)),
one definition setting a claim reads, `format`, and one config object,
`surface.defaults`
([A site sets a default in configuration](#a-site-sets-a-default-in-configuration)).
The widget attribute is in `src/Attribute/`, which `PublicSurfaceTest`
does not count, so its pins are unchanged.

*Grown 2026-10-28,* by one method: `OperationContext::withDefaults()`,
beside `defaults()`, the build's, restored for a route that fills keys
on one page, the shipped use C5 found missing
([A route may fill or fix what an operation asks for](#a-route-may-fill-or-fix-what-an-operation-asks-for)).
The table's row says it; `PublicSurfaceTest` does not count a
context's methods, so its pins are unchanged.

`PublicSurfaceTest` pins what `src/Resource/` declares, so the count
grows only with that test updated beside a reason: 9 attributes, 35
attribute arguments (33 before; 30 until 2026-10-25, when
`#[Operation(gate:)]` was added, [A gate is borrowed, never
copied](#a-gate-is-borrowed-never-copied); 31 until 2026-10-31, when
`label:` and `description:` were added to `#[Resource]` and
`#[Structure]`, [A resource and a structure may say what they are
called and what they are for](#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for)), 16 blueprint methods (17
before; 15 until 2026-10-27, when `resolve()` was added, [A key may
stand for another at use](#a-key-may-stand-for-another-at-use)), 21
interfaces (23 before; 20 until 2026-10-28, when
`EntrySchemasInterface` was added, a provider that answers an entry's
schema when a door reads it, [The static build is cached, the rest is
per request](#the-static-build-is-cached-the-rest-is-per-request)). It
is the concept budget for the code, as
[Your first resource](first-resource.md) is for the docs.

What changed, old → new, to grep:

<!-- cspell:disable -->
- **A1, the docs say what the code does.** `Rule`'s docblock names
  `because()`; the install file says a list past its limit is
  `deferred`; the served contract's "Three markers" is "Markers", the
  table's title; concept rows for a key that does not apply (led by the
  declared rule), `describe()` and `extendChoices()` (no numbered
  example calls the second), and Layout.
- **A2.** `BlueprintInterface::derive()` → `compute()`; `DeriverInterface`
  → `ComputesValueInterface`, its static `derive(array $from)` →
  `compute(array $from)`; `Derive::ON_CREATE`, `ON_CHANGE` →
  `Compute::ON_CREATE`, `ON_CHANGE`; `PipelineGuard::guardDeriver()` →
  `guardCompute()`; `Computed::$deriver` → `$using`; the PHPStan
  identifier `surface.deriver` → `surface.compute`. "Deriver"
  names one thing, a class that makes resources at discovery. The wire
  keyword `computed` and the result code `computed` stay.
- **A3.** `#[Operation(values:)]`, `OperationDefinition::$values` →
  `structure:`, `$structure`; the tool input `values` and the envelope
  key stay.
- **A4.** `surface.settings:reference_enum_limit` →
  `reference_list_limit`, beside `site_list_limit`, the stored value
  moved by `surface_post_update_reference_list_limit()`.
- **A5.** The stock widget plugins `options`, `map`, `boolean` →
  `select`, `fieldset`, `checkbox`, the words `x-surface.widget` and the
  `widget` setting say; a `hook_surface_widget_info_alter()` keyed
  by an old id finds nothing.
- **A6.** The tool's `committed` output labeled "Written" → "Committed";
  `drush surface:contract-diff --dir` → `--baselines`, `--dir` still
  read.
- **B1, one provider for a list the site decides.**
  `KeysFromSiteInterface::keys(): KeyedList` and
  `VariantsFromSiteInterface::variants(): VariantList` →
  `SiteListInterface::list(): SiteList`; `ClosedVariantsInterface` →
  `ClosedSiteListInterface`, its entries a value and a label, the
  structure named once, in `structures()`; `VariantList::add($value,
  $label, $schema)` → `SiteList::add($value, $label, schema: $schema)`,
  `addStructure($value, $label, $class)` → `add($value, $label,
  structure: $class)`. `addEach()`, `attachEach()` and
  `attachVariants(from:)` each refuse an entry carrying what they do not
  read.
- **B2.** `DerivedVariantsInterface`, `DerivedVariants` and the
  `surface.derived_variants` tag are gone: the field types'
  settings come from `FieldSettingsSchemaVariants` and
  `FieldStorageSettingsSchemaVariants`, providers the field resources
  name, counted against `site_list_limit`
  ([Derived variants](#derived-variants)). Held at first, for its
  change of behavior, and approved the same day.
- **B3.** `AltersAccessInterface` → `OperationAccessInterface`, the role
  read from `#[Alters]`; an access class named on `access:` that carries
  `#[Alters]` is refused
  ([An alter may answer access](#an-alter-may-answer-access-and-the-stricter-stands)).
- **B4.** The concept rows "Child operations" (into the operation
  context row) and "Transformations" (a pointer in the page's opening).
- **B5.** `_surface_operation_access: 'TRUE'` is implied: a route
  whose defaults name `_surface_resource` and
  `_surface_operation` gets it from `OperationRouteSubscriber`, and
  one that says it is read the same. Every shipped routing file dropped
  the line (42 entries; the resource test module's larder route keeps it,
  to show it is still accepted), and the first page's quoted route with
  them, two lines shorter, its concepts still four. *Amended
  2026-10-29:* the subscriber also refuses, when the router is rebuilt,
  a route written wrong in a way the route alone shows ([A route may
  fill or fix what an operation asks
  for](#a-route-may-fill-or-fix-what-an-operation-asks-for)).
- **C1.** `BlueprintAdditionsInterface::with()`, the deprecated alias of
  `mixIn()`, removed.
- **C2.** `BlueprintInterface::fromSchema()` removed; a document is read
  through a variant from the site or registered with
  `SchemaReader::structure()`.
- **C3.** `#[RefinesInput(watches:)]` removed: the parameter names say
  what a refiner watches.
- **C4.** `#[Operation(default:)]` and `DerivedOperation::$default`
  removed: a child is invoked through its operation of its parent
  operation's kind ([One invocation model](#one-invocation-model-for-both-levels)).
- **C5.** `OperationContext::withDefaults()` and the constructor's
  `constraints:`, `defaults:`, `children:`, `childOperations:` removed;
  the constructor takes `id`, `creates` and `known`, the rest the
  withers, and starting values are gone
  ([Starting values without creating](#starting-values-without-creating)).
  *Restored 2026-10-28:* `withDefaults()`, with its rule, for a route
  that fills keys on one page; the constructor still takes `id`,
  `creates` and `known`
  ([A route may fill or fix what an operation asks for](#a-route-may-fill-or-fix-what-an-operation-asks-for)).
- **C6.** `#[Operation(of:)]` removed: an operation stands on its
  resource's or structure's own class, and one on any other class is
  refused.
- **C7.** `AltersOutputsInterface` and the `third_party_outputs` mount
  removed: what a call answers is its operation's, and a reaction to a
  save belongs to ECA. Held at first, as a capability with no user, and
  approved the same day.
<!-- cspell:enable -->

A removed attribute argument is refused at discovery with what replaced
it, before the attribute is instantiated, so the message says more than
PHP's unknown parameter: `watches:` (the parameter names), `default:`
(the operation of the parent's kind), `of:` (an operation stands on its
own class). An alter that still implements `AltersOutputsInterface`
cannot be loaded, and is refused by name where the scanned directories
are read; one that keeps `alterOutputs()` is refused where the alters
are read. Each removal is additive to bring back, and no tag has carried
any of them.

Group D, the wire changes, was held for contract 4 and approved,
D5 with it, on 2026-10-24
([Contract 4](#contract-4-fewer-keywords-nothing-new-on-the-wire)).
(`PublicSurfaceTest`, `ResourceRegistry::REMOVED_ARGUMENTS`,
`ResourceCollectorPass::reflect()`, `OperationRouteSubscriber`,
`OperationRouteAccessTest`)

### The ids are the standard five, the labels are Drupal's

Decided 2026-10-23, by the owner. An operation's id is what a program
reads: the tool id (`surface:event.venue:create`), the custom
method after a path, `x-surface.operation.id` in both documents, a
route's `_surface_operation`. Its label is what a person reads:
the form's title and the tool's. The default ids are the kinds' own
names, `create`, `read`, `list`, `update` and `delete`, and Drupal's
words for them stay in the labels: `create` is "Add a venue", `update`
is "Configure the newsletter" or "Edit a content type", `delete` is
"Remove a placed block".

- **Why the standard ids.** An agent choosing between tools and a client
  generator naming methods expect these five; `add`, `edit` and `remove`
  were Drupal's words for the same kinds, which a reader outside Drupal
  has to map first. An operation's kind (`OperationDefinition::kind()`)
  has carried these names since
  [An operation states how it behaves](#an-operation-states-how-it-behaves),
  so the id of a default and its kind now agree.
- **Why the labels keep Drupal's words.** People read the label, and a
  Drupal site's people read "Add", "Edit" and "Remove" on every form. A
  label changed only where it repeated the old id.
- **`configure` for an update on a singleton.** A resource with no
  identity is a settings object, and every settings page has said
  "Configure" for twenty years, so `configure` stays an accepted id for
  an update there, a default as `update` is. On a resource with identity
  it is a custom method like any other. The first resource and every
  settings example keep it.
- **`read`, not `get`.** The same operation is a form, a tool and an
  HTTP path. `get` names one door's method; `read` says what the
  operation does at all three, and is its kind's name.
- **The rule.** The default of a kind at a path is the operation whose
  id is the kind's standard name (`create`, `update`, `read` or `list`,
  `delete`), or `configure` for an update on a singleton; else the only
  operation of that kind there; else none, and every operation of the
  kind is a custom method
  ([Contract 3](#contract-3-the-operation-is-the-method);
  `OperationDefinition::defaultIds()`, `ServedPaths::defaults()`). A
  custom operation keeps any name (`check`, `reorder`, `reuse`).
- **The old words are refused at discovery.** An operation id `add`,
  `edit`, `remove` or `get` is refused when discovery reads it, the
  message naming the standard id that replaces it
  (`OperationDefinition::RETIRED_IDS`). Refused, not warned: a warning
  is read once and the id kept, and every tool id, path and document
  built from it carries the old word on. A contract 2 path that carries
  one is still gone, read as the renamed operation's
  (`ServedPaths::CONTRACT_2_IDS`).
- **What followed.** Every shipped operation so named was renamed with
  its method, its label kept: the venues, the placed block, the content
  type, the field and its storage, every content entity resource and
  field item, and the test modules'. Tool ids follow
  (`surface:event.venue:update`, `:update:read`, `:delete`); the
  read tool's suffix stays `:read`. Route names follow the id
  (`surface_examples_block.update`, `surface_examples.step15_delete`).
  A path keeps Drupal's own convention where it had one, `/add` and
  `/edit` (`/admin/structure/block/surface/add`,
  `/surface-entity/node/{id}/edit`, `/admin/structure/types/surface-add`);
  a removal's path, `/remove`, followed no convention, and is `/delete`,
  Drupal's own word for that path.
  (`OperationDefinition`, `ServedPaths`, `ServedPathsDefaultsTest`,
  `OperationFactsTest::testRetiredIdsAreRefusedAtDiscovery()`,
  `ServedPathsTest::testContractTwoPathsAreGone()`)

### A resource has storage or a host

A `#[Resource]` with no storage of its own is accepted when it names
identity or a plugin names it: a plugin's host supplies the storage, and
an identity-bearing child (the cupboard's bin, the spice rack) is stored
by the parent it is attached to, which discovery cannot see, since
attachment is declared inside `defineInputs()`. One with neither is
refused at discovery. Asking such a resource for its storage on its own
is refused by `Resources::storage()`. *Open:* whether an attached
resource with no storage should be a structure whose identity its parent
hands it, which would need identity on a structure.
(`ResourceRegistry::discover()`, `Resources::storage()`)

### Blueprints and layouts

What `defineInputs()`, `defineOutputs()` and `alterInputs()` are handed
(and `alterOutputs()`, until 2026-10-24: [The surface was counted and cut](#the-surface-was-counted-and-cut)) is a **blueprint**: `BlueprintInterface` for
the owner, `BlueprintAdditionsInterface`, add-only, for an alter. It is
the thing a resource or structure declares its members into, as a
migration declares a table's columns into the table's blueprint. It was
called a shape, but in Smithy a shape is a named type, and the builder
its members are declared into is not one; a named scalar shape, a
`#[Shape]` class reused at many keys, is
([A shape is a named definition](#a-shape-is-a-named-definition)), and
the two never share a name. How accepted values are laid out in storage, where that
differs from how they are asked for, is a **layout**:
`StorageLayoutInterface`, and `AltersStorageLayoutInterface::storageLayout()`
for an alter's mount (and, *added 2026-10-27*, `ConfigObjectStorage::layout()`
for a config object, [A layout is the storage's one word on its
shape](#a-layout-is-the-storages-one-word-on-its-shape)). "Shape" is the named type, of three kinds, and in
code names the third, `#[Shape]`. (`ResourceBlueprint`,
`ResourceBlueprintAdditions`, `BlueprintAdapterBase`)

### A shape is a named definition

A scalar asked for at many keys (a license, a stock code) was written
inline at each: its type, its pattern and the words that explain it,
its description, its examples. A **shape** names it once: a class in
`src/Shape/` carrying `#[Shape('event.license')]` and implementing
`ShapeInterface`, whose static `define()` returns a plain core
`DataDefinition`, used in the type position of `add()`:
`$inputs->add('license', License::class, t('Event license'))`. Resources,
structures and these named scalars are the three kinds of shape, in
Smithy's sense of a named type. The compliance license is the first
case, the test module's stock code the fixture. Each choice, and why:

- **A named definition, not a data type plugin.** Core's answer to a
  reusable value is a typed data plugin: a class, a definition class, a
  plugin id every consumer has to know, and a type nothing outside
  Drupal reads, which is too heavy for four digits with a label. A shape
  is the definition itself, named: what a key holds is still a
  `string`, so the widget, the pipeline's coercion, the Tool API's
  flattened definitions and the config schema read it as any string,
  and nothing downstream learns a new type. `define()` returns a plain
  definition rather than taking a blueprint: one value has nothing to
  declare into, and the core setters already say all of it.
- **What is the key's stays the key's.** `define()` declares no default
  and no required flag, refused when the shape is used, since what a key
  starts from and whether it must hold a value differ by key; and a
  scalar only, since a group of keys is a structure. A shape attached,
  listed or mixed in is refused naming `add()`. Discovery refuses what it
  refuses for the other kinds: both attributes on one class, an
  instance `define()`, no interface, and an id another shape, resource
  or structure claims, since all of them name defs in one `$defs`.
- **Additions at the key narrow only.** The key starts from a copy of
  the shape as its alters left it and adds what any key adds: its label,
  required, a default, a constraint, a description of its own. Once its
  owner and every alter have declared, the build holds each such key to
  the narrowing check a refiner's answer is held to, against the shape,
  and refuses one that widens (a higher maximum, a second pattern, a
  bound dropped), naming the key, the shape and what widened. Core's
  `addConstraint()` replaces a constraint of the same name, so a key
  tightening a bound the shape already has restates the others it keeps.
- **One def per shape, the key's additions beside the reference.** Both
  documents write each shape once, as `$defs.<id>`, described as an
  optional key is: its type allows null, since whether a key may hold
  nothing is the key's, and its `x-surface` carries only what the shape
  checks (the pattern's message, what only the server checks). Each key
  declared with it is `$ref` first, then its title, then only what it
  says differently (its type without null where it is required, a
  default, a bound it narrows, its own description) and its whole
  `x-surface`, which a renderer reads whole at every property. Sibling
  keywords rather than `allOf`: in 2020-12 a `$ref` beside other
  keywords is their conjunction, the same narrowing an attached child's
  site composes, and with `$ref` first `SchemaResolver::inline()` and
  the React app's `inlineSchema()` lay the def where the reference stood
  and the key over it, keyword by keyword, which agrees with the
  conjunction because the key only narrows. A conditional's `then` still
  says only what it narrows and refers to nothing; the never-stricter
  invariant is held per key by the matrix (the depot's two keys).
- **A port may read a shape.** `$inputs->reads('code', Sku::class)`
  declares a port of the shape's type that only a key declared with the
  shape may be wired to, narrowed or not: the port's readers may trust
  the value to be one the shape allows, which a key of the bare type
  does not promise. A port that reads a type takes a key of a shape of
  that type, as it takes any key of the type. The def's
  `x-surface.ports` names the shape.
- **An alter of a shape tightens it everywhere.** `#[Alters(Sku::class)]`
  on an alter whose `alterInputs()` is handed the shape, named by its
  id: `narrow('<id>')` with no condition (a bound tightened, a subset of
  a list, a pattern where it has none, inside the subset both engines
  read alike) and `describe('<id>', ...)`. Every key using the shape on
  the site gets it, and the def carries it. What mounts, attaches, mixes
  in, offers more values, requires or rules out is refused naming the
  call; an alter of a shape naming operations, declaring a
  `#[RefinesInput]` method or not implementing `AltersInputsInterface`
  is refused at discovery.

Open: a key, or a container, opting out of a shape's alters, as a
container cannot yet opt out of a structure's; the catalog listing
shapes and who uses them. (`Resource\Attribute\Shape`,
`ShapeInterface`, `ResourceBuild\Shapes`, `ShapeBlueprint`,
`Declaring::assertShapesNarrowed()`, `Attaching::unshaped()`,
`DefinitionMetadata::shapeOf()`, `PropertySchema::named()`, `isPart()`
in `app/src/contract.ts`)

### Variants, not slots

A key whose structure a sibling key chooses was a **slot**, named for
the hole. It is **variants** now, named for what fills it: structures a
sibling key chooses between, attached at one key with
`attachVariants($key, by: $sibling)`, each the variant for one value of
that key, `#[Variant(of:, at:, for:)]`. In one line, a tagged union
whose tag is a sibling key.

- **The members are what an author writes.** A variant is a class with
  keys of its own; the key the variants sit at is one line in the
  parent. The verb and the attribute now say the word the glossary
  says, where `attachBy()` said neither and `key: 'ticket', value:
  'free'` read as "the ticket is free" when it is the pricing that is.
- **The hole needs no name.** It is a key like any other, and "the
  variants at `ticket`" says all that "the `ticket` slot" said.
- **"Union" is kept** for an untagged data definition core may gain, a
  value of one of several types with nothing beside it to say which.
  These are tagged, and the tag is a sibling, so the two are named
  apart.
- **The overlap with single-directory components goes** as a side
  effect: a slot there is markup a theme fills, and a Drupal reader
  met the word with that meaning first.

The wire kept the old word until the contract's version moved: the
widget hint was `x-surface.widget: "slot"` until
[contract 2](#contract-2), and is `variants`, moved with the
`surface` and `situation` keys
([The product keeps its name](#the-product-keeps-its-name)). In code,
the engine's `Slot` is `Variants`, `ContractEmitter::slot()` is
`variants()` and `DerivedVariantsInterface::slot()` is `variantsAt()`;
`DerivedVariants` already fit. (`BlueprintInterface::attachVariants()`,
`Resource\Attribute\Variant`, `Variants`, `DerivedVariantsInterface`)

**The tags live on the variants** (decided 2026-10-20, from the DX
reviews). `#[Variant(..., label:)]` says what a person is offered the
value as, and a deciding key that declares no list of its own gains a
`LabeledChoice` of the variants' values and labels (a plain `Choice`
when none gives a label; a variant with none is offered as its value).
*Amended 2026-10-31:* a variant whose `#[Variant]` gives no label is
offered by its structure's own label, where it has one
([A resource and a structure may say what they are called and what they are for](#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for)).
The shipped deciding keys drop the lists that restated their variants'
values, and example 3's pricing is offered as Free and Paid, not `free`
and `paid`, in the form and both documents.

- **One place for a tag.** A tag was written on the deciding key's list
  and again on each variant, and its label in neither; now it is
  written once, where zod and Swift write a union's cases.
- **Always open, as the glossary says.** With no list on the deciding
  key, a variant another module adds is offered with no change to the
  parent. A declared list closed the union before: it refused at build
  any variant whose value it did not name.
- **A declared list narrows and never widens.** A list the deciding key
  does declare is a narrowing of the variants' values: a variant whose
  value it leaves out is not offered, and a value it names that no
  variant is for (declared or derived, offered or withheld from the
  account) is refused at build, naming the key and the value, because
  it would choose nothing. Before, such a value was dropped silently and
  a variant the list left out was refused; the second is the narrowing
  every other list allows, the first the widening none does. A key that
  declares its list keeps its own words: the variants' labels are not
  merged into it.
- **The order is discovery's.** A derived list offers the variants in
  the order discovery found them, as `Variants` always held them; an
  owner wanting another order declares the list.

(`Resource\Attribute\Variant::$label`, `SurfaceBuilder::attachVariants()`,
`Attaching::assertListNarrowsVariants()`)

### A page stands without the decisions page

Decided 2026-10-26, from the fresh-eyes runs: the second reader counted
more than fifteen links from the build pages into this one and could not
tell which held an answer needed to build and which held history
([the log](fresh-eyes-2026-10-19.md), entry 13), and the third met "a
key left out keeps what is stored" as a surprise, since for a key other
than a list it was stated only here
([the log](fresh-eyes-2026-10-19b.md), entry 23).

- **The fact is on the task page.** A rule, a default, the cause of a
  refusal or what a spelling does is stated in a sentence or two where a
  builder meets it. This page holds the reasons, and a page that cannot
  be followed without it is fixed on the page.
- **One form for the link.** A page links an entry as
  `(why: [decision](decisions.md#...))`, after the fact it explains, so a
  reader learns once that every such link may be skipped. The README and
  the documentation index say so.

## Authoring

### A resource or structure is static

`DefinesInputsInterface::defineInputs()` and `DefinesOutputsInterface::defineOutputs()`
are `public static`, and the build step calls them on the class, never
on an instance; a resource's or structure's `#[RefinesInput]` methods
are static too, and one that is not is refused when it is built. What it declares is a
property of the class: it takes no values, no context and no services,
and static enforces that by the language rather than by a docblock. It
also lets a plugin class be its own structure without anything
constructing the plugin to ask (see [A plugin that is its own
structure](#a-plugin-that-is-its-own-structure)), and it keeps an object
out of every cached form: the owner's link in the refiner chain holds
the class name, where an instance carrying `StringTranslationTrait` was
measured at about 721 bytes more per cached form. `RefinesInputRefiner`
dispatches a static and an instance method alike, but **a static
refiner is a static method, an owner's and an alter's alike**: an
alter is an autowired service and may hold configuration a refiner
reads, a site's shipping zones, say, and a refiner that reads it is a
runtime refiner, the one kind that may be an instance method (see
[Static by default, runtime by declaration](#static-by-default-runtime-by-declaration)).
PHP itself refuses a class that implements
the interface with an instance `defineInputs()`, so discovery names the
one that dropped the interface to get one past the compiler.
(`Resources::buildResource()`, `Binding::assertStaticOnResource()`,
`ResourceRegistry::assertResourceClass()`)

Translatable strings in static context use the global `t()`: a
resource's or structure's `defineInputs()`, refiners and operations, an alter's
static refiners (with `PluralTranslatableMarkup` for a plural), a
plugin's static
protocol methods, an enum's labels, a value object's static helper.
`t('Label')` returns the same lazy `TranslatableMarkup` that
`new TranslatableMarkup('Label')` does, and string extraction finds
it; the coding standards' reason to prefer `$this->t()` is injection,
which does not apply where nothing can be injected, and
`DrupalPractice` does not flag `t()` in a static method.
`Drupal.Semantics.FunctionT` holds it to literal strings, so a value is
always a placeholder. `new TranslatableMarkup` stays only inside
attribute arguments (`#[Operation(label:)]`, plugin attributes), where
a function call is not allowed; a class the container builds keeps
injecting `string_translation` and calling `$this->t()`.

### An operation on the class

`#[Operation]` may stand on the resource's or structure's class,
repeatable, and is then the whole operation: its context is the bare
one, `new OperationContext()` with the attribute's id and `creates`
stamped on it, and there is no method. Decided 2026-10-20. **The class
form is the default and the method the exception**: a method only when
the context needs more than the attribute says, parameters, identity it
knows, a constraint, defaults or a child. Every shipped singleton is
written on the class (the examples, the first module's two resources),
and the content type, the field and the field storage keep methods
because their contexts know or narrow something.

- **Why.** Thirty-eight operations in the module, twelve of them in
  shipped modules and every one of the examples' and the first module's,
  had a method whose whole body was the context, `return new
  OperationContext('configure');`, restating the attribute's id in a
  second place that had to agree with it. The method form exists so that what an operation needs is its own
  signature, and a bare operation has no signature; the first page a
  newcomer copies carried the five lines for nothing.
- **Against the earlier refusal of a second spelling.** [A rule
  declared as data](#a-rule-declared-as-data) rejected an attribute
  for rules partly because two spellings of one rule would be
  documented twice. That reason does not carry over: the class form
  carries no code at all, so there is nothing to keep in step with a
  method, and the method form is needed exactly when there is something
  to write in it. It is one attribute in two places, not two
  vocabularies; the docs teach the class form first and say "a method
  only when the context needs more".
- **What stays on the attribute.** Everything static about an
  operation, wherever it stands: id, label, `description`, permission,
  `creates`, `readOnly`, `destructive`, `idempotent`, `structure`
  (`default` and `of` until 2026-10-24, [The surface was counted and cut](#the-surface-was-counted-and-cut)).
  The catalog, the tool listing and the kind read them without running
  anything ([Whether an operation creates](#whether-an-operation-creates)).
- **A description.** `description:` says what a call does to what, for
  whoever chooses between operations, an agent first among them: it is
  the first sentence of the tool's description and the top
  `description` of both documents. With none, a method's docblock
  summary stands in (a comment, so not translatable; an operation read
  in another language says it on the attribute), and an operation on
  the class has none, its tool described by its label.

The sandbox modules and the test fixtures that keep the method form
work unchanged: a method that names the attribute's id is the same
operation. (`Operation`, `ResourceRegistry::discover()`,
`OperationDefinition::isBare()`, `Resources::operation()`,
`OperationFactsTest`)

### A rule is a keyword first, a list second, code last

Decided 2026-10-26, with example 2's venue moved to a reference. Which
constraint an author writes is a ladder of three steps, taken from the
top ([Which constraint to write](options.md#which-constraint-to-write)):

- **A stock constraint with data options first.** `Range`, `Length`,
  `Count`, `UniqueItems`, `NotBlank`, `Regex`, `Email`, `Choice`,
  `LabeledChoice`: the ones the emitter writes as JSON Schema keywords.
  Why: a keyword is read by every client. The schema reader turns it
  back into the constraint, the React page narrows on it with no
  request, and a tool caller reads it before calling. JSON Schema has a
  closed set of keywords and a generic validator ignores any other, so
  a constraint with no keyword tells a client its name and nothing it
  can check.
- **A list the site decides second.** A constraint that names a kind,
  read by a resolver (`PluginExists`, `EntityBundleExists`,
  `LanguageExists`, `Country`), or the type string `entity:<type>`. Why:
  the class stays literal and holds no service, the resolver lists for
  the site, and the schema states the list as a `oneOf` while it is
  short, looked up or `deferred` past its budget, and a key a static
  refiner narrows by it stays `enumerated`. Where no stock constraint
  names the kind, a module writes one per kind with its resolver, as
  example 2 does for a venue's rooms.
- **A constraint of the author's own last.** Only for a rule no keyword
  and no list states: a comparison between two keys, uniqueness against
  what is stored, a check of site state with a reason of its own. A
  create's identity held once is no author's: the build adds the stock
  `SurfaceUnique` (*amended 2026-10-26*,
  [A machine name is a computed identity](#a-machine-name-is-a-computed-identity)). Why
  last: the schema can only name it under `checkedOnServer`, nothing
  reads it back, nothing narrows on it in the browser, and a caller
  learns it when a call is refused. One that is written extends the
  closest stock constraint, so its validator, refusal and result code
  come with it. *Amended 2026-10-26:* the example's
  `SurfaceExamplesExtrasUniqueItems` was a step three constraint for a
  rule JSON Schema has a keyword for, so it moved to step one as the
  stock `UniqueItems`, written as `uniqueItems: true` and read back.

Example 2's venue was a `Choice` subclass with a validator and a
resolver of its own; it is `entity:event_venue` now, with no class. Its
room keeps a constraint, since no stock one names a venue's rooms.
Rejected: a constraint per resource for every list the site holds,
which is how example 2 started, because each one is code a reader must
open to learn what a key allows, where a type string or a stock
constraint says it.
(`PropertySchema::STATED`, `PropertySchema::keywords()`,
`SurfaceOptions::isStatedByList()`, `RegistrationStep2Resource`,
`EventVenueRoomConstraint`, `UniqueItemsConstraint`)

### Our options answer core's question

Decided 2026-10-27. A key's lists answer core's
`OptionsProviderInterface`: `SurfaceOptions::provider($declared,
$refined)` returns an `OptionsProvider` whose possible values and
options are the declared definition's list, and whose settable values
and options are the refined definition's list, or the declared one when
no refined definition is handed in
([Core's question](options.md#cores-question)).

- **Possible is the full schema, settable is these answers.** Core
  separates the values data may hold from the values that may be set in
  an editing context. The full schema states every value a key may
  hold, read from no values; the schema for these answers states what
  the values in hand allow. The two halves and the two documents draw
  the same line, so each half reads the definition its document reads.
- **The resolver stays the source.** The provider reads the `OptionSet`
  the options service answers and adds nothing to it, so the list that
  validates, the list a form offers, the list each document states and
  the list core's interface answers are one list. The cacheability that
  core's interface has no method for is kept on the provider,
  `cacheability()`, for the doors that cache what they render.
- **The account is not read.** Core's methods take one to filter by.
  Here a definition is refined for an account when it is built, so the
  caller hands in the definition built for that account; reading the
  account again would be a second answer that could disagree.
- **A core consumer needs no knowledge of this module.** Anything that
  reads an `OptionsProviderInterface` reads a key's list from the
  provider, with no resolver, constraint or `OptionSet` in its code.

The select widget reads its `#options` through the provider, so the
generated form asks the question in core's words.
(`Options\OptionsProvider`, `SurfaceOptions::provider()`,
`OptionsWidget::buildElement()`, `OptionsProviderTest`)

### A key is named once, as a constant

Decided 2026-10-27. A class names each of its keys once, as a constant
in declaration order, `public const VENUE = 'venue';`, and every later
mention is the constant: `add(self::VENUE, ...)`,
`#[RefinesInput(self::ROOM)]`, `narrow(self::ROOM)`,
`Condition::equals(self::VENUE, ...)`
([Key names](declaring-a-resource.md#key-names)).

- **The question is the editor's.** A key named as a string in five
  places is five strings: an editor cannot rename it, and find usages
  finds nothing. A misspelled key is still refused at build, naming it;
  the constant moves that to the editor, before anything runs, and makes
  a rename one refactoring.
- **What it does not solve.** A refiner's parameter is matched to the
  key it watches by name, `string $venue`, so the name is spelled there
  and must match the constant's value; the refiners on the second page
  and in example 2 each say so in a docblock. The generator writes no
  refiner. Another module's alter names the owner's keys by
  string too, or by the owner's constant where it chooses to import the
  class; a rename in the owner is a breaking change for it either way,
  which the contract diff reports. Nothing on the wire changes: the
  constant's value is the key.
- **Who carries it.** The generator writes it in every mode, for the
  resource and each structure it writes, with a rule for keys whose
  names would clash ([Key names](generating.md#key-names)). The first two
  pages and examples 1 and 2 carry it, since they are what an author
  copies first and what the generator's output is held to
  ([The generator writes what the first page shows](#the-generator-writes-what-the-first-page-shows)).
  Examples 3 to 17 keep their strings: each shows one concept, the
  constants would add a block to every quoted class for no new idea, and
  a key named as a string still works the same. It is a convention, not
  a rule the build checks.
- **The constant carries no comment** (*amended 2026-10-27*). The
  constants are written one per line in one block, with no docblock and
  no blank line between them: the label on the key's `add()` line is its
  comment, and a block of bare constants reads as the method's table of
  contents.
- **Rejected: passing the definition `add()` returns in place of the
  key** ([the review](reviews-2026-10-20/creative-generalist.md)), which
  would need the blueprint to resolve an object to its key by identity
  in every method that takes a key, and leaves attribute arguments as
  strings, which PHP requires to be constant expressions. A class
  constant works in all of them with no change to the API, so
  `PublicSurfaceTest`'s counts stay where they are.

(`Drush\Generators\Resource\ResourceCode::constantNames()`,
`ResourceGeneratorTest::testKeysAreNamedOnceAsConstants()`,
`NewsletterResource`, `StoreResource`, `RegistrationStep1Resource`,
`RegistrationStep2Resource`)

### A resource and a structure may say what they are called and what they are for

Decided 2026-10-31. `#[Resource]` and `#[Structure]` take an optional
`label:` and `description:`, typed as `#[Operation]`'s are and written
as `new TranslatableMarkup(...)`: the label what the thing is called,
the description what it is for. Discovery keeps both on the definition,
whose `label()` and `description()` answer them, else the words of the
one plugin that holds it, else NULL, and a caller names it by its id
([What it is called](declaring-a-resource.md#what-it-is-called)).

- **Why.** The id is for code: a tool id, a path, a key under `$defs`.
  Four readers need a name and a purpose instead: a select offering
  structures as variants, the catalog, a served contract's `$defs`, and
  an agent reading those `$defs` with no other text to go on. Each of
  them printed the id before, `registration.step3.ticket.paid`.
- **Where it is said.** A def's `title` is its structure's label, or its
  shape's label, and its `description` the structure's description; a
  def with neither is titled by its key, as before, and the key stays
  the id. The catalog prints both beside the id. A deciding key offers
  a variant's value by its `#[Variant]` label, else by the structure's
  label. The contract panel names a part by its label, its id after it.
  The schema reader reads a def's `title` and `description` back into
  the structure's label and description, so a document still reads back
  to itself (`SchemaReader::names()`).
- **A plugin's structure takes the plugin's words.** With no label of its
  own, a structure one plugin holds is called what the plugin definition
  calls the plugin (a block's `admin_label`, any other's `label`), and,
  with no description either, takes its `description`. Read at discovery
  from the plugin definitions (`ResourcePlugins::hostNames()`), so
  nothing is said twice and nothing is copied into the structure class.
  Only where one plugin alone holds it: two would call it two things.
- **Two labels, two things.** The label `attach()` gives the key is the
  key's, beside the `$ref`; the structure's label is what the structure
  is wherever it is held. The `#[Variant]` label is the choice's word and
  stands over the structure's: the paid ticket is offered as "Paid" and
  titled "Paid ticket".
- **Rejected: a label on every `#[Variant]`**, the only place a
  structure could be named before. It names a structure only where it is
  a variant, says nothing in the catalog or at an attached child's def,
  and a structure chosen at two keys would say its name twice.
- **Rejected: ids as titles, the status quo**
  ([Contract 2](#contract-2), "`title` on every def, its id"). Stable
  and language-free, but a reader learns nothing from it that the key
  does not say, and a type generator that wants a stable name reads
  the key, which does not change.
- **What followed for served contracts.** Every def whose structure or
  shape has a label changed its `title` from the id to the label, and
  gained a `description` where the structure has one; the contract diff
  reads neither as breaking. The baselines and the documents pinned in
  fixtures were regenerated once, and only those titles and
  descriptions moved. The OpenAPI document's tag for a labeled resource
  gains its `x-displayName`, and a tool title that adds its resource
  (where two operations share a label) adds the label.

(`Resource\Attribute\Resource`, `Resource\Attribute\Structure`,
`ResourceDefinition::label()`, `ResourceDefinition::description()`,
`ResourcePlugins::hostNames()`, `Subschemas::named()`,
`DocumentHead::top()`, `Attaching::variantLabels()`,
`SchemaReader::names()`, `CatalogDocument::names()`,
`ResourceLabelsTest`)

## Context

### A build is not cached across requests

Every request builds afresh; the pattern's "cache by (id, context)" is
not done across requests. A build describes live site state, and a
cache keyed by context alone would serve a stale one; what that state
is, a blueprint says with `addCacheableDependency()`, for a caller that
caches.

*Changed 2026-10-24:* a build for no account (`buildDeclared()`) is
kept across requests, keyed by everything the context says and tagged
with everything it was read from, so the staleness this entry rejected
is the tags' to rule out; a build for an account is still built in each
request ([The static build is cached, the rest is per
request](#the-static-build-is-cached-the-rest-is-per-request)).

Within one request a sealed build is kept (amended 2026-10-20, from the
performance review; the entry was "A build is not cached"): by the
resource, everything the context says, the kind of build (`build()`,
`buildStatic()`, `buildDeclared()`, and whether it is held to its
config schema) and the account kind: none for a build for no account,
the roles and the permissions hash for one for the account alone, and
the account itself beside them for a build with every answer applied,
which a runtime answer may read. That is not the cache this entry
rejected. Its reason was staleness, and a sealed surface carries its
own cacheability, so within a request staleness is detectable: a kept
build is dropped when a tag it recorded is invalidated
(`ResourceRegistryInvalidator` tells the build step as it tells the
registry), every one when discovery's index is read again, and a build
that expires (any max-age but permanent) or varies by a context other
than the account (the route, the URL) is not kept at all. At most 32
are kept, the latest asked, so a worker serving one call after another
keeps the latest few. A build that reads state its cacheability does
not declare is reused within the request; that was already a bug,
since every caller that caches (the schema route, the tool listing)
trusts the same cacheability.

What it saves: a dry-run call to a basic page's update tool built three
times and builds twice (the account's listing, for the account alone,
and one build the refined `values` and the run share); a schema request
built its operation twice and builds it once, beside the build for the
account alone.

The route access check of the React page and the contract endpoints
builds nothing: it asks access with the operation's context alone
(`ServedOperations::contextFromRequest()`), so a response the dynamic
page cache answers pays the access answer and no build. Not from the
static permission alone, which `permissionAccess()` now allows: an
access class or an alter may answer at run time, and only the context
can ask them; what the permission alone decides, it still decides
first, before a parameter is read. The operation form's route check
already asked with the context alone. *Amended 2026-10-29:* a form
route that fixes or fills keys holds them to the resource with one
build for no account when it is matched, kept in the build cache only
when nothing in its context came from the request; one that seeds
nothing holds nothing there ([A route may fill or fix what an
operation asks for](#a-route-may-fill-or-fix-what-an-operation-asks-for)).
(`Resources::buildAs()`, `Resources::keepable()`,
`ServedOperationAccessCheck`, `BuildMemoTest`,
`LongLivedWorkerMemoryTest::testKeptBuildsStayBoundedAcrossContexts()`)

### Child context

A child that invokes no operation of its own sees the context its
parent's hands it with `withChild()`; otherwise it sees the parent's
context id, `creates` and known identity, and not the parent's
constraints or child contexts (or starting values, until they were
removed on 2026-10-24). Those name the
parent's keys, so handing them down would apply them to keys the child
does not have. (`Resources::childContext()`)

### One invocation model for both levels

A child is invoked the way a resource asked on its own is, through an
operation of its own, so that its permission, its access class, its
alters' `operations:` and its known identity mean the same thing at
both levels. The motivating case is an entity form whose every field is
a child, each answering for itself whether this person may change it,
see it, or neither. Which operation is decided when the parent is
built, in this order: the one the parent's context names
(`withChild($key, operation: 'enter', known: [...])`); else the child's
operation whose id is the parent's context id;
else the child's operation with the standard id of the parent
operation's kind (`create`, `update` or `configure`, `read` or `list`,
`delete`); else none, and the child inherits as it always did.
*Changed 2026-10-24:* the third step was the child's default,
`#[Operation(default: TRUE)]`, at most one; the ids decision made the
default of a kind a rule, no shipped child marked one, and `default:` is
refused at discovery ([The surface was counted and cut](#the-surface-was-counted-and-cut)). The parent's
context id is only a hint, because the parent's verbs are the parent's:
a field's `reuse` means nothing to its storage, and a child may have no
operation for a verb at all, so a match is used when there is one and
never required; a match, by id or by kind, that needs identity the parent
does not know is passed over for the next step, because a context that
knows nothing yet is built on purpose (a tool's static definition). Naming comes first because it is the parent saying so
in so many words, and a name the child does not declare is refused
rather than skipped. The child's operation takes its arguments by name
from the identity the parent handed it, then from the parent's; the
context it returns knows both, its own known keys winning on a clash,
because the child's operation is the authority on the child. A context
handed whole (`withChild($key, $context)`) comes before all of these and
is taken as it is, invoking no operation of the child's even when its
id names one of them: the parent built it.

However the child was invoked (named, matched by id or by kind, inherited,
or handed whole), its access class answers, open, locked or omitted, in
the context it was invoked in, and what that answer read reaches the
parent's cacheability. The permission of an operation is asked only where
the build resolved that operation (named, or matched by id or by kind).
Permission and access are two questions. A permission is about entering
an operation, and a parent that built its child's context itself took
responsibility for that entry, so the child's permission is not asked
again: a field's reuse and update build its storage through
`FieldStorageResource::update()`, and the field's own permission is the
gate. Access is about what may be done with what is there, and only
access composes down the tree. A module like field_permissions hides a
single field through access, so a context handed whole must not be a
way around it: were the handed path to skip the child's access class,
any parent could open a field its access closed by building the field's
context itself. An inherited child with no access class is asked
nothing and stays open.
(`Declaring::invokeChild()`, `Declaring::childOperationContext()`,
`Gating::childAccess()`, `OperationContext::withChild()`)

### A context's id is the operation id

The context's id *is* the operation id, and is stamped from the
attribute: an `#[Operation]` method may leave the id out of the context
it returns, and `creates` too, and the engine stamps the attribute's on
it (decided 2026-10-20). One that names another id, or says otherwise
about creating, is refused, at discovery for an operation that needs
nothing (discovery builds it, as it would be built when asked), and
when it is first asked for one that needs a subject, which cannot be
built before a caller names it. Enforcing it is what lets alters
(`operations:`), permissions and catalogs trust it. An operation built
on another calls `withId()`. A context that was never stamped, an
operation's method called directly and handed to `build()`, is refused
there: ask for the operation through `operation()`.
(`OperationDefinition::stamp()`, `ResourceRegistry::assertStamps()`,
`Resources::operation()`)

### Constraints and starting values for an attached key

A context constraint naming the key a child is attached at is refused,
and the message points at `withChild()`. A child is narrowed by its own
context, which is the one door
through the wall
between parent and child. (`Declaring::applyContext()`) *Changed
2026-10-24:* starting values, `OperationContext::withDefaults()`, are
gone, and so is this entry's half about them ([The surface was counted and cut](#the-surface-was-counted-and-cut)).
*Amended 2026-10-28:* a constraint or a default the parent's context
names by a path into the child (`title.value`) is handed to the child's
context, by the path below the key, so the child is still narrowed by
its own context alone; the key itself is still refused
([A route may fill or fix what an operation asks for](#a-route-may-fill-or-fix-what-an-operation-asks-for)).

### Starting values without creating

*Withdrawn 2026-10-24:* a context carried starting values, refused on
one that does not create, until `OperationContext::withDefaults()` and
the constructor's `defaults:` were removed with no shipped use: no
shipped operation starts a new thing from values (a clone). Bringing a
clone back is additive ([The surface was counted and cut](#the-surface-was-counted-and-cut)).
*Restored 2026-10-28:* `withDefaults()` is back with its one rule, for
a route that fills keys on one page: a context that does not create and
carries defaults is refused when the resource is built ([A route may
fill or fix what an operation asks for](#a-route-may-fill-or-fix-what-an-operation-asks-for)).

### Starting values are defaults

*Withdrawn 2026-10-24* with starting values: a starting value was the
key's declared default for that build only ([The surface was counted and cut](#the-surface-was-counted-and-cut)).
*Restored 2026-10-28:* so it is again, written over the site's default
too ([A route may fill or fix what an operation asks for](#a-route-may-fill-or-fix-what-an-operation-asks-for)).

### A site sets a default in configuration

Decided 2026-10-24, from the alter-pattern survey, which found contrib
alters setting `#default_value` on forms throughout. Here an alter may
not change an owner's default, and `withDefaults()` is gone, so a site
that wants a key to start from another value had no way to say so. It
says so in configuration: `surface.defaults`, a list of entries,
each a resource id, a key path and a value.

- **Configuration, not an alter.** Which value a key starts from on a
  site is the site's, exported with its configuration and set with
  `drush config:set`, not a module's code. The declared default stays
  the module's: the build reads the entries of what it builds at the top
  and writes each over the declared default, and the declaration a
  config schema and an install file are written from
  (`Resources::declaration()`) reads none, so a module's install file
  never holds a site's value.
- **A list, because a config key holds no dot.** A resource id
  (`registration.step3`) and a key path (`contact.phone`) hold dots,
  which core refuses in a config key, so both are values of an entry.
- **The value is text, held to the key by the build.** No config schema
  type says "what this resource's key holds" short of `type: ignore`,
  which checks nothing. The value is a string, as a form posts it, read
  through the coercion table (`40` an integer, `true` a boolean,
  `["a","b"]` a list, a JSON object a map, empty for none) and held to
  the key's definition with typed data, its constraints included. A
  value the key does not accept, or a path that names no key, refuses
  the build, naming the key, as any declaration the build cannot honor
  does; the catalog lists the refusal. Checking each entry when it is
  saved would build every resource it names from a config event, and a
  module's update could still make a saved entry wrong later; the build
  is where both are found.
- **What it reaches.** A key the resource declares, a key inside a map
  it declares, a key of a structure attached at a fixed key, and a key
  an alter mounts. Not a key inside variants (each variant starts from
  its own defaults), a collection's item, or a map of keys from the site
  (its provider says what each entry starts from); not a whole attached
  structure, a secret (both documents state a default, and a secret is
  never read back), or a computed key. Each is refused saying why.
- **Every build carries the config object's tag**,
  `config:surface.defaults`, so a change reaches the form, both
  documents and the tool listing at the next request, and the builds a
  request keeps and the derived tools are cleared with it.
- **A stored value stands.** A default is what a key with nothing stored
  starts from: a site default changes a new thing and an unset key,
  never a value already stored. The read tool answers what is stored,
  so it shows none.
- **A plugin's static defaults stay the declared ones.**
  `ResourcesInterface::defaults()`, which a plugin host asks of the
  class with no build (`defaultConfiguration()`, `defaultSettings()`),
  carries no cacheability, and the providers that read it into a schema
  (a block's settings, a field type's) would hold a site's value past
  its change. *Open:* a new plugin's configuration therefore starts from
  the declared default, while the documents of its settings state the
  site's.

(`SiteDefaults`, `Declaring::writeSiteDefaults()`, `SiteDefaultsTest`)
*Amended 2026-10-28:* `withDefaults()` is back, for a context that
creates; its values are written over the site's, with the same reach and
the same holding, both in `KeyPaths`
([A route may fill or fix what an operation asks for](#a-route-may-fill-or-fix-what-an-operation-asks-for)).

### A route may fill or fix what an operation asks for

Decided 2026-10-28, from a site's add page: a basic page whose title is
set, or whose body starts with text, served by the generic `create` of
`entity.content:node.page`, with no operation of its own. A route says
it with two defaults, `_surface_fixed` and `_surface_defaults`,
each a map of key paths to values, which `OperationRoute` applies to the
context the operation built ([forms](forms.md#a-route-that-fixes-or-fills-keys)).

- **Filling is a form's matter, so a route is its level.** A tool call
  and an API write send their values; only a person on a page starts
  from what the page shows. A default at a route changes what one page
  starts from and no other door's contract, so parity between doors is
  not at stake. It is `OperationContext::withDefaults()`, restored with
  this as its use: each value is what its key starts from in this build,
  written over the declared default and the site's, read through the
  coercion table and held to the key, with a site default's reach
  ([A site sets a default in configuration](#a-site-sets-a-default-in-configuration)).
- **Only a context that creates carries defaults.** On an update the
  stored value stands, so a default would be stated and never used. A
  context that does not create and carries defaults is refused when the
  resource is built, naming the operation, and at a route, naming the
  route. *Open:* whether a route may give defaults to an operation that
  neither creates nor updates and whose storage stores nothing, such as
  a check or a report run; until the maintainer decides, the rule holds,
  and `_surface_query` starts such a key from a link.
- **Fixing narrows the contract, so at a route it is true on that page
  only.** A fixed value is `withConstraint($path, 'Choice', ['choices' =>
  [$value], 'surface_fixed' => TRUE])`, nothing new in either
  document's vocabulary: a key fixed so is drawn as that value and
  stated `const` with `x-surface.fixed`, as a deciding key with one
  variant is, and starts from it (a fixed key holds its value, below).
  The option says the Choice fixes the key, and the build
  takes it off before core sees the Choice. The value is held
  to the key first, so one the key refuses is refused naming the key.
  The operation's other doors still accept any value it does: a value
  that must hold at every door is the operation's own constraint, or its
  access class's answer, and a fact nobody should see or send, such as
  who a message goes to, belongs in storage keyed by the operation, never
  in a key a route fixes.
- **A path into a child is the child's context.** A constraint or a
  default at `title.value` is handed to the context of the structure
  attached at `title` (`Declaring::handDown()`), so a child is still
  narrowed by its own context alone ([Constraints and starting values for
  an attached key](#constraints-and-starting-values-for-an-attached-key)).
  One at a key of a map the resource declares narrows that key in place.
- **A refusal is the route's, said as a parameter's.** The route builds
  the resource with its values once, for no account, when it is matched.
  A refusal of them becomes the route's `InvalidArgumentException`, which
  the access check answers with a refusal saying why, as it answers a
  route parameter that names nothing. A refusal the operation meets
  without the route's values stays the operation's. *Amended
  2026-10-29:* a value of the URL that names nothing is no longer
  answered as the route's refusal. The access check asks the operation's
  permission first (below), so an account without it hears the
  permission's reason, the same for every value. A resource id the path
  fills that no resource has is a page not found to any account, answered
  before the permission is asked: without the resource there is no
  operation whose permission to ask. For an account the permission lets
  in, a value that names nothing (`NamesNothingException`: an id no
  entity has, an entity the operation does not take as its own, a
  reference whose value its key refuses) is let through in the request's own dispatch,
  for this request alone, and the form answers 404, at a route the
  operation form (`_form`) or a React page serves; at a route another
  controller serves, it is refused with no reason, since the check
  cannot know that controller reads what the value names and would
  otherwise serve it past the access class; asked about a link
  (`Url::access()`, a menu link, a local task, a breadcrumb), the check
  answers forbidden with no reason, so no link to a page not found is
  drawn (`OperationGate::namesNothing()`). Why: core answers a URL that
  names nothing with 404 and hides a link whose converter loads nothing,
  and the API door already answered 404 to an account holding the
  permission. A refusal of where the route puts a value, and of a plain
  default, stays the route's 403 with its reason, whatever the URL
  carries: only the value is the visitor's. A value a converter loads
  nothing for is core's 404 to any account, before access is asked, as
  before. The gate writes nothing to the log: core's breadcrumb asks
  about every path above the one requested, so a URL anyone may type
  would write a line per request.
- **A React page starts keys and fixes none.** *Changed 2026-10-29:*
  the React page was left out, with a React page that fixes or fills
  keys open, since the generic page reads its operation from its own
  path and calls an API at another. A module now serves an operation's
  React page at a route of its own (`ResourceReactPage::served()`),
  which names the resource and the operation as a form route does and is
  gated by the same check. Its defaults and query prefills are values
  the page hands the app to start from, held as the form holds them; the
  app's documents and its write are the operation's own, at the API, so
  they state and hold nothing of the route. `_surface_fixed` on
  such a route fails the router rebuild: the write at the operation's
  own path holds a value to the operation's constraints alone, so a key
  the page stated `const` would take any value there, the property this
  entry refuses for a value a query sends. Carrying the route's values
  to the API, by a token, a header or the app sending them again, was
  weighed and refused: the API would read where it was called from
  ([Nothing an operation runs reads the
  request](#nothing-an-operation-runs-reads-the-request)), and one
  operation would answer two contracts at one path. *Open:* a
  route-scoped write, an endpoint that holds a page's write to the
  route's context, the one reader serving the access check, the page and
  the write; it would let a React page fix keys, and gives one operation
  a second write path, which is the maintainer's to want.

- **Fixing says so.** A Choice of one value alone is not a fixed key.
  Two shipped operations narrow a key that way, a placed block's update
  its theme and a field storage's update an unlimited cardinality once
  the field has data, and both are drawn as a select of one and stated
  as a `oneOf` of one, which the block's pinned documents hold. Drawing
  every Choice of one value fixed would change those documents, so the
  route's says it fixes the key, and an operation's own may say it too.
  *Open:* whether those two should.

(`OperationContext::withDefaults()`, `OperationRoute::withRouteValues()`,
`Declaring::applyContext()`, `Declaring::writeContextDefaults()`,
`KeyPaths`, `DefinitionMetadata::setFixed()`, `RouteValuesTest`,
`ContentEntityRouteValuesTest`)

*Amended 2026-10-29,* from the plan for seeding an operation from a
route: a page whose seed comes from its own path, a link that starts a
key, one page every bundle shares, and what each door answers when a
value is wrong. Who chooses a value says how it reaches the operation
([forms](forms.md#a-route-that-fixes-or-fills-keys)): a parameter when
the operation must know it, a seed (a fixed value or a default) when one
page states it for every visitor, a prefill when a link offers it or
code computes it at request time. The choices, and why:

- **A seed's value may come from the route's own path.** A value of
  `_surface_fixed` or `_surface_defaults` that is `'{name}'`,
  whole, is a reference: the raw value of the route's path variable of
  that name, read through the coercion table and held to the key as a
  literal is, and left out when the URL carries none, so one route with
  an optional trailing variable serves two URLs. The variable must be a
  variable of the path, bounded by a requirement or a converter, or the
  route is refused. Why: the page's address is what the page is about,
  and the route that bounds the segment is its author's, so a value read
  from it is still the author's choice, within a bound the author set,
  and the key's own constraints hold it. A reference inside text,
  `'Hello {name}'`, is a literal: the key judges a whole value of its
  type, never a fragment of text. Refusing references, so that every
  value from a path is an operation parameter, was weighed and refused:
  a site builder would write PHP in a class they may not own to fix a
  key on one page.
- **One route may serve every bundle.** A derived resource's id may hold
  a bounded variable of the path, `entity.content:node.{node_type}`,
  filled from its raw value before the registry is asked, so one routing
  entry serves every bundle's add page, where every bundle had tools and
  only the basic page a form. The bundle fills the id rather than a key
  because every other door addresses the resource by that id. A filled
  id no resource has is a page not found to any account: without the
  resource there is no permission to ask. *Open:* the edit route for
  every bundle. `/node/{node}/edit` names a node whose bundle is known
  only once it is loaded, and nothing answers which derived resource an
  entity belongs to, so for now the bundle goes in the path; a deriver
  method answering the derivative of an upcast entity, or routes a
  deriver declares (`pages:` on `DerivedResource`, compiled by a route
  provider and rebuilt when the list of bundles changes), would serve
  the edit route itself.
- **What the route alone shows is refused when the router is rebuilt.**
  A `{name}` in the resource's id or a reference that names no variable
  of the path, or one nothing bounds; one path both fixed and filled; a
  query map no link can use; and fixed keys on a React page fail the
  rebuild (`OperationRouteSubscriber`, after core has written each
  parameter's converter), with the class core throws for a reserved
  variable name in a path, `\InvalidArgumentException`, naming the route
  and the fix. Each is asked again when the route is matched, with the
  same words, for a route no rebuild saw. Why: a typo in a routing file
  is found by whoever installs it, at once, rather than by the first
  visitor; what needs the resource built stays at match, so one bad
  route fails as few rebuilds as possible. The cost is the whole router,
  as core's own check costs it: a failed rebuild writes no route of any
  module until the file is corrected.
- **A visitor's query value is input, never the context.**
  `_surface_query` names each key a link may start and the query
  parameter it reads; the form lays the value on its first draw, held to
  the key and dropped without a word when the key refuses it, and puts
  nothing in the context. Why: what is in the context the page states,
  as `const` or `default`, and a query string has no bound the route
  sets, so a crafted link would make the page state a value of its
  choosing; the build cache would gain an entry per value a link sent;
  and what was entered is judged where entered values are judged, on
  Save. A dropped value is never a 403 or a 404, since anyone may write
  a link, and is not logged, since a crafted link may not write a log
  line per request. The keys are listed by name, never the whole query:
  the page reads `destination` and others for itself, and a reader of
  the route sees which links it honors. The values code hands the form
  through `getForm()` are held by the same rule, and code hands values,
  never a context, so the access check and the form read one context
  through one reader.
- **The build that holds a route's values, and which the bin keeps.** The
  access check holds a route's seeds to the resource with a build for no
  account. The build cache keeps it only when the context holds nothing
  read from the request, no value of the path handed to the operation
  and no reference read; otherwise the same build is held for the
  request alone (`ResourcesInterface::holdDeclared()`). Why: a kept build
  stays until a tag it read is invalidated, so a route whose context held
  a value of its path would write a lasting entry per value a URL
  carried, an update route one per thing visited; a literal seed is the
  same on every request, and its kept build is what the next request
  reads. *Open:* the request memo's limit of 32 builds, now that a route
  with a reference holds a build per value, in link access checks too;
  keep it, and measure on the block placement and node pages before
  raising it.
- **The permission is asked first.** At the operation form's route, the
  operation's permission is asked from the resource and operation ids
  alone, before the operation is invoked or anything the URL carries is
  read, as the React page, the API and the tool ask it ([Every door
  projects what the read tool
  projects](#every-door-projects-what-the-read-tool-projects)). Before,
  the context was built first, so an account without the permission was
  told that an id named no entity, or why the route's seeds were wrong.
  Why: what a URL names is told only to an account that may ask the
  operation; any other hears the permission's reason, the same for
  every value. What the route alone shows to be written wrong
  (`OperationRoute::assertWritten()`) is refused before the permission,
  to any account, as the rebuild refuses it: it reads nothing of the
  URL, so it tells nothing of what a value names, and a route no rebuild
  saw is refused in the rebuild's order.
- **Identity is the operation's to say.** Each identity key the context
  knows is set as the key's default and locked, so a context that also
  fixes or fills it is refused, a route's and a parent's alike, before
  the context's values are written
  (`Declaring::assertKnownIdentityKept()`): "The example.rename route:
  The "update" operation fixes "card" on example.card, an identity key
  the context knows: ...". Why: which thing the page is about comes from
  the operation's parameters, and a second statement of it could
  disagree with nothing to refuse the two; asked before any value is
  written, the refusal is this one whatever the value, never a value
  refusal a reference would read as naming nothing. A prefill naming
  one is refused too, on the first draw.
- **A fixed key is one per key.** A key is fixed by the operation's own
  context or by the route, never both: a route fixing a path the
  operation's context already narrows with a Choice is refused when the
  route is matched. Why: a context keeps one constraint of a name at a
  path (`withConstraint()`), so the route's Choice would take the place
  of the operation's, and the page would state a value the operation's
  other doors refuse. One path both fixed and filled by a route is
  refused for the same reason in another form: a fixed value is also
  what its key starts from, so the default would be ignored without a
  word. *Open:* a route's `_surface_defaults` on a key the
  operation's own context fixes is overridden by the fixed value with no
  refusal, at the form and at a React page; refusing it as the route's
  would say so.
- **A fixed key holds its value.** A key a route or a parent context
  fixes starts from the fixed value whatever default it declares, is made
  required where it was optional, is drawn fixed and stated `const`,
  required in both documents, and a submission cannot leave it empty.
  Why: a value nobody may change on the page cannot be one the visitor
  may clear; an optional key fixed was drawn as a choice of the value or
  nothing and stated as either, which fixed nothing. This amends the
  bullet above under which a key fixed so was drawn fixed only where it
  held a value. *Open:* a reference key fixed past
  `surface.settings:reference_list_limit` is labeled by its id on
  the form's item and under `x-surface.fixed`, since past the limit the
  key lists no candidate to read a label from; whether a fixed reference
  looks its label up is the maintainer's.
- **The two page doors answer alike.** The generic React page answers a
  URL that names nothing as the form's route does: let through to a 404
  in the request's own dispatch for an account the permission lets in,
  a 404 to any account for a page path that names no resource or no
  operation, and no link drawn to either. Both access checks ask one
  gate (`OperationGate`) for the operation's permission and for the
  answer to a URL that names nothing; each check writes the order it
  asks them in, and the permission asked again of the URL's values,
  itself. Why: two pages of one operation that answered one URL
  differently would tell a visitor at one what the other hides, and one
  gate keeps the two answers from drifting apart. The API door's
  answers are unchanged.
- **An operation with no permission tells no one what a value names.** A
  derived content entity operation declares no permission: entity access
  decides per entity, once the thing is loaded. Nothing then lets an
  account in before a value of the URL is read, so a node of another
  bundle is refused to every account at every door, as is an id no node
  has wherever no converter answers it first, with no reason at the
  form's route and the one fixed refusal at the React page, the API and
  the tool. Why: passing the permission step where none is declared
  would tell any account, anonymous included, what a value names.
  *Open:* whether derived operations should declare permissions, which
  would make the 404 reachable for them; that is a security change,
  outside this entry.
- **What the page is about is a value of its path.** The subject a
  cosmetic layer is told, which also names the form's AJAX wrapper and a
  delete's confirmation, is the first of the operation's parameters that
  is a variable of the path with a value, never a plain default ([A
  plain route default pins an operation
  parameter](#a-plain-route-default-pins-an-operation-parameter)).

(`OperationRoute::named()`, `OperationRoute::assertWritten()`,
`OperationRoute::withRouteValues()`, `OperationRoute::queried()`,
`OperationRouteSubscriber`, `OperationAccessCheck`, `OperationGate`,
`NamesNothingException`, `Declaring::assertKnownIdentityKept()`,
`Declaring::applyContext()`, `Resources::holdDeclared()`,
`KeyPaths::heldOn()`, `SurfaceOperationForm::prefilled()`,
`SurfaceOperationForm::title()`, `ResourceReactPage::served()`,
`ServedOperationAccessCheck`, `FormRouteDoors`,
`SiteCatalog::routeRefusals()`, `RouteValuesTest`, `RouteAccessTest`,
`RoutePrefillTest`, `RouteCatalogTest`, `ReactRoutePageTest`,
`ContentEntityRouteValuesTest`, `ReferenceKeysTest`)

### Known keys that are not identity

A known key that is not one of `#[Resource(identity:)]` is not locked;
it stays on the context for the storage and the access class to read.
`FieldStorageResource::update()` knows the field storage's entity type and
field name this way, which `FieldStorageStorage` loads by, while
`field_type`, its one
identity key, is locked. (Before storage settings existed, the field
type was known the same way; it became identity when it started
choosing between the settings variants.) *Amended 2026-10-28:* the storage's entity type and field name are
identity too, beside its field type ([A parameter is identity the caller
knows](#a-parameter-is-identity-the-caller-knows)); a field's settings
child still knows the field's bundle this way.

### An entity parameter given as an id

A route parameter or tool input for an operation parameter typed as an
entity interface is loaded by the one entity type whose class satisfies
that type; a type several entity types satisfy is taken only as an
object. A guess between entity types would load the wrong thing.
(`OperationArguments`)

### A parameter is identity the caller knows

Decided 2026-10-28, the owner's rule: a parameter is identity the caller
already knows before the call, and the body is what the call decides. An
add names the parent it adds to and leaves the keys it chooses open in
the body; an edit names the whole identity. Every identity key is named
the same way at every door (route, OpenAPI document, tool, React page),
with the words a person uses, never a composite id a caller has to
assemble.

- **Parts, not a composite id.** The field instance's reuse took
  `FieldStorageConfigInterface $storage`, core's `node.body`, and its
  update `FieldConfigInterface $field`, `node.article.body`: ids that
  join identity keys with dots, which a caller had to know how to
  build. Both take `string $entity_type, string $bundle, string
  $field_name` now, load what they name and refuse, naming the part,
  when it names nothing; `field.storage`'s update takes `$entity_type,
  $field_name`. A parameter named for a resource's one identity key and
  typed as the entity (`node.type`'s `type`, `block.placement`'s `id`)
  is that key already, and stays.
- **One name for the entity type, `entity_type`.** The field resources
  called it `entity_type_id`, the content entity resources
  `entity_type`. `entity_type` is the name core's field config stores
  it under (`field.field.node.page.body` holds `entity_type`, `bundle`,
  `field_name` and `field_type`, the field instance's four identity
  keys), the name the content entity resources, the reference key's two
  parts, a gate's `entity_type` and `id` pair and the example block already
  used, and the words a person says; `entity_type_id` is core's name
  for a PHP variable holding it. So the field resources moved and
  nothing else did: no stored value changed, and the content entity
  resources' documents did not move. The permission is `administer
  %entity_type fields`.
- **The storage's identity is core's.** `field.storage` named only
  `field_type`, the key that chooses its settings, and knew the entity
  type and the field name as keys that were no identity. Its identity
  is `entity_type`, `field_name`, `field_type` now, each a declared key,
  locked wherever its context knows it; as the field's child they are
  the field's, handed in its context on reuse and update, the entity
  type on create, and otherwise learned from the field's accepted
  identity when it is committed. A storage value naming another is
  refused. This amends [Known keys that are not
  identity](#known-keys-that-are-not-identity).
- **The path.** A parameter named for one of the resource's identity
  keys is a path segment, in identity order, from the first key on, as
  far as the operation names them; any other parameter is in the query
  string. One rule at every door: the route reads it, the OpenAPI
  document writes a path parameter for it, the tool names its fetch
  with it, and the React page reads its endpoints off it. A create
  names its parent's path, `POST /surface-api/field.instance/node/article`;
  a path an update is served at names a thing, so a create there is a
  custom method, `POST .../node/article/body:reuse`. The deepest path
  any operation of the resource is addressed at is the thing's, and an
  update at a shorter one, over a parent's things as the venues' reorder
  is over all of them, is a custom method. An identity key no operation
  takes as a parameter is the resource's own and has no segment: a
  content entity resource's entity type and bundle, which its id names,
  `/surface-api/entity.content:node.page/12`. A resource with one
  identity key came out the same: its operations' parameters were
  already named for it. This amends [Contract
  3](#contract-3-the-operation-is-the-method), whose path held the
  parameters of the operation that addresses one thing, in declaration
  order, and put a create's in the query.
- **What a static tool definition knows.** An operation whose
  parameters name a whole thing (an edit, or a create that names as
  much identity as an edit does, the field's reuse) knows every
  identity key, those it leaves out too, so its `values` lists none of
  them. This amends [Identity before the
  parameters](#identity-before-the-parameters).
- **Who hears that a part names nothing.** When the parameters build no
  context, a permission with a `%key` placeholder is filled from the
  parameters as sent: an account holding `administer node fields`
  hears `field_name "nope" names no field of the node entity type.`, at
  the tool and over HTTP (404); any other account gets the one fixed
  refusal, as before.
- **What it closed.** The fetch a tool names carries every parameter,
  `GET /surface-api/field.instance/{entity_type}/{bundle}/schema?choose=/settings={field_type}`,
  where it named the path without them and answered 400 (the open
  point of [A budget for what the site
  decides](#a-budget-for-what-the-site-decides)). A field added over
  HTTP answers 201 with its edit's path as `Location`, which its
  composite id could not give.

*Open:* the field's storage child still repeats the field's identity
keys in its body (`storage.entity_type`, `storage.field_name`,
`storage.field_type`): optional, locked where its context knows them,
and refused when they name another field. An engine rule that a
child's identity key named as one of its parent's identity keys is the
parent's, never asked in the child, would drop them.

(`ResourceDefinition::identityParameters()`,
`ResourceDefinition::namesWholeThing()`, `ServedPaths::depth()`,
`ServedPaths::defaults()`, `OpenApiDocument::operationParameters()`,
`OperationInputs::schemaPath()`,
`ResourceOperationToolBase::sentPermissionAccess()`,
`ServedOperationAccessCheck::access()`, `FieldInstanceResourceTest`,
`IdentityPathsTest`)

### A plain route default pins an operation parameter

Decided 2026-10-29, from a support page: one send operation, one route
per channel, the channel a parameter no key holds. A route default
whose name has no leading underscore is a route parameter, as core
counts it: core copies it into the route match's raw parameters, and
`OperationArguments::fromRoute()` reads each of the operation's
parameters by its name, so `to: 'support'` in a routing file is handed
to the operation's method as `$to = 'support'`, as a path variable would
be, and the method says what its context knows
([forms](forms.md#the-operation-form)).

- **The plain default, not a second spelling.** A `_surface_known`
  default naming the pinned parameters was weighed and refused: the
  plain default is what core's own routes write (an entity's add page
  names its entity type and its bundle parameter this way), the catalog
  prints it as `pins`, and a second spelling for one fact is one more
  thing to learn.
- **One that nothing reads is refused.** A plain default that is no
  variable of the path and no parameter of the operation was ignored in
  silence, so a misspelled `too: 'support'` left the operation's `$to`
  at its own default on every request. It is refused when the route is
  matched, naming the default and the operation's parameters, unless a
  callable core hands route parameters to by name reads it: the route's
  `_controller`, its `_form`'s `buildForm()`, its `_title_callback` or
  its `_custom_access`, read by reflection, so core's
  `EntityController::addBundleTitle()`, which reads `entity_type_id` and
  `bundle_parameter`, still serves. Where one of them cannot be read,
  nothing is refused. An access check of another kind is not read, so a
  plain default only its `access()` reads is refused.
- **One the operation refuses is the route's.** The operation judges a
  plain default as it judges any parameter, and its refusal is the route
  written wrong, a 403 with the operation's reason on every URL. One
  whose value names nothing, a pinned id no entity has, is the route's
  too and never a page not found: the value is the route's literal, so
  no URL can correct it.
- **It is never the subject.** What a page is about is a value of its
  path: the subject a cosmetic layer is told is the first of the
  operation's parameters that is a variable of the path with a value. A
  plain default is the same on every URL of the route, and the route
  match a link's access check builds holds the caller's parameters alone
  among its raw ones, so a pinned parameter taken for the subject would
  name a thing in the request's own dispatch and none in a link check.

(`OperationRoute::assertPlainDefaultsRead()`,
`OperationRoute::fromRouteMatch()`, `OperationArguments::fromRoute()`,
`FormRouteDoors`,
`RouteValuesTest::testPlainDefaultPinsTheOperationsParameter()`,
`RouteValuesTest::testPlainDefaultNothingReadsIsRefused()`,
`RouteAccessTest::testPlainDefaultTheOperationRefusesIsTheRoutes()`)

### One context per walk, not a parameter list

The emitter and the build step each walk a structure, and each used to
hand the same facts from method to method as loose parameters:
`ContractEmitter::frame()` took twelve, `Binding::bindRefiners()`
sixteen. Each walk now carries them in one immutable object, passed
whole.

- **`Contract\Emission`** holds what the walk of one document shares,
  the cacheability, the stale paths and the defs (objects written to as
  the walk goes, so every step collects into the same ones), and what is
  true of the frame being described: its path, the id it was built from,
  the orphans standing in it, and whether it is a def, locked whole, or
  stands for every item of a collection. Descending into a child, a
  variant or a collection item is a `with...()` call that answers a copy.
  Whether a frame is described for one set of values, and the kinds of
  refiner it is answered by, follow from the full schema and the def
  flag, so they are asked of the object and not held.
- **`ResourceBuild\Build`** holds what one build of a resource or
  structure needs: the builder, the definition, the context, the
  ancestry and the owner's frame, how its keys divide (inputs, outputs,
  children, collections, ports), and the contributor whose refiners and
  rules are bound now (an alter's module, the keys it extended and
  mounted, every key any alter mounted, the keys of a structure at its
  mount, the ports of a mixed-in structure mapped to the owner's keys).
  A structure mixed in is built by `forMixin()`, a contributor by
  `forContributor()`.

A reader sees what a walk carries in one place, and not by comparing two
signatures that differ by one parameter. A core reviewer reads a method
signature of a few parameters, each saying what that method decides.
Nothing changed in what is built or emitted: both classes are internal,
and the documents, the sealed data surfaces and the contract baselines
are byte for byte what they were. Neither holds a service of the site
or the request, so neither carries a door-specific thing into a place
that runs on an operation's behalf
([No door-specific context](resources.md#no-door-specific-context)).
(`Contract\Emission`, `ResourceBuild\Build`, `ContractEmitter::frame()`,
`Binding::bindRefiners()`, `ContractBaselineTest`)

### The build is read by phase

`ResourceBuild\Resources` held the build step's order, its caches and
every step between, in five thousand lines. It is split by phase, each
an internal class that `Resources` constructs from the services it
already has:

- **`Declaring`** answers what the thing declares: its blueprints filled
  from the class and its alters, the site's defaults, the context's
  constraints and known identity, and the context a child is invoked in.
- **`Attaching`** answers what the thing holds: each child, collection,
  variant, map of keys from the site, structure mixed in and structure at
  an alter's mount, each built through the whole sequence again in its
  own frame, its ports wired to the parent's keys.
- **`Binding`** answers what each contributor says about its keys: the
  `#[RefinesInput]` methods and the rules declared as data, bound into
  the builder, the owner's before any alter's.
- **`Gating`** answers who may ask and what they may see: the
  permission, the gates an operation borrows, the access classes and the
  alters that answer access, at the top and at each child. It alone holds
  the current user, and only its instance methods read it.

`Resources` stays the one service, and `ResourcesInterface` is
unchanged. It keeps the order (`buildResource()` reads as the sequence of
phases), the memo within a request and the build cache, with the two
builds that set the kind of build under way or are kept by it:
`settleVariant()` and `buildRead()`. A phase that builds a child calls
back into it.

A reader who asks why a key is locked reads `Gating`, and one who asks
where a refiner was bound reads `Binding`; neither reads the other
phases. A core reviewer reviews one phase at a time, its dependencies
named by its constructor. Nothing changed in what is built: the method
bodies moved as they were, and the sealed data surfaces, the documents
and the contract baselines are byte for byte what they were.
(`Resources`, `Declaring`, `Attaching`, `Binding`, `Gating`)

## Refinement

### Gating per key

The engine runs a key's refiners once every sibling any of them watches
has a value, so a key refined by two methods watching different siblings
waits for the union. The pattern runs each method once its own siblings
have values; gating per key is what the engine's refinement edges, the
form's AJAX rebuild and the discard cascade already ride, and nothing
shipped has needed finer. Per-method gating is listed in `ROADMAP.md`, after
the rework, for when a case needs it. (`Binding::bindRefiners()`)

### A refiner that watches nothing

It runs once, at build, after the context is applied; its result is
held to the narrowing check and is what the data surface advertises. The
pattern says it "runs once" without saying when; at build is the only
time that gives every caller the same answer. (`Binding::bindRefiners()`)

### Sibling values are coerced

A refiner's sibling parameters are filled through reflection, so PHP's
non-strict scalar coercion applies: a form's `"1"` reaches a `bool`
parameter as `TRUE`. Forms hand refinement raw input, and a typed
parameter that threw on it would make every checkbox a TypeError.
(`RefinesInputRefiner`)

### A refiner never sees an invalid sibling

Before a key's refiners run, each value they watch is held to its own
key's definition, as refined against the same values, and a value that
definition refuses is passed as if the key held nothing: a whole key is
then unanswered, so [the gate](#gating-per-key) withholds every refiner
watching it and the target stays as advertised, and a key an alter
mounted, which never holds a target back, is handed as `NULL`. So an
event license typed as `EV-2048` gives example 3's capacity the
no-license ceiling of 100, exactly as no license does, and a capacity
of 250 under that ceiling moves no steward minimum. A refusal upstream
is settled before anything downstream is judged: withholding a venue
widens the room, and the room is judged again against that. The values
themselves are not touched, so a form keeps showing what was typed and
the pipeline refuses it under its own key; only refinement treats it as
absent. It is the Tool API's rule, which validates the simulated
dependency values and keeps the unrefined definition on a violation,
and it lives in `Surface::refine()` itself, so the form, the AJAX
rebuild, the pipeline's validate, the served contract and the React
refine all get it from one place. The check is
`Refinement\WatchedValueCheck`, typed data's own validation, sealed
into every data surface the build step makes and serialized by its
service id; a data surface sealed without one, which only the engine's
tests build,
hands every configured value over. A value its key's definition as
declared refuses is withheld before the first pass, since every
definition refined from it refuses it too: until 2026-10-24 the first
pass ran with it, and a refiner typed for a number was handed a word
([Nothing an operation runs reads the
request](#nothing-an-operation-runs-reads-the-request) found it). A
refiner may therefore assume what it is handed satisfies its sibling's
constraints, and
`RegistrationComplianceAlter` tests only that a license is there. The
refined data surface says which values it withheld
(`withheldValues()`), so a refusal that names what a key depends on
says the license is empty and that "EV-2048" was refused, which is what
the rule was handed, and not the value sent (decided 2026-10-20, B11;
[A refusal names what it depends on](#a-refusal-names-what-it-depends-on)).
(`Surface::refine()`, `Surface::refusedWatchedValues()`,
`Surface::withheldValues()`, `Resources::buildResource()`)

### Alters' refiners in the owner's chain

An alter's `#[RefinesInput]` method on an owner's key runs in the
owner's chain, after the owner's own methods. On a key the alter offered
more values on with `extendChoices()`, it is that contribution's
refiner instead, handed the alter's values only. The engine keeps one
chain per contribution, so an alter can tighten the owner's values
without ever being able to take another module's away.
(`Binding::bindRefiners()`)

### Cacheability on the blueprint

`BlueprintInterface::addCacheableDependency()` lets the owner's blueprint say
what site state it read; the data surface carries it. An alter cannot,
because what it adds reaches no further than the owner's keys, and a
static refiner cannot, because it calls no service. A runtime refiner
does read site state, and says nothing of what: its link is max-age 0
(see [Static by default, runtime by declaration](#static-by-default-runtime-by-declaration)).
(`ResourceBlueprint`)

### Static by default, runtime by declaration

A `#[RefinesInput]` method is static unless its attribute says
`runtime: TRUE`. A static refiner promises to be a pure function of the
siblings it watches, so the served contract runs it ahead of time and
states its answers as conditionals (`enumerated`), or says they cannot
be listed (`refine`: ask `POST .../schema` with values, `/refine` until contract 3). A list the
site decides past its budget is neither: it is static and could be
listed, and is left out for its size, `deferred`, fetched per choice
([A list left out for size is deferred](#a-list-left-out-for-size-is-deferred-not-refined)). A runtime refiner
promises the schema nothing: the emitter never runs it, in either
document; the key keeps the width its static refiners give it in the
full schema and carries `live: true` (the markers were `dynamic` and
`runtime` until [contract 2](#contract-2));
the schema for one set of values, which describes that moment, is
refined by it like any other; the pipeline runs it on every validate and
submit and has the last word, as it always had. One that watches nothing
runs on every refinement rather than once at build, and a data surface
refined through one is max-age 0. The engine knows the kind by the link:
the build step binds a class's runtime methods in a link of their own,
wrapped in `Refinement\RuntimeRefiner`, and a key's entry keeps which
siblings only a runtime refiner watches, so asking for the static
refiners alone (`refine($values, FALSE)`) gates each key on what its
static refiners watch.

The kind is declared because the emitter must not guess. Before this,
the emitter inferred which rules it could capture from the shape of the
watched keys, and a rule that reads storage looked exactly like one that
reads a constant: it would have been run once at emission and its answer
written down as true for any values, which is a schema stricter or looser
than the runtime depending on the day, and so not a contract. Uniqueness,
a remote lookup, the current account and the time can never be captured
in JSON Schema; saying so on the method is the only place the knowledge
exists.

The kind is enforced rather than trusted, in three layers, because a
static refiner that reads site state writes a full schema that is
wrong for any values once that state changes (a threshold read from
config is the plain case), and no declaration alone stops it:

- **Structural, at build.** A static refiner is a static method. The
  one thing a static method cannot have is injected services, so the
  build refuses an instance method that does not declare `runtime:
  TRUE`, naming it and both fixes. A static method may still declare
  runtime, since it can reach the global container. This moved the
  alters' refiners that only translated to static methods calling
  `t()` and constructing `PluralTranslatableMarkup`; an alter's
  injected service, the honest way to read state, is now only reachable
  from a runtime refiner. (`Binding::assertStaticUnlessRuntime()`)
- **Observational, under `assert()`.** In development and tests a
  static refiner's call runs with the global container replaced by a
  wrapper that throws when asked for any service or parameter but the
  typed data manager, which core's definitions ask for to read their
  default constraints. `t()` passes: it builds a lazy markup. Production
  compiles no assertion and pays nothing. It catches `\Drupal::service()`
  and every shortcut through the container, `::currentUser()` and
  `::time()` included. (`Pipeline\PipelineGuard`,
  `RefinesInputRefiner::invoke()`)
- **Agreement.** Under `assert()` the emitter asks each enumerated key's
  static refiners again for one combination and refuses a different
  answer, which catches a counter or a clock that reads no service; and
  a kernel test trait holds every conditional of a document to a fresh
  `refine()` for its values, run over the examples and the fixtures.
  (`Conditionals::answersAgain()`,
  `Tests\surface\Traits\StaticRefinerAgreementTrait`)

None of them proves purity: running a refiner twice proves nothing about
tomorrow, and a static method can still read a static property something
else filled from storage. A PHPStan rule adds reach to refiners no test
exercises (decided 2026-10-19, replacing "left for later"): it flags a
static refiner whose body calls `\Drupal::`, the one shortcut every
service and site state read goes through. It is registered by a PHP
include (`phpstan-rules.php`), because PHPStan compiles its container
before the bootstrap file teaches it where the rule's class lives, and
it does not try static state, which a rule cannot tell from a constant
without false alarms. The broken fixtures, which exist to be refused
when they run, are ignored by its identifier.
(`Tests\surface\PHPStan\StaticRefinerRule`, `StaticRefinerRuleTest`,
`RefinesInput`, `Binding::bindRefiners()`, `RuntimeRefiner`,
`ContractEmitter::frame()`, `Conditionals::enumerate()`)

### A definition is validated as itself

Refinement makes a new definition for every set of values, and nothing
it hands out is shared: two builds, or two refinements of one, never
hold the same object. Typed data shared them anyway. Core builds a map's
properties and a list's items from a prototype it keeps for the rest of
the process, keyed by the root's type and settings and the property's
path, and never by the definition it is asked about, so the first
definition validated at a path judged every later one. The compliance
alter's stewards, a property of the mount, were held to the first
call's minimum by every later call: after a capacity of 80 a capacity
of 50 was refused, and after 50 a capacity of 80 was written with one
steward. A top-level key never showed it, because the root is made from
the definition handed in; nor did a label or a description, which only
the leaked definition carried and no message reads. It reached across
resources too: two classes with a map key of one name shared its
properties' rules.

Every place the module turns a definition into typed data, the
pipeline's validation of inputs, collections and outputs, and the
watched value check, goes through `DefinitionTypedData::create()`,
which hands core a copy of the root carrying a digest of what the
definition says that validation reads, its type, class, required,
settings and constraints at every depth, among its settings (a list's
goes on its item, which is what core keys a list by). Labels and
descriptions are left out of the digest on purpose: no violation reads
them, and a refiner writes the values it was handed into a description,
which would give core one prototype per distinct value. Definitions
that validate alike share prototypes and ones that do not never do; the
definition handed in is not touched.
Clearing core's cache before each validation, as the Tool API does,
would work too, but reaches into a protected property and throws away
every other caller's prototypes. The cost is that a long process keeps
one set of prototypes per distinct set of rules it validated, which is
bounded by what the refiners can narrow to, not by the values they
were handed. *Changed after the compliance
example's stewards were found leaking between tool calls in one
process; see `docs/core-gaps.md`.* (`DefinitionTypedData`,
`SurfacePipeline`, `Refinement\WatchedValueCheck`)

### A key that does not apply

A refiner says a key does not apply to the values it watches by
returning `DoesNotApply::here()`, from a method whose return type names
it: `: DataDefinitionInterface|DoesNotApply`. It is the narrowest answer
there is, so it is a refiner's answer like any other, not a second
concept: gated, enumerated, held to the narrowing check (nothing is
narrower, and a key ruled out may not be made to apply again), and
static or runtime by the same declaration and the same three layers of
enforcement. The engine marks the definition it was handed and calls
nothing after it. A key, a key an alter mounted (inside its mount), an
attached child's key and a key with variants are ruled out the same
way; a parent's refiner of a key holding a child may give this answer
and no other, since what the child holds is the child's to narrow.

Three spellings were weighed. A marker set on the definition the refiner
returns is what the engine does inside, but as the author's spelling it
is invisible until the refiner runs, and the full schema and the tool's
listing must know before anything runs which keys may not apply, or a
`refine` or `live` key required in them would be stricter than the
pipeline. A blueprint declaration, `add(..., appliesWhen:)`, is
declarative narrowing ([ROADMAP](https://github.com/mlander/surface/blob/main/ROADMAP.md) item 3), which cannot
state a runtime rule; it has since landed as a refiner too, whose
`doesNotApply()` gives this same answer ([A rule declared as
data](#a-rule-declared-as-data)). The
sentinel in the return type is both an answer and a declaration: the
signature already declares what a refiner watches, and now whether it
may rule its key out, so the build reads it by reflection (a method that
answers it without declaring it is refused) and the enumeration states
it as it states any static answer. Each door:

- **Form.** No element at all, not disabled and not hidden; only its
  wrapper, for the AJAX rebuild that makes it apply. A key drawn as
  nothing that a full submission makes apply, a sibling changed in the
  same post, was never sent, so it is read as left out and keeps what is
  stored, inside a child as at the top: the container posts the paths it
  drew as nothing (`@absent`), and each AJAX rebuild replaces that list.
- **Values.** Read as left out: left out, `null`, the empty string or
  exactly what it holds is accepted, and the accepted document leaves
  the key out; any other value is refused, "Send day does not apply when
  Frequency is Monthly.", naming what it depends on as
  [a refusal names what it depends on](#a-refusal-names-what-it-depends-on)
  does. Refused rather than ignored, as an unknown key is refused,
  because a caller who sends a value that means nothing learns so
  instead of watching it vanish, and because both documents refuse it.
  `null` is accepted and does not clear: a key that does not apply has
  nothing to clear, and `null` and the empty string are notations of
  nothing, as a form's `"50"` is a notation of a number. What it holds
  already is accepted because a full submission, a plugin loading its
  configuration, or a client sending back what it read, carries it
  unchanged, and the stale rule draws the same line: what is stored was
  not chosen by the caller. Nothing about the key is required or
  validated.
- **Storage.** It keeps what it holds. Monthly, then weekly again, the
  send day survives: no value is silently lost, the rule Phase B item 10
  set against pruning. `submit()` hands storage the stored value back
  (`SurfacePipelineInterface::kept()`), so a storage that writes its
  whole object keeps it too, and a plugin host loading its
  configuration keeps it. The cost is a value in storage nobody can see
  while the key does not apply: the read tool and the contract's
  `values` leave it out.
- **The read tool and the schema for these answers.** The key is not in
  `properties`, `required` or `values`. The frame says
  `x-surface.doesNotApply: {key: [what it depends on]}`, so a client
  with no full schema still refines when one of those changes.
- **The full schema.** Under each conditional whose values rule it out,
  `then: {properties: {key: false}}`; the key leaves the frame's
  `required`, and each conditional where it applies and is required says
  `then: {required: [key]}` (with `properties: {key: {...}}` beside it
  when it narrows too). This is a conditional `required`, which closes
  the fresh-eyes log's entry 19: a refiner that makes an optional key
  required for some values is stated the same way. `false` was chosen
  over `type: null`: the schema for these answers leaves the key out
  under `additionalProperties: false`, which refuses it present, and
  `false` says the same, so the two documents say one thing; `type:
  null` would admit a `null` the accepted document never holds, and read
  as a key whose value is `null`, which clears. A `refine` or `live`
  key whose refiner may rule it out is in no `required`, since nothing
  says where it applies. The tool's listing makes it optional for the
  same reason, and the pipeline asks for it where it applies.
- **React.** The page narrows it from the full schema: a met `false`
  drops the field and what it sends, a met `required` requires it again,
  and a key that did not apply when the page was loaded, whose stored
  value the page was never handed, is asked of the server (`POST .../schema`).

A list narrowed to nothing is not a key that does not apply. It renders
as a select offering its empty option alone, the constraint refuses any
value, and both documents state `oneOf: [{const: null}]`, which a
required key's type refuses too. Refusing it at build was weighed and
dropped: a refiner's answer exists only for values, at refinement, and an
options resolver may find nothing on a site (no bundles yet), so a
refusal would break a page for site state. The text field it fell
through to before showed what was stored as something to edit, on a
field that could accept nothing. An empty `Choice` is now such a list
too, which is what variants nothing declares give their deciding key.

A key with variants that does not apply is drawn as nothing, as one
whose deciding key chose nothing is; its variants' conditionals are not
written in the schema for these answers, and the full schema states it
`false` beside them. An empty variant is a different answer: the key
applies and has nothing to say, its value an empty map.

Inside a collection's item a key that does not apply is not put back
from storage, by design (decided 2026-10-19): a list is sent whole and
each item is accepted over the child's defaults, never over what is
stored at its delta, since an item may have moved and a delta names no
stored item ([Empty items are dropped](#empty-items-are-dropped)). What
a list holds is kept when the list is left out, as "absent keeps" says
of any key; once it is sent, the list written is the list sent.

A map whose every key is ruled out is an empty map, and is written as
one: `SurfacePipelineInterface::document()` writes accepted values
as their JSON document, every map an object (`{}`, an empty one
included) and every list a list, as the emitter writes its own `values`,
and the React `/validate` answers with it (decided 2026-10-19; a JSON
encoder writes an empty PHP array as `[]`, which `type: object`
refuses). The emitter's `properties` for such a map is `{}` too. The map
each module's mounted keys sit in, `third_party_settings`, is never
null: with nothing stored and no mounted key holding a default it is
accepted as an empty map, not `null`, which a config object's schema
refuses (decided 2026-10-19; until then the parcel fixture carried a
`courier` with a default so its mount was never empty).
(`Refinement\DoesNotApply`, `RefinesInputRefiner::invoke()`,
`Surface::refineAgainst()`, `SurfacePipeline::accept()`,
`SurfacePipeline::document()`, `Conditionals::enumerate()`,
`narrow()` in `app/src/contract.ts`, `AdvertisedSchemaAcceptanceTest`)

### A rule declared as data

A rule between keys whose condition and effect a schema can state is
declared, not coded: the blueprint's `narrow()` takes the key, `when()`
a `Refinement\Condition`, and its effects.

```php
$inputs->narrow('capacity')
  ->when(Condition::absent('license'))
  ->constrain('Range', ['max' => 100])
  ->because($this->t('Up to 100 without an event license.'));
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

The guidance: declare the rule when its condition and effect fit the
vocabulary; write a refiner when the effect must be computed (the
stewards for a capacity, `ceil($capacity / 50)`, stays coded) or the
condition is runtime. Each choice, and why:

- **One concept: a declared rule is a refiner, static by construction.**
  One contributor's rules are one link, `Refinement\DeclaredRefiner`, in
  the owner's chain of each key they narrow, and the keys a condition
  reads are refinement edges, so they are `dependsOn`, rebuild a form
  over AJAX, are held to the cycle check and to "a refiner never sees an
  invalid sibling", and name themselves in a refusal, as a coded
  refiner's do. Every answer is held to the narrowing check. A second
  concept beside refiners would need its own gate, its own enumeration
  and its own agreement check, and the two would drift.
- **A rule waits for nothing.** A coded refiner runs once every key it
  watches holds a value; a rule runs whatever its keys hold, and is
  handed them as they stand, because `absent` is about a key holding
  nothing, and because the `if` a schema states is read on any document.
  A key only a rule reads gates nothing, so a rule on the capacity that
  reads the license never holds the room's ceiling back
  (`Entry::gate()`, `Entry::declaredDependencies()`).
- **A closed condition vocabulary, each word one exact `if`.** `present`,
  `absent`, `equals`, `in`, `atLeast`, `atMost`, `above`, `below`,
  `not`, `allOf`, `anyOf`; no callback, no expression, no string
  function, since each would be a rule only PHP can read. Exact means
  exact on the accepted document, which carries every key, `null` for
  one holding nothing, and leaves out a key that does not apply: so
  `present` is "there and not `null`", `absent` is `{type: null}`, which a
  key left out satisfies too, and a bound carries `type: number`, which
  `null` does not satisfy (JSON Schema reads `minimum` on `null` as
  met). A value compared with a key is of the key's type, refused at
  build otherwise, so `const` reads the document as the pipeline reads
  the values. One class with static constructors, because it reads as a
  sentence in a blueprint call; eleven classes would be eleven `new`s.
- **A closed effect vocabulary that only narrows.** `constrain()` a
  `Range`, `Length` or `Count` bound, a subset of the key's own list
  (`Choice` or `LabeledChoice`), a `Regex` inside the subset both
  engines read alike and only where the key has none; `require()`;
  `doesNotApply()`, the same answer as `DoesNotApply::here()`, beside no
  other effect, since nothing more is said of a key that does not apply;
  and `describe()`, because a person filling the form is owed the reason
  as much as a validator is owed the bound, and a description is an
  annotation a `then` may carry. A rule's description is said only where
  its effect tightened the key for these values (a `require()`, a
  `doesNotApply()` and a description alone always do), so a room already
  under the rule's bound keeps its own words; the full schema's `then`
  keeps it, an annotation the schema cannot place. A bound tightens what the key allows by
  the time the rule runs (the lower maximum, the higher minimum, the
  values both lists offer), so the pipeline's answer is what a validator
  applying both conditionals reads. A rule that would widen what the key
  declares (a maximum above its own, a value its list does not offer, a
  second pattern) is refused at build, judged by the narrowing check as
  if written over the key. **Locked is left out**: a key's lock is the
  context's, settled at build on its entry, and a lock that depended on
  values would change at refinement what accept() takes from storage and
  what both documents say is `readOnly`; nothing cheap carries it.
- **Each contributor's coded refiners, then its rules.** The owner's
  before an alter's, as for coded refiners; within one contributor, its
  rules after its methods, in declaration order. A coded refiner writes
  a bound as it likes and would be refused for widening a tighter one
  laid before it; a rule only tightens, so after the methods it cannot
  widen, and the result is the schema's `allOf`. The description a rule
  writes last is the one shown, as its conditional comes after the
  enumerated ones in the schema.
- **Where it is spelled: the blueprint, one spelling.** A rule is part of
  what a key is, so it is said where keys are said: the owner in
  `defineInputs()`, an alter in `alterInputs()` through
  `BlueprintAdditionsInterface`, on the owner's keys and its own mounted
  ones, read by the owner's keys and its own, under the rule that an
  alter narrows and never widens. An attribute was weighed and rejected:
  it needs a method to sit on and a rule has no code; attribute
  arguments take no static call, so conditions would be nested `new`s
  of public constructors and descriptions `new TranslatableMarkup`; and
  two spellings of one rule would be documented twice. (Operations
  weighed the same worry and decided the other way, 2026-10-20: an
  operation on the class carries no code to keep in step, so the class
  form is the default and the method the exception, [An operation on
  the class](#an-operation-on-the-class).)
- **Stated as written.** The full schema and every def state each rule
  as one conditional, its `then` read the way an enumerated one is (only
  what changed, `false` where it does not apply, `required` where only
  there), after the key's enumerated conditionals, its index listed in
  the owning object's `x-surface.rules` (since [contract 2](#contract-2);
  before, `x-surface.declared` sat beside its `if`); the key is no
  longer `refine` for a rule, and carries no marker of its own
  (`x-surface.declared: true` until
  [contract 4](#contract-4-fewer-keywords-nothing-new-on-the-wire)).
  Only the coded refiners are enumerated
  there. The schema for these answers evaluates the rules for its values,
  as a coded refiner whose gate is open is applied, and writes none.
  A key a rule may rule out is in no `required`, and a conditional
  requires it wherever no rule rules it out; a conditional requiring it,
  a rule's or a coded refiner's, holds only where none does, so no
  document is held to both `false` and `required`.
- **Refused where no schema could be exact.** A rule that requires a key
  a coded or runtime refiner may rule out (where the coded one rules it
  out is not stated where it cannot be listed, nor ever the runtime
  one's); a rule on a key holding a child other than ruling it out, and
  on a collection other than by its Count; a condition on the key
  itself, a child, a collection or another module's mounted key; an
  alter's rule on a key it offered more values on, whose rules would run
  on its slice of the list alone (not built; its refiner says it).
- **`unless()`, and no double negation in the schema** (2026-10-20,
  from the DX reviews). `->unless($c)` is `->when(Condition::not($c))`,
  under the same one-condition check: the recipe every toggle copies
  ("the door code does not apply unless after-hours access is on") read
  as two negations. `unless` is a word of the rule, not of the closed
  condition vocabulary, and states the same `if`. Where the conditional
  requiring a key wherever no rule rules it out would wrap a negated
  condition in `not`, the emitter writes the condition itself: the
  documents mean what they meant, and read as the rule does.
- **A bound's rule takes a message** (2026-10-20, from the DX reviews).
  `constrain('Range' | 'Length' | 'Count', [..., 'message' => t(...)])`,
  one option as `Regex` takes it, written under Symfony's own option for
  each bound the rule sets (`minMessage`, `maxMessage`), or the one a
  range holding both bounds reports in (`notInRangeMessage`, beside
  which Symfony refuses the other two), and a length's or count's
  `exactMessage` for both bounds at one value; and
  only where the rule's bound stands: a bound the key already held at or
  inside the rule's keeps the key's message, as it keeps the key's
  description. One spelling rather than Symfony's three, because an
  author writes one sentence for one condition; the cost is Symfony's
  own, that a range holding both bounds says one message for either
  side, so a rule's message on a range is worded for the range, its
  numbers written in. The message is handed to the validator as its
  source string, as a declared constraint's is. It reaches the form and
  the tool as the refusal, the condition after it; neither
  document carries it, as neither carries a key's own bound message:
  `maximum` says itself, where a pattern needs `patternMessage` to be
  read by a person. A message with no bound is refused.
- **Held to the pipeline.** `StaticRefinerAgreementTrait` samples the
  values each `if` names, either side of each bound, nothing, and a value
  the key would hold, and for every document the pipeline could accept
  requires a fresh refinement with the rules alone to answer the key
  with the `then` of each conditional a standard validator finds met,
  tightened over the declared key. The React page reads an `if` by JSON
  Schema's own rules (`holdsIf()`) and tightens by each `then`; it asks
  `/refine` for a rule reaching inside a map, and while a key's coded
  refiners have nothing to refine against.

The first case is example 4's capacity, which was `dynamic` because its
coded rule watched free text. The rule's text is now one sentence: "With
one, up to @max" read the room's ceiling off another refiner's answer,
which is an effect computed, so it stayed with the code it left. In a
room seating fewer than a hundred the room's ceiling is the lower one,
and its own sentence stands: the rule tightened nothing. Its stewards stay
coded and `refine` (they watch a number), so the React page still asks
on a license change: the stewards depend on the capacity. Phase C item 9
of the roadmap (dependent effects as data) is this, but `locked`.
(`Refinement\Condition`, `Refinement\Rule`, `Refinement\DeclaredRefiner`,
`Binding::bindRules()`, `Surface::refinedEntry()`,
`Conditionals::rules()`, `holdsIf()` in `app/src/contract.ts`)

## Alters

### Where an alter's keys live

An alter's key is mounted at `third_party_settings.<module>.<key>`, not
beside the owner's. (An alter's outputs were mounted at
`third_party_outputs.<module>.<key>` until 2026-10-24, when alters
stopped adding outputs: what a call answers is its operation's, and a
reaction to a save belongs to ECA; [The surface was counted and cut](#the-surface-was-counted-and-cut).) The owner's storage and schema then never have to know a
contributor's keys, which is how core keeps third-party settings.
(`ResourceBlueprintAdditions`)

### An alter attaches at its own mount

An alter may `attach()` a structure at its own mount,
`third_party_settings.<module>`, and nowhere else: the mount's keys are
then the structure's, stored, posted and described by config schema
under the module's name exactly as keys the alter added would be, and
the alter adds no key of its own beside them. It still cannot attach at
the owner's level, where the structure's keys would be the owner's, and
`attachList()` on an alter is still refused, as is a structure at a key
inside the mount: neither is built. The structure is built in its own
frame, as any child is, and its answer for the values at the mount is
written there (`Entry::$mounted`), so the form, the pipeline, storage
and the tool bridge read the mount as the plain map they always read.
That is what lets an alter be an adapter
([ports](#a-port-is-a-live-sibling-value)) with no
change to the owner's storage or to anything that reads it. The
alter's glue may read the structure's keys by their names, as it reads
any key it mounted, and may not narrow them: what they allow is the
structure's. A resource or a structure with children of its own, or
whose access answers locked or omitted (its access class, or an alter
that answers for it), is refused at a mount; each is open. *Changed for ports: until then `attach()` and `attachList()` on
an alter were both refused, an attached structure inside the mount not
being built.*
(`ResourceBlueprintAdditions::attach()`, `ResourceBlueprintAdditions::attachList()`,
`Attaching::mountStructure()`, `Surface::refineMounted()`)

### An alter refines its own mounted key

A `#[RefinesInput]` method on an alter may name a key that alter added,
by the name it added it under; it is bound to the mount,
`third_party_settings`, and refines only its own module's property
inside it, so the method reads as it would on an owner's key and the
engine's narrowing check applies to the mount unchanged. Its edge is
recorded by the key's dotted path, `third_party_settings.<module>.<key>`,
so the form rebuilds and the discard cascade drops that one key rather
than the whole mount; the mount itself is gated on the union of what
its keys watch. *Changed for example 4 of the examples, which needed a
key the alter adds to tighten with the owner's capacity; until then
such a method was refused.* (`Binding::bindRefiners()`,
`RefinesInputRefiner::refineMounted()`, `DefinitionMap::refinementPaths()`)

### An alter watches its own mounted key

An alter's method may watch the keys that alter mounted, named as the
alter added them, whether it refines the owner's key or one of its own.
The watch is resolved inside the alter's own namespace and recorded by
the dotted path, so the engine's edge, the form's AJAX trigger, the
discard cascade and the served contract's `dependsOn` all name
`third_party_settings.<module>.<key>`, and the method is handed the
value read at that path. Watching another module's mounted key stays
refused, as a child's watching its parent does: what another module
mounted is that module's. A mounted watch never gates: the value is
handed as it stands, NULL while unanswered, and the parameter receiving
it has to accept NULL (refused at build otherwise). Gating is per key,
so an unanswered optional key of the alter's would otherwise suspend
the owner's own refiners of the same key, and the alter would have
widened what the owner narrowed: the compliance alter's license, empty,
would have lifted the room's limit along with its own hundred. The
cycle check follows each path's own edges, so a capacity watching the
license while the stewards beside it watch the capacity is no cycle.
*Changed for example 4 of the examples; until then watching any mounted
key was refused, pending dotted refinement paths, `ROADMAP.md` item 16,
of which this is the one case the third-party mount needs.*
(`Binding::assertNotWatchingForeignMount()`, `Binding::mountedWatches()`,
`Surface::dependencyValues()`, `SurfaceBuilder::edgesOf()`)

### A storage layout for a mount

An alter whose keys are asked for in one layout and stored in another
implements `AltersStorageLayoutInterface`; its layout applies to its own
module's mount and nothing else. The alter does not own the storage, so
it hands the translation to the data surface, and the composed storage
applies it. (`Resources::buildResource()`, `StorageAdapter`)

### Required inside a plain map

A required property inside a plain map (a key that is neither an
attached structure nor variants, such as the mount alters' keys live in)
that holds no value is refused with the pipeline's own "is required"

message, filed at its path, whether or not the map itself holds
anything; typed data's NotNull for the same path is dropped so it is
said once. No child judges a plain map's properties, and NotNull alone
passes an empty string and never sees a map that is absent, so a
mounted key an alter made required would otherwise never be refused.
(`Validating::missingInMap()`)

### Placement is a describe option

Where an alter's fieldset is drawn is said once, by the alter, on its
own mount: `describe('third_party_settings.<module>', after: '<key>')`,
naming a top-level key of the owner's blueprint. It is recorded on the
mount's definition (`DefinitionMetadata::setPlacedAfter()`), so every
door reads the same declaration: the generated form draws the details
there, the served contract says `x-surface.after` and the React app
draws it there. A form alter would have placed it on one form and
nowhere else. The value path does not move: the keys are stored,
posted, validated, advertised to the Tool API and described by the
config schema at `third_party_settings.<module>.<key>` as before, and
the contract's properties keep their nesting and order, because the
mount is what lets the owner's storage hold a contribution without
knowing it ([Where an alter's keys live](#where-an-alters-keys-live)).
The form moves the details at render time (`#pre_render`), after Form
API is done with the array, so no walk by path changes. Only the
calling alter's own input mount may be placed; an output mount is never
drawn, and `after:` on it is refused rather than ignored, so a mistake
shows. A key the owner put in an [arrangement
group](#arrangement-is-declared-once) is followed into it: the details
are drawn in the group, right after the key. Decided 2026-10-21, from
the fourth fresh-eyes log (entry 28): the form looked for the key in
the frame, where a gathered group no longer held it, and drew the
details last, while the React app, which draws a placed fieldset as
part of the key it follows, drew them in the group. Honoring the
placement was chosen over refusing it at build: the owner groups and
the alter places, neither knowing the other, so a refusal would make an
owner's arrangement break another module's alter.
(`BlueprintAdapterBase::assertPlaceable()`,
`Form\Drawing::placeMounts()`, `Form\Drawing::holding()`)

### Adding a key twice

Declaring a key that is already declared is refused, for owner and
alter alike, and `add()` with a NULL default means "no default", not a
declared NULL. A second `add()` silently replacing the first would be
an alter changing the owner's key. (`BlueprintAdapterBase::add()`)

### An extension is a variant the site adds, or an alter

Decided 2026-10-23, with example 14. A module that calls a service takes
a credential pasted as a secret, and, once a module that manages keys
is on (the Key module), offers a key the site manages in its place and
draws no secret beside it. There are two ways to build it, and the
examples ship both, each for the case it fits.

- **The owner integrates: a variant the site adds.** When the module
  that needs the credential knows the key module, its resource declares
  both sources, each a structure of its own, and a provider lists them:
  `attachVariants('credential', by: 'source', from:
  CredentialSources::class)`, the pasted source always and the managed
  one only while the key module is on, cacheable by `config:core.extension`
  and the keys' configuration. Installing the key module is a second
  source at the next request, with no cache rebuild, in the form, both
  documents and the tool. The owner's code reads its own variant,
  `credential.api_key` or `credential.key_id`, and asks the key service
  for the value of the second.
- **A closed provider: storage knows both.** The two sources are
  structures the owner's code names, so the provider implements
  `ClosedSiteListInterface` (`ClosedVariantsInterface` until 2026-10-24)
  and names them in a static `structures()`, the only place each is
  named: its list names a value and a label.
  The config schema written from the resource then gives each its own
  type, `credential.[%parent.source]`, as it does for `#[Variant]`
  classes, whichever the site offers when it is written; a provider
  whose variants are read from schemas leaves the key `type: ignore`.
  The build refuses a closed provider's list that offers a value
  `structures()` does not name, or names a schema or a structure of its
  own. Two spellings
  were turned down. `#[Variant]` classes are always offered, and the
  managed source must be offered only while the key module is on. A
  variant's access answer could withhold it, but which sources a site
  has is the site's, not the account's or the thing's, and that answer
  would mark the key `access: live` in the full schema.
- **One value to choose is a fixed value.** With the key module off the
  provider lists one source. A deciding key whose list its variants
  derive and that offers one value, which it may not be cleared of, is
  drawn as that value while it holds it or nothing: the form draws an
  item showing the value's label, posts the value, and wires no AJAX
  trigger on it; both documents state `const`, with the label under
  `x-surface.fixed`, and the widget hint `fixed`, which the React page
  draws as the label. No select of one: it offers no choice, and a
  select a person opens to find one option reads as a fault. A list the
  deciding key declares is the author's and is drawn as declared, so a
  nested component's `component` key narrowed to one stays a select. A
  stored value the list no longer offers (`managed` after the key module
  is uninstalled) is drawn as a select under the empty option rule, so a
  save that leaves it keeps it. (`DefinitionMetadata::setOneVariant()`,
  `OptionsWidget::drawsFixed()`, `PropertySchema::property()`)
- **A secret survives a switch of variants.** A value written for
  another variant goes when the deciding key moves on
  ([An empty variant is an empty map](#an-empty-variant-is-an-empty-map)).
  A secret is never read back, so a caller who chose the managed key
  could not send the pasted one again when choosing back, and the key
  would be lost by a choice the caller made to try something. So a
  secret another variant declares, stored and not empty, is kept beside
  the chosen variant's keys, read by no door, and taken up again when
  its variant is chosen; each variant's config schema says it may be
  there (`requiredKey: false`). A plain value of a variant not chosen
  still goes: it can be read and sent again. (`Variants::keptSecrets()`,
  `Variants::held()`, `SurfacePipeline::kept()`,
  `ConfigSchemaGenerator::keptSecrets()`)
- **On the form, a switch is read off what was drawn.** Whether posted
  values fit a variant cannot be told by their keys alone: the card's
  `link` map and a teaser's `link` string share a name. So each variant
  key's element says the variant it was drawn for
  (`SurfaceFormBuilderInterface::DRAWN_FOR_KEY`, a render key, at
  every depth). When the deciding key now chooses another, extraction
  reads nothing from the element, and the rebuild draws the chosen
  variant as a reload would: from what is stored when what is stored was
  written for it, from its defaults otherwise. A render key and not a
  posted field: the AJAX request is processed against the form as drawn,
  which carries it, and nothing a browser sends can change it.
  (`Drawing::drawnFor()`, `Extracting::switchedVariants()`,
  `SurfaceHostTrait::surfaceFormValues()`, `SpotlightFormTest`)
- **What is stored is the variant the stored deciding key chose**
  (*amended 2026-10-27*). The same names say as little of a stored value
  as of a posted one: a tool call sending the teaser alone against a
  stored card read the card's props as the teaser's, and refused its
  `link` map as the teaser's text. A stored variant's values are held
  for a choice only when the deciding key's stored value is that choice;
  otherwise the chosen variant starts from its defaults, and from the
  secrets of its own kept beside them, at every door
  (`Variants::heldFor()`, read by `Accepting::acceptKey()`,
  `SurfacePipeline::kept()` and the form's `Extracting::settle()`;
  `SpotlightFormTest::testNewComponentSentAloneStartsFromItsDefaults()`).
- **The owner does not integrate: an alter.** The webhook declares a
  pasted key and nothing else. The key module's alter mounts `key_id` at
  its own mount, offering the keys the site manages through a constraint
  and its resolver, and declares
  `narrow('api_key')->when(Condition::present('key_id'))->doesNotApply()`:
  once a key is chosen the form draws no pasted box, a pasted key sent is
  refused naming `key_id`, the full schema states the rule as a
  conditional, and what is stored for `api_key` is kept for the day no
  key is chosen. Uninstalled, the key module takes the `key_id` it
  stored on the webhook with it (`hook_uninstall()`), since no config
  schema describes it then and a save with schema checking on would
  refuse the object; the pasted key is the webhook's and stays. Ruling
  out was chosen over a lock. A lock would draw an
  empty password box that cannot be used, and would take a pasted key
  sent with a note instead of a refusal, so a caller who sends both
  learns of it only from the note; the rule vocabulary has no
  conditional lock either (ROADMAP item 3 left it out). The page quotes
  the spelling a lock would have.
- **What the code that uses it reads.** The webhook's code reads
  `api_key`; `key_id` is under the key module's mount, which the webhook
  never names
  ([An alter attaches at its own mount](#an-alter-attaches-at-its-own-mount)).
  *Amended 2026-10-27:* the alter also resolves `api_key` from `key_id`
  at use, so the code that signs a post, reading through
  `Resources::resolved()`, gets the managed key's value
  ([A key may stand for another at use](#a-key-may-stand-for-another-at-use)).
  Until then the alter's way corrected the form, the documents and the
  tool and left that code with no key.

Open: whether a plain value of a variant not chosen is kept too (the
rule against pruning stored values says keep it; a value left by
another variant has gone since variants were built); and a stored
source the site no longer offers, which is kept and shown stale, while
the credential it chose is not built until the source is offered again.
(`ClosedSiteListInterface`, `CredentialSources`, `WebhookKeyAlter`)

### A key may stand for another at use

Decided 2026-10-27, with example 14's webhook. An alter that offers a
managed key in place of an owner's pasted one rules the pasted key out
once a managed key is chosen, and the owner's code still reads `api_key`.
The alter now also says what that code reads there:
`$inputs->resolve('api_key', from: ['key_id'], using: ManagedKeyValue::class)`.
`Resources::resolved()` builds the operation, loads what storage holds,
and puts at `api_key` what the class answers for the stored `key_id`,
while `key_id` holds a value; the owner's code calls it and names no
managed key.

- **Why.** The Key module asks with `key_select` and answers with
  `getKeyValue()`, one declaration for both. Here the alter owns both
  halves of its swap, the rule for what is asked and the resolve for
  what is answered, so the consumer is unchanged. Nothing is copied into
  the owner's storage: the managed key's value is read at each call and
  never written back, and the pasted key stays stored as it was.
  Uninstall the key module and its mount goes with it, so `resolved()`
  answers the pasted key again.
- **Not in the contract.** A resolve is a fact about use, not about what
  is asked, so neither document, the tools, the form nor the read states
  it. What is asked does not change, and every door still leaves the
  secret out. `resolved()` is PHP's alone and answers secrets.
- **When it is skipped.** While a key in `from:` holds nothing (NULL, the
  empty string or no value) the stored value stands. A name the class
  knows no value for, a key the site stopped managing, resolves to NULL:
  the pasted key does not stand in, since it was ruled out, and the
  signer refuses to sign with no key.
- **One resolve per key.** A second resolve of a key, by the owner or
  another alter, is refused at build naming both. Two cannot compose:
  while both sources held a value there would be no right answer, and
  each alter rules out the owner's key alone, never the other alter's.
  Core's form alters overwrite one another silently, by weight; the Key
  module avoids that by being the one abstraction, so two modules that
  both stand in for one credential would layer at the key, and the
  refusal says so.
- **The class.** `using:` names a `ComputesValueInterface` class, as a
  computed key's does, handed the stored value of each key in `from:`
  by the name the alter gave it. It runs at use and never ahead, so it
  may read the store the value lives in (`ManagedKeyValue` asks the
  keys' service); it never reads the request or the account.

Two alternatives were turned down. A new output carrying the value would
move the consumer onto a name the owner never asked for, and outputs are
never refined or altered. Deriving the rule that rules the pasted key out
from the resolve would join two facts, what is asked and what is
answered, into one call; they stay two lines.

*Amended 2026-10-27:*

- **A resolve inside a structure is walked.** A structure declares a
  resolve among its own keys, and its alters among theirs, by paths of
  its own. `Resources::resolved()` applies it wherever the structure is
  held: under the key it is attached at, in each item of a collection,
  in each entry of a map of keys from the site, in the variant its
  deciding key chose, and in the owner's frame where it is mixed in,
  whose keys its members are (a mixin resolves among the members it
  brought). Still refused: a resolve in an alter of a mixed-in
  structure, and in a shape, which has no keys. A plugin's own
  structure, built by its host, has no `resolved()` door: what it
  declares applies only where a resource holds it.
- **Two alters setting one fact on a key are refused.** The owner's
  values are what alters lay over. The first alter to set a fact on a
  key it does not own (label, description, group, weight, placeholder,
  prefix, suffix, help) sets it; a second alter setting the same fact on
  the same key is refused at build, naming the key, the fact and both
  modules, as a second resolve is. Two alters setting different facts on
  one key compose, and an alter wording its own mount, or one fact
  twice, is no collision. Why: last-wins by weight is how core's form
  alters overwrite one another silently; here the order alters run in
  would decide what every door shows.
  (`ResourceBlueprintAdditions::claimFacts()`, `AlterWordingTest`)

*Open:* a resolve whose class needs the account. (`Resolve`,
`BlueprintAdditionsInterface::resolve()`, `Resources::resolved()`,
`ManagedKeyValue`, `WebhookSigner`, `ResolvedKeysTest`,
`ExamplesExtensionTest`)

## Attached structures

### Variants nothing declares

A key with no variant declared stays a placeholder and its deciding
key gains an empty `Choice`, so nothing can be chosen. Refusing the
whole parent would break it on every site without a variant module.
(`Attaching::variantKeysOf()`)

### Derived variants

A value no `#[Variant]` class fills may get a variant from a description
that already exists: a field type's config schema fills the field's
settings variants and the storage's. A declared variant always wins.

*Changed 2026-10-24, by the owner:* these came through a second
mechanism, a `DerivedVariantsInterface` service tagged
`surface.derived_variants` that named the owner's class and key
from outside and sealed plain definitions into a child, counted by no
budget. They come through the one provider now: `FieldInstanceResource`
attaches its `settings` with `attachVariants('settings', by:
'field_type', from: FieldSettingsSchemaVariants::class)`, and
`FieldStorageResource` from `FieldStorageSettingsSchemaVariants`, each a
`SiteListInterface` listing one entry per field type offered in the UI,
labeled with the field type's label, its schema the config schema
written as JSON Schema (`ConfigSchemaDocument`), with the field type's
own default settings where their type fits and are not the empty
string. Each variant is a read structure, built as any read from a
schema is, a key its storage always holds marked as such. The list
carries the field type plugin manager's cacheability and
`config:core.extension`, so a fetch of one variant past the limit
caches honestly. It is a list the site decides, so it counts against
`site_list_limit`: under it nothing moves but the deciding key's labels,
now the field types' own; past it the field tools state `settings` open
and `deferred`, with a fetch, and the narrowing tool answers it. The
kernel test site the catalog is written from offers 21 field types to
the field and 22 to the storage, so its `create`, `reuse` and `update`
field tools are deferred and each has a narrowing tool beside it
([catalog](catalog.md)); this module's development site offers 20 to
each, at the limit, so its field tools state every field type's
settings, as before. Why: one idea, a
list the site decides, had two mechanisms, one named from outside its
owner and outside the budget ([The surface was counted and cut](#the-surface-was-counted-and-cut)).
(`FieldSettingsSchemaVariants`, `Attaching::variantKeysOf()`)

### A parent watching a child

A parent's refiner that watches the whole key a child is attached at is
refused, as is one that refines it, unless all it may answer is that
the key [does not apply](#a-key-that-does-not-apply), which is the
parent's to say about a key it owns. The pattern says a parent cannot
refine into a
child and a child cannot read a parent's value; refusing the watch too
makes the wall hold both ways. (`Binding::assertWalled()`)

### A port is a live sibling value

A structure that needs a value its container holds declares a port, a
name of its own with a type, `$inputs->reads('capacity', 'integer',
required: TRUE)`; its `#[RefinesInput]` methods watch the port by that
name and its declared rules name it in a condition, as they would a
sibling. The container wires each port to one of its own keys when it
attaches the structure, a resource at a key of its own and an alter at
its mount: `attach('compliance', ComplianceStructure::class, reads:
['capacity' => 'seats'])`. The compliance structure of example 4 is the
first case. Each choice, and why:

- **Live, and read as a sibling is.** The child is handed what the
  wired key holds now, as the container admits it (a value its own key
  refuses is handed as nothing), and refines in its own frame: a port
  gates a coded refiner and is read as it stands by a rule, exactly as
  a sibling is. It is never a key of the child: nothing stores, draws
  or accepts it there, since the container does all three for its own
  key. The wall stays where it was. The child names nothing of its
  container, which names nothing inside it; what crosses is one value,
  named by the child and chosen by the container. A copy into the
  child's values would be a key the child stores, and a path into the
  parent would be the child naming its parent's key, which binds the
  reusable piece to one owner.
- **The wiring is the site's.** Which key a port reads is said where the
  structure is attached, because the structure is reused by containers
  whose keys it cannot know (a capacity, a number of seats, the guests).
  The build refuses an unwired required port, a wiring to a key the
  container does not declare, to a key of another type, to a key inside
  the child itself, a port the child does not read, and a cycle through
  the wiring, naming it. An optional port left unwired holds nothing. A
  variant and a collection's item are wired by nothing yet, so
  neither may require a port.
- **What depends on a port is stated at the site.** The def is the
  structure as declared, for no values, reused at every site with one
  id, and a port is no property of it: a conditional naming the port
  there would be read by a validator as a key that is always absent,
  stricter than the pipeline, and a def that knew the wired key would
  be one container's. So the def lists its ports (`x-surface.ports`), a
  key reading a port names it in its `dependsOn` and carries neither
  `enumerated` nor `refine`, and it is in no `required` where it may be
  ruled out. The container states the rest on its own `allOf`, an `if`
  on its key and a `then` reaching into the child at the site: coded
  refiners enumerated over the wired keys' lists (`refine` when they
  read a key of the child's too, whose value the container does not
  state), declared rules as written, the port read at the wired key.
  The site says which key each port reads (`x-surface.reads`), and its
  `dependsOn` names the container's keys: the child's dependencies at
  the parent's paths. The schema for these answers binds the ports to
  its values, so the site narrows the def by composition, as before.
- **An alter is an adapter.** An alter that attaches a reusable
  structure at its own mount ([revised](#an-alter-attaches-at-its-own-mount))
  wires the structure to one owner, and what stays its own is glue for
  that owner: rewording or placing the owner's keys, a declared rule or
  a coded refiner on an owner's key, `extendChoices()`. The glue may read
  the structure's keys at its mount, because a relation between an
  owner's key and the structure's answer (the compliance capacity
  without a license) is the one thing neither side can say alone, and
  reading changes nothing the def says. A resource's own refiners still
  do not read an attached child's keys: the wall is unchanged, and what
  an alter reads is its own mount, as it always could.
- **Identity is a port of a fixed kind.** What a parent hands a child
  through `withChild(known:)` is a value the child reads from its
  container too, settled when the parent is built: it locks the key the
  child declares for it, selects what its storage loads and fills its
  permission. A port is the live kind, a value the container's caller
  may still change. Both stay, each where it applies, and identity is not
  reimplemented as a port: a port locks nothing and is asked of no
  operation.

Open: a container opting out of the alters of a structure it attaches,
which apply wherever it is used; ports on a variant or a
collection's item, which nothing wires yet. (`Port`, `BlueprintInterface::reads()`,
`Attachment::bound()`, `Attaching::assertWiring()`, `Attaching::wirePorts()`,
`Surface::refine()`, `ContractEmitter::site()`, `ContractEmitter::mounts()`)

### A mixin is merged flat

Composition nested only (attach, behind the wall; an alter's mount under
its module's name) until a structure could be mixed in: its members
merged into the owner's own keys, `$inputs->mixIn(TimestampsStructure::class)`,
or with its ports wired, `$inputs->mixIn(OwnershipStructure::class, reads:
['audience' => 'visibility'])`. Nest for a thing of its own inside this
one (a contact, a ticket); mix in for shared members that are part of
this thing (timestamps, ownership, publishing flags). The test module's
journal is the first case. Each choice, and why:

- **Spelled on the blueprint, not the attribute.** The members land
  where the call stands, in order with the other keys, and declaration
  order is load-bearing (the form, the schema, the stored object); the
  wiring reads exactly as `attach(..., reads:)` does; and a derived
  resource, which has no attribute, can call it from its inputs
  callable. The attribute would have been visible at discovery, so the
  catalog could list it without filling a blueprint; it does not list
  mixins yet (open), and the contract names each one, as a reference.
- **An ordinary `#[Structure]`, no marker.** The same class is mixed in
  by one owner and attached by another (the journal mixes the
  timestamps in, the ledger attaches them), and which composition fits
  is the owner's choice about its own keys, not a property of the
  structure. The one thing that differs by use is whether the def is
  closed, which the emitter reads off the document (below).
- **Declared by the owner only.** An alter's keys live under its own
  module's name, so a structure an alter mixed in would put its members
  at the owner's level, which is what the mount exists to prevent; an
  alter's `mixIn()` is refused, naming the mount to attach at instead.
- **Stored flat, described by the owner's config schema.** The members
  are the owner's keys, so storage holds them beside the owner's own
  and the owner's config schema lists them. A mapping of the members
  cannot be inherited, since a config object's mapping already extends
  `config_object`, so the owner repeats them; whatever attaches the
  same structure uses one mapping type of them
  ([Storage](storage.md#keys-a-mixed-in-structure-brings)).
- **Refused at build.** A member that is a key the owner, or another
  structure mixed in, declares (named both ways: the owner first, the
  structure first, two structures), since one name is declared once; a
  structure mixed into itself, directly or through another; a structure
  holding a child (a child of the owner at a key the structure named is
  not built: attach it instead); a resource, which stores itself; one
  with outputs, which are not merged. A structure may mix another in.
- **Emitted as a composition, so the def stays reusable.** The frame is
  `allOf` of a reference to each structure's def and its own
  `properties`, closed with `unevaluatedProperties: false`. Each member
  keeps its place in `properties`: `true` where the def says all there
  is, only what differs where the owner narrows it here (a lock, a
  context, these values, a port read at the owner's key), `false` where
  it does not apply to these values. What the def requires and the
  conditionals it states are not said again. A def is closed with
  `additionalProperties`, which would refuse the owner's keys beside it,
  so in a document that mixes a structure in its def is written open,
  and every other place that uses it closes it with
  `unevaluatedProperties: false` beside the reference; in a document
  that only attaches it, it is the closed def it always was, so the
  examples' documents did not move. `true` rather than `{}`, because
  `{}` decodes to an empty array in PHP and encodes back as `[]`.
  `SchemaResolver::inline()` and the React app's `inlineSchema()` lay the
  frame over each def and close it with `additionalProperties`, so a
  reader that resolves the references sees the flat frame the pipeline
  reads.
- **Refined in the owner's frame.** A structure's coded refiners and
  declared rules are bound in the owner's chain, before the owner's
  own, and still narrow only its own members and watch or read only its
  members and its ports; each port is read at the owner's key it is
  wired to, which is the edge, the AJAX trigger, the `dependsOn` and
  the name in a refusal. Every port is wired, required or not: in the
  owner's frame an unwired port has no key to be read as (open: an
  optional port left unwired). The owner's refiners and rules, and its
  alters', may watch and narrow the members: they are the owner's keys
  now, declared into its blueprint and refined in its frame, so there is
  no frame between them and nothing to breach. The wall is the boundary
  between frames, and what keeps the def reusable is kept: the
  structure's own refiners never see the owner's keys, and whatever
  the owner adds is stated on its own frame, beside the reference, never
  in the def. Two exceptions: whether a member applies is the
  structure's to say (its def, shared by every owner, says where it is
  required), so the owner may not rule one out; and nothing of the
  owner's may name a member of a structure that has an access class,
  since what that access takes away for one account nothing may depend
  on for every account.
- **Alters.** An owner's alters see the members as the owner's keys. A
  structure's alters apply wherever it is mixed in, as wherever it is
  attached: they reword, narrow and offer more values on its members,
  through a blueprint that names its members alone; one that mounts a
  key would mount it on the owner, and is refused there (open). A
  container cannot opt out of a structure's alters, as for ports (open).
- **Access.** The structure is built by itself in the owner's context,
  for its def and its access class. Locked holds its members to what is
  stored (`Entry::$held`: shown and fixed, `readOnly` in both documents,
  `const` of what is stored in the schema for these answers and none in
  the full schema); omitted takes them back out of the owner's keys and
  storage keeps what it holds under them, by path inside a child the
  owner stores, which an omitted child inside a stored child now gets
  too. The owner's access answers for the rest, and where both answer
  for the same keys the stricter stands: an owner left out takes the
  members it mixed in with it, an owner locked locks them, whatever the
  structure answered. An alter of the structure that answers access
  answers for its members too, after the structure's own, in the
  owner's frame ([An alter may answer
  access](#an-alter-may-answer-access-and-the-stricter-stands)).
- **The tool flattens by reading the data surface.** The tool bridge
  converts the built data surface, where the members are the owner's
  keys, so `values` is flat; the Tool API's normalizer writes from typed
  definitions and has no `allOf` to carry.

Open: listing mixins in the catalog; an optional port a mixin's owner
leaves unwired; a mixed-in structure's alter that mounts; a mixed-in
structure holding a child; a container opting out of a structure's
alters. Found on the way and left: the React page narrows an attached
child whose def states conditionals of its own to the instance (its
answered conditionals dropped), where `/refine` inlined carries the
def's too, the same constraints written twice; the Vitest chain for the
journal leaves the child's step out for it.
(`BlueprintAdditionsInterface::mixIn()`, `ResourceBlueprint::mixIn()`,
`Attaching::mixIn()`, `Binding::bindMixin()`,
`Attaching::assertMixinsUnnamed()`, `Mixin`, `ContractEmitter::composed()`,
`SchemaResolver::mixIn()`, `mixIn()` in `app/src/contract.ts`)

### An empty variant is an empty map

A variant may declare no keys, the spelling of "this choice has
nothing more to say". It renders as nothing (an empty container on the
form, nothing on the React page), its value is an empty map, and both
contracts and the tool state an object with no properties; a value left
by another variant does not fit it and goes, as between any two
variants, but a secret, which is kept
([An extension is a variant the site adds, or an alter](#an-extension-is-a-variant-the-site-adds-or-an-alter)).
The fieldset widget serves any complex definition and answers an
empty one with `[]`: a map that declares nothing can hold nothing, and
before this the form threw for want of a widget. A key that does not
apply at all, rather than applying with nothing to say, is
[a key that does not apply](#a-key-that-does-not-apply).
(`MapWidget`, `Field.tsx`)

### Outputs hold no children

`attach()` and `attachVariants()` on outputs are refused. The pattern lets an
output vary by an input that way, but the engine's output map has no
children yet. (`BlueprintAdapterBase`)

## Collections

### A list of child invocations

A collection (`attachList()`) is a list of child invocations: every
item is the child, built once for the list in the context its
operation resolves, as `attach()` builds its child, and refined,
accepted and validated by the child in its own frame, each item's
context knowing its `delta`. Built once because a blueprint cannot depend
on a value, and how many items there are is a value; the frames differ
only in the values they are handed and their position. The entry
carries a `Collection` rather than an attachment, so the key is the
parent's own and every door reads it as a list: an array of references
to the child's def in both contracts, a list input of the child's maps
in the tool, a details of item details on the form. Each item's
context is carried (`Collection::contextFor()`), and nothing reads an
item's delta yet: a child that names storage of its own is not routed
per item, and the parent stores the list. A child may hold a collection
of its own: every door recurses, and the form draws a list inside an
item as a list in its place ([A collection on a form](forms.md#a-collection-on-a-form), decided 2026-10-19). *Open:*
routing an item to its child's storage, which needs an identity key on
the child to say which stored item it is. (`Resources::buildResource()`,
`Collection`, `Form\Drawing::buildCollection()`)

### Access once per list

A collection's child is invoked through its operation once for the
list, and that access answers for the list: open, locked (the list key
locked, so every item and the form's add and remove controls) or
omitted (the key left out). Field access is per field, not per delta,
and an item has no subject of its own to be asked about before it
exists. *Added 2026-10-25:* a gate the operation borrows is asked per
item, about the entity the item refers to, once the values are known
([A gate is borrowed, never copied](#a-gate-is-borrowed-never-copied)).
(`Resources::buildResource()`)

### Empty items are dropped

An item that holds nothing at all, every key `NULL` or `''` at any
depth, is dropped before validation, the way core drops an empty
delta: it is a row a form added and left blank, not an answer. FALSE
and 0 are answers. The pipeline drops it in `accept()`, so a payload and
a form agree, and the form drops it on extraction too, to flag a later
item's violation on the row it was drawn as. Its keys are read before
it is dropped: an unknown key in a blank item is refused by its path,
as in any other item, never dropped silently (the never-stricter audit,
case 2). An item is accepted over
the child's defaults, not over what is stored at its delta, since the
list is sent whole and an item may have moved; except a secret, which
keeps its position's ([Every door projects what the read tool
projects](#every-door-projects-what-the-read-tool-projects)).
(`Collection::isEmptyItem()`, `Accepting::acceptCollection()`)

### An item is never null

An item of a list is never nullable in either schema: its type has no
`null`, and a list of choices offers no untitled `{const: null}` for an
item. Decided 2026-10-19, from the second fresh-eyes log (entry 19),
where a required list of weekdays offered `null` as a day. An empty
item, of a plain list as of a collection, is dropped by `accept()`
before anything reads the list, so null never reaches the accepted
document, which is the scope both schemas are held to: a null item
posted is dropped and the document is accepted by both, and a schema
that offered null would advertise a value no stored list holds. The key
holding the list still allows null while it is optional, since null
clears it ([Absent keeps, null clears](#absent-keeps-null-clears)). The
tool listing is the Tool API's normalizer's, which still reads an item
as optional. (`PropertySchema::neverNull()`,
`AdvertisedSchemaAcceptanceTest::testListItemsAreNeverNull()`)

### Count is the parent's

The list key belongs to the parent: its Count, and any constraint on
the list itself, is the parent's to declare (`min`, `max`), to narrow
with a `#[RefinesInput]` method on the key, and to narrow with an
operation's `withConstraint()`. Each item's rules are the child's. So a
parent's refiner may refine the list and may not watch it, a parent
refiner that narrows inside the item it is handed is refused when the
parent is refined, and validation says the Count about the list and
each item's violations under its delta. Count is compared by its
bounds, as Length and Range are, so narrowing it is checkable at all;
nothing shipped carried a Count before, so nothing that narrowed
one changed verdict.
(`Surface::assertItemsUntouched()`, `Narrowing::COMPARATORS`)

## Storage

### A child learns its parent's accepted identity

A routed child is prepared and committed in its own context plus the
identity values its parent accepted that it does not already know. A
creating parent's identity exists only once accepted, and a child's
storage loads by identity. (`StorageAdapter`)

### Write order

An attached child is committed before its parent, a variant
after. An attached child is a part the parent is built on (a field's
storage); a variant lives on what the parent writes (a field's
settings). (`StorageAdapter::commit()`)

### A config object is named on a subclass

`ConfigObjectStorage` is abstract, and a module names its config object
in a subclass's `CONFIG` constant, the way the examples' storage classes
did before it existed. A storage class is an autowired service, one per
class, so it cannot be handed a name per resource; an argument on
`#[Resource]` would put a storage detail on the resource, which the
pattern keeps out of it. The subclass is three lines, and it is where a
settings resource's storage grows if it needs to. (`ConfigObjectStorage`)

### A config object is described by its resource

The resource definition is the single description of a config object,
and its config schema is derived from it, as a stepping stone until core
can read a data definition as the schema. Decided by the owner on
2026-10-20, from the first change three reviews proposed (laravel,
creative-generalist, drupal-architecture; [synthesis 3.1](reviews-2026-10-20/synthesis.md)): the first settings page declared
each key twice and two defaults three times. Seeding the class from the
schema, the architecture review's direction, was rejected: it would
entrench what the module means to retire, a second declaration in the
format core is asked to replace with data definitions
([core gaps, step 2](core-gaps.md#step-2-operations-and-access-for-simple-config)).

- **Written, committed, checked.** `drush surface:config-schema`
  writes, for every resource a `ConfigObjectStorage` stores, the
  object's entry in `config/schema/<module>.schema.yml` and its
  `config/install` file; without `--write` it prints the difference and
  fails when there is one. `ConfigSchemaDriftTest` holds the shipped
  ones to it. The schema stays a committed file and the storage's truth
  ([The whole object is checked](#the-whole-object-is-checked) stands),
  read by core with nothing of this module running: generation removes
  the keeping in step by hand, not the schema.
- **What it says.** Fully validatable at the root; a type per key from
  its definition (a multi-line string a `string`: a resource says
  nothing of translation, so nothing generated is translatable);
  `nullable: true` where the three-way rule lets null clear (an optional
  key that is no secret and that its storage does not always hold), and
  on a required key installed holding nothing; `requiredKey:
  false` where a key may be absent, a module's mounted keys (stored once
  sent) and the keys of an entry from the site; the bounds and patterns a
  schema can carry, from the definition's own (`Length`, `Range`,
  `Regex`, `Count`, `NotBlank`), and none on a key a rule may rule out,
  which the rule holds only where it applies; no list of values, since a
  stored value a list no longer offers is kept as stale, and a `Choice`
  in the schema would refuse every later write to the object over it;
  the owner's labels, not an alter's rewording.
- **Which keys are always held is the storage's to say.** Whether a key
  may hold nothing is a fact of where it is stored ([A key its storage
  always holds](#a-key-its-storage-always-holds)), so the storage
  subclass that names the object names them too, `ALWAYS_HELD`, by
  dotted path; the schema leaves `nullable: true` off them, and
  `mayClear()` reads it back. So the generated schemas keep every key's
  nullability as it was: example 11's badges and greeters and the
  recipes' after-hours flag, keywords and holidays always held, every
  other optional key cleared by null. Deriving it from a declared
  default was tried and turned down: it made every optional key with a
  default refuse null on objects that were not fully validatable, a
  breaking change in twenty-three properties of the examples' contracts,
  which is a decision of its own.
- **The install file** keeps the value it gives a key (what a site
  starts from may differ from the default, as example 7's send day and
  example 11's greeters do), takes the declared default for a key it
  does not give, and drops a key the resource does not declare. What a
  resource cannot say is kept from the file too: the object's label, a
  variant's when it declares none, the comments, and every entry no
  resource writes. Each entry written says so on the line above it.
- **Before the install, in one run.** A module named that is not
  installed is read from its code: its classes are scanned from its
  `src/` as discovery scans an installed module's
  (`ResourceCollectorPass::discover()`), autoloaded from there, and its
  alters, storage and access classes built as the container would build
  them (`AutowiringClassResolver`), with nothing installed or cached.
  So a new module is written, then its files, then installed once. The
  entity types it declares are read from its `src/Entity` as the entity
  type manager discovers them, so a key that refers to one is built as
  it will be (`References::knowing()`; *added 2026-10-26*, when example
  2's venue, a reference to the examples' own entity type, stopped the
  examples' files being written before their install).
  Decided 2026-10-21, from the fourth fresh-eyes log (entries 13 and
  14): the command read only installed modules, so a module was
  installed without its config object and installed again after the
  files were written, and an owner and the module mounting keys on it
  were written in two runs, the tool deriver warning in between that
  the owner advertised a mount no schema described. Requiring the
  module installed and creating its object from the file just written
  was weighed and turned down: it keeps the install that reads no
  install file, and an install file is read once, at install.
- **Mounted keys** follow the existing convention: the owner's
  `third_party_settings` is written when a module mounting there is
  installed, or named in the same run, as it is generated, and each
  mounting module's entry
  `<config>.third_party.<module>` in its own file. Open: whether every
  generated object should declare `third_party_settings` whatever is on,
  as core does for config entities, so an alter from a module the owner
  never generated with is not refused under that key.
- **A schema the module did not generate is held to.** Core's
  `node.type.*` or an adopter's written by hand: a storage implementing
  `HeldToConfigSchemaInterface` says where the resource advertises more
  (type, nullability, `Regex`, `Length`, `Range`, `Choice`, `Count`,
  `NotBlank`, `Email`), and the build refuses it, naming each key, so
  the contract never advertises what storage will refuse. A generated
  schema is never narrower; one written before the resource changed is
  refused until it is written again, and the generator builds without
  the check (`Resources::declaration()`). The node type resource, which
  the architecture review found restating core's schema less strictly,
  states core's control-character patterns now, and `NodeTypeStorage`
  says from the schema that its two booleans always hold a value.
- **An entity type a module declares** (added 2026-10-23, example 15). A
  resource whose `ConfigEntityStorage` names an entity type its own
  module declares gets that type's entry, `<module>.<config prefix>.*`,
  a fully validatable `config_entity` whose mapping is each key the
  storage maps to a property, by the property's name, by the same rules;
  `ALWAYS_HELD` on the storage says which optional keys are never null.
  No install file is written: what a module ships of an entity type is
  things, which no resource describes. An entity type of core or of
  another module keeps the schema its owner writes, held to as above
  ([A listing is a read-only operation with outputs](#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection)).

(`Storage\ConfigSchemaGenerator`, `Drush\Commands\ConfigSchemaCommand`,
`ResourceBuild\AutowiringClassResolver`,
`Storage\HeldToConfigSchemaTrait`, `Declaring::assertHeldToSchema()`,
`ConfigSchemaDriftTest`, `ConfigSchemaCheckTest`, `ConfigSchemaCommandTest`)

### The generator writes what the first page shows

Decided 2026-10-24. `drush generate surface:resource` writes every file
[Your first resource](first-resource.md) lists: the permission and the
route, the resource class, its storage, and the config object's schema
entry and install file, from an interview or from one of two sources,
`--from-config-schema=<name>` and `--from-json-schema=<file>`
([Generating a resource](generating.md)).

- **One generator, three sources.** Each source is built into a sealed
  data surface by the build step before anything is written: the
  answers declared as the class will declare them
  (`Drush\Generators\Resource\KeyAnswers`), a config schema entry
  written as JSON Schema (`Contract\ConfigSchemaDocument::document()`)
  and read, a document read as it is. One writer turns the built keys
  into the calls an author makes (`ResourceCode`). Why: a key the reader
  learns to read reaches all three at once, where a template per source
  would drift from the others.
- **The page stays the truth.** The page quotes the shipped module, as
  it did; `ResourceGeneratorTest` answers the interview as the
  newsletter needs and compares each file it writes with
  `surface_examples_first` byte for byte, whitespace included. Why: the page
  is the module's concept budget (ROADMAP item 13). Generating the page
  from the generator would make the generator the description and the
  page a copy that nothing reads back.
- **The schema entry and install file come from the command's mapping.**
  `ConfigSchemaGenerator::describeBuilt()` is what `describe()` runs, for
  a resource built any way. The generator writes from the build of its
  source; once the files are in the module's own directory, installed or
  not, it builds the class just written as the command does (a module
  read again from its code, `including(..., fromCode: TRUE)`) and writes the
  class's files where they differ, saying so. Why: the first build runs
  under `--dry-run` and before any class exists; the second catches a
  writer that left something out.
- **Seeding from a config schema, once.** [A config object is described
  by its resource](#a-config-object-is-described-by-its-resource) turned
  down seeding the class from the schema as the lasting description. The
  generator seeds once: after it, the class is the description. For
  another module's object (`node.settings`) the owner's schema stays
  that object's description and the resource is held to it
  (`HeldToConfigSchemaInterface`), so the config schema generator writes
  no entry for an object whose name another module provides (core's
  rule: the first part of the name), and the generator writes neither
  file for one.
- **A foreign constraint is a comment.** What the reader keeps as a
  `JsonSchema` constraint, and what a config schema states that its
  document does not carry, is written as a comment above the key,
  naming the keyword and the key. Why: written as a `JsonSchema`
  constraint it would still be checked, but as a schema array the author
  did not write and may not read; a comment is in plain sight, and the
  author writes the constraint the module has for it. For a constraint
  the build holds a resource to its object's schema by (`Regex`,
  `Length`, `Range`, `Choice`, `Count`, `NotBlank`, `Email`), the comment
  says the build refuses the resource until it states it.
- **A YAML file is added to, never replaced.** A routing or permissions
  file is read, the entry added at its end after a blank line, and
  written; the rest is kept byte for byte, an entry of the same name is
  kept and the generated one left out, and a note says which. Why: those
  files carry entries the generator knows nothing of, and parsing and
  dumping them would rewrite their quoting and comments.
- **Two source options through Drush.** Drush hands a generator its
  answers and its own few options, so a listener declares both on the
  `generate` command and the generator reads them from Drush's input
  (`Drush\Listeners\GenerateSourceOptionsListener`).
- *Open:* a resource with identity, stored in a config entity type. The
  interview offers it, asks the identity key and stops, naming example
  15; writing the entity type, its storage and the operations' methods
  is later.

(`Drush\Generators\ResourceGenerator`, `Drush\Generators\Resource\ResourceSources`,
`Drush\Generators\Resource\ResourceCode`, `Drush\Generators\Resource\YamlEntryResolver`,
`Storage\ConfigSchemaGenerator::describeBuilt()`, `ResourceGeneratorTest`,
`ResourceGeneratorPathautoTest`)

### The whole object is checked

`ConfigObjectStorage::prepare()` validates the written values laid over
the stored object, not the written keys alone, with violations filed
under the keys being written. A type that resolves through a sibling and
core's language code rule, which is checked at the root, only judge the
object whole. It owns `langcode` by core's rule (present exactly when the
schema has a translatable value) and `_core`. A module's mounted
settings that resolve to no schema type are refused, since config
validation lets data no schema describes through silently. A config object with
no schema at all is a `\LogicException`, not a violation: it is the
developer's to fix, before any person sees the form.
(`ConfigObjectStorage::prepare()`, `ConfigObjectStorage::mountsWithoutSchema()`)

### Who produces outputs

A resource's outputs are what its storage's `load()` hands back under
an output's name, once the values are committed. The storage class
already knows how to read the thing it wrote; a second verb would say
the same twice. *Added 2026-10-23:* a read only operation is never
committed, so its outputs are what its storage's `prepare()` answered
under an output's name: prepare is the last stage a read runs, and one
with no side effects, so the storage reads there, and a listing's page
is that answer
([A listing is a read-only operation with outputs](#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection)).
*Changed 2026-10-25:* a dry run of any other operation answers what
its `prepare()` rehearsed under an output's name, the same way, where it
answered none: a dry run is a call that stops before it writes, and its
caller wants the answer the write would give as far as the storage can
say it before writing. Example 17's dry run answers each node's old title
and the one it would write; a storage that rehearses nothing under an
output's name answers nothing, as before.
(`StorageAdapter::outputs()`)

### A computed key is a pure function of its sources

Decided 2026-10-23, with example 15's slug: a key of the resource whose
value nobody types, computed from other keys and read by callers. The
spelling is `$inputs->compute('slug', 'string', t('Slug'), from:
['label'], using: Slugify::class, when: Compute::ON_CREATE)` on the
owner's blueprint, returning the definition as `add()` does; the class
implements `ComputesValueInterface`, whose `compute(array $from): mixed` is
static. The pipeline computes it in `accept()`, once every other key of
its level is accepted, from their accepted values; `validate()` judges
it by its own constraints and rules, `prepare()` and storage write it as
any key, and the read answers it. *Renamed 2026-10-24:* the verb was
`derive()`, the class `DeriverInterface` with a static `derive()`, the
constants `Derive::ON_CREATE` and `ON_CHANGE`; "deriver" now names one
thing, a class that makes resources at discovery
([The surface was counted and cut](#the-surface-was-counted-and-cut)).
Three choices settle it:

- **Pure functions only.** The class is handed the values of `from`
  and nothing else. With assertions on it runs with the container
  guarded (`PipelineGuard::guardCompute()`, which refuses every
  service), and the module's PHPStan rule flags `\Drupal::` in it. Why:
  a pure function can run anywhere the values are, so `POST .../schema`
  and a form's rebuild answer the value a write of the values in
  progress would store, and the value stored is the value shown. A
  runtime one could not be run ahead, and would need a mark of its
  own in both documents for a value the client cannot predict. A changed
  time reads the clock: it stays a storage's to add in `prepare()`, as
  the language code `ConfigObjectStorage` adds is.
- **When it runs, `when:`.** `Compute::ON_CREATE`, the default, computes
  it while storage holds nothing for it, which is how a thing being
  created starts, and keeps what is stored after: a slug a link points
  at must not move when the name does. `Compute::ON_CHANGE` computes it
  again on every write in which a key in `from` holds other than what is
  stored. A write that changes no source keeps what is stored either
  way. Why a declaration and not one rule: the two cases are both
  common and say opposite things about an edit, and only the author
  knows which the key is. Why "storage holds nothing" and not the
  operation's `creates`: a thing stored before the key existed gets its
  value at its next write, and a post-update can write the same value
  for what is stored (`surface_examples_post_update_venue_slugs()`).
- **A value sent for it.** Equal to the one it holds for these values
  (computed, or stored where it is not computed again), it is noted,
  code `computed`, "Slug is computed from Name; the value sent was not
  taken.", as a locked key's is; any other is kept in the accepted
  document for `validate()` to refuse, code `computed`, "Slug is
  computed from Name and takes no value of its own; leave it out.", as
  a value sent for a key that does not apply is. Why not refuse every
  value: a client that writes back the document it read sends the
  slug it was given, and refusing that breaks the plainest client. Why
  not ignore every value: a different value is a caller who believes it
  set the slug, and a note it may not read leaves it believing so.

A refusal of the value it holds, which nobody sent (its pattern, the
venue storage's rule that a slug is held once), names the keys it is
computed from, the ones a caller can change, in a sentence of its own
after the refusal: "The slug riverside-hall is held already, by
riverside: a slug is held once across the venues. This is computed from
Name." While a key in `from` is refused itself, the computed key's
refusals are left out: they follow from it.

On the wire: both documents state it `readOnly: true` with
`x-surface.computed: {from, when}`, no `const` (what it holds follows
its sources), never in `required`; `readOnly` alone means locked, so the
marker is what tells a client to show it, narrow when a source changes,
and never send it. The write tool's `values` leaves it out and the read
tool's output keeps it, so the two shapes differ by the computed keys;
the read's definition is still the write's, built with them
(`OperationInputs::map()`). The form draws it as an item, which posts
nothing; the React page draws it disabled and leaves it out of a write
(`withoutComputed()`). The generated config schema describes it as any
key, since storage holds it. Read back from a document, it is a key
nobody types that holds what is stored: the class computing it is one
no document names.

*Open:* a computed key whose class may read the clock or a service, declared
runtime, never run ahead and marked in both documents; a computed key in
a structure, or one an alter declares under its own mount from the
owner's keys it may read (refused for now: what a structure's level is
computed from would need the structure's own read and listing, and an
alter's from the owner's keys crosses the wall the alter reads only at
its mount); computing again on demand, an operation that recomputes a
stored value its sources no longer give; the React page computing the
value locally, which needs the class in a language the page runs.
(`Computed`, `ComputesValueInterface`, `Compute`, `ResourceBlueprint::compute()`,
`Accepting::acceptComputed()`, `Validating::notComputed()`,
`Validating::namingSources()`, `PipelineGuard::guardCompute()`,
`SurfaceFormBuilder::refinementOverlay()`, `Slugify`, `ComputedKeysTest`,
`VenueResourceTest`)

*Amended 2026-10-26:* a third `when:`, `Compute::UNLESS_SENT`, computes
the key only when a call leaves it out, or sends it null or empty, and
keeps a value sent, with no note and no refusal. Such a key is not
`readOnly`, the write tool offers it, and a form draws it by its widget.
A refusal of it names its sources only while it holds the value they
give, "This is computed from Name unless a value is sent.", and is left
out while one of them is refused; holding another value, it holds the
caller's own, and its refusals stand as they are.
The class computing a key is handed the key's own definition beside the
values, `compute(array $from, ?DataDefinitionInterface $key = NULL)`:
static declaration, no site state, which the stock `MachineName` reads
to cut to the key's Length. And one class may reach one service: the
guard admits `transliteration` to `MachineName` by name, a function of a
string and a language code that reads no site state, and refuses every
other service to every class as before
([A machine name is a computed identity](#a-machine-name-is-a-computed-identity)).

### A machine name is a computed identity

Decided 2026-10-26, with example 15's venue and the content type example.
Drupal pairs a title with a machine name: the machine name is suggested
from the title, a person may type over it, it is unique, and it does not
change once the thing exists. It is one spelling with no verb of its
own, `id` being the resource's identity key:

```php
$inputs->add('label', 'string', t('Name'))->setRequired(TRUE);
$inputs->compute('id', 'machine_name', t('Machine name'),
  from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT);
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Five parts, each an existing mechanism:

- **The type.** `machine_name` is core's config schema data type: a
  string with a Regex and a Length of 166. `add()` and `compute()` read
  a type typed data does not know from the config schema
  (`Shapes::configType()`): the key is the string underneath, with the
  type's constraints as its own, marked with the type
  (`DefinitionMetadata::configTypeOf()`), and held to narrowing them as
  a key declared with a shape is. Both documents state `pattern` and
  `maxLength`, the schema reader reads them back, and a tool caller sees
  them. The generated config schema writes `type: machine_name` and only
  what the key narrows, as core's `node.type.*` does. Two readings make
  the pattern one a client can check: a Regex anchored with `$` is read
  with `D`, which narrows it by a final line break only
  ([A pattern both engines read alike](#a-pattern-both-engines-read-alike)),
  and a message that names the value it refuses (core's "The %value
  machine name is not valid.") is a refusal's sentence, so no
  `patternMessage` is stated for it. Why the config schema type and not
  a shape of this module's: a config entity's id is a `machine_name` in
  core's schema already, so the resource and its storage name one type.
- **When it is computed, `Compute::UNLESS_SENT`.** Left out of a call,
  or sent null or empty, it is computed as `ON_CREATE` computes it,
  while storage holds nothing for it; sent, it is kept as sent and
  judged by its own constraints. A refusal of the value its sources give
  names them, "This is computed from Name unless a value is sent.";
  a refusal of a value of the caller's own names nothing more.
  `ON_CREATE` and `ON_CHANGE` still refuse a value sent other than the
  one they hold, code `computed`.
  Both documents keep `x-surface.computed`, `"when": "unless_sent"`,
  with no `readOnly`, and never list it in `required`; the write tool's
  `values` offers it. On an update the identity is what the operation
  knows, locked at every door, as before. Why a mode and not a verb: it
  is a computed key in every other respect (its sources, a pure class,
  the narrowing answering its value), and only what a value sent means
  differs.
- **The stock class, `MachineName`.** It transliterates the first source,
  lowercases, replaces each run of characters outside `[a-z0-9_]` with
  one underscore, cuts to the key's Length and trims underscores at both
  ends: the steps of core's `machine-name.js` with its default
  `replace_pattern`, in its order, so the name the browser suggests and
  the name the server computes for a call that leaves the key out are
  one name. "Café Déjà Vu" is `cafe_deja_vu`. It reads the key's Length
  from the key's own definition, which `compute()` is handed. The guard
  admits one service to this one class by name, `transliteration`
  (`PipelineGuard::COMPUTE_ALLOWED`, and the same list in the PHPStan
  rule): transliteration is a function of a string and a language code
  with no site state. Every other service stays refused, to it and to
  every other class. It transliterates for English; the browser
  transliterates for the page's language, and what it suggests is sent
  and kept.
- **Uniqueness, `SurfaceUnique`.** A stock constraint nobody writes:
  the build adds it to the identity key of every operation that creates,
  when the resource has one identity key and its storage implements
  `DeletableStorageInterface`, which now also answers `exists()`, whether
  a thing is stored under the identity a context knows, and
  `singularLabel()`, "venue" (`Declaring::holdIdentityOnce()`). Its
  refusal names the id and the thing, code `taken`: "A venue named
  riverside already exists." Both documents name it under
  `checkedOnServer` with its reason, "Refused when a venue named this
  already exists." `ConfigEntityStorage` answers both, and its
  `prepare()` refuses a taken id in the same sentence for a caller that
  skips validation. Why on the deletable storage: a storage that
  removes a thing by its identity loads it by that identity, so it can
  say whether one is there. A method on `ResourceStorageInterface` would
  have to be written by every storage, those with no identity included,
  and a new interface would grow the counted surface for the same
  question ([The surface was counted and cut](#the-surface-was-counted-and-cut)).
- **The widget.** A key of type `machine_name` computed unless sent from
  a key beside it is drawn as core's `machine_name` element
  (`Form\MachineNameElement`): its source is the source key's element,
  and its `exists` callback asks the storage what the constraint asks
  (`UniqueConstraintValidator::taken()`). The element's own validation
  is off, since a form keeps the first error per element and core's
  words would replace the constraint's, which are the tool's and HTTP's.
  On an update the key is locked, drawn disabled with its value, as any
  locked key is. The React page draws it as a text input, shows the
  narrowing's suggestion, and sends it once the person typed it.

Moved onto it: example 15's venue `id`, whose slug stays `ON_CREATE`,
the contrast the page shows, and the content type example's `type`, whose
`SurfaceUniqueNodeType` constraint and whose cosmetic machine name
element are deleted. A placed block and the storage test's config entity
get `SurfaceUnique` too: their storages are config entity storages.

Rejected: a blueprint verb of its own, `machineName()`, since it would
repeat `compute()`'s sources, class and mode under another name, where
every door reads a computed key already; and a uniqueness constraint per
resource, as the content type example had, since each repeats one storage
question in its own words, and a resource without one lets every door
offer a taken id.

*Open:* an identity of several keys gets no uniqueness constraint, since
a constraint sees one key's value; the refusal says "A" before every
noun, "A image style" included; and a source and its machine name drawn
in different groups of an arrangement are not paired, since the element
looks for its source beside it.
(`Compute::UNLESS_SENT`, `Computed::takesSent()`, `Shapes::configType()`,
`MachineName`, `PipelineGuard::COMPUTE_ALLOWED`, `UniqueConstraint`,
`UniqueConstraintValidator`, `DeletableStorageInterface::exists()`,
`Declaring::holdIdentityOnce()`, `MachineNameElement`,
`ComputedKeysTest`, `MachineNameTest`, `UniqueIdentityTest`,
`VenueApiTest`)

### A layout is the storage's one word on its shape

Decided 2026-10-27, with the pathauto port. A key a resource asks for
one way and a config object keeps another (a checkbox per type, stored
as the list of the types checked) took three overrides on the storage
subclass: `load()` and `prepare()` to translate, and `widerThanSchema()`
to drop the key from the check. The config schema generator read none of
them, so it wrote the asked shape, which the stored list does not
match, and such a module kept its schema written by hand.

- **The seam `FieldSettingsStorage` already had.** `ConfigObjectStorage`
  asks a protected `layout(): ?StorageLayoutInterface`, NULL by default,
  which a subclass overrides: `load()` reads through its
  `fromStorage()`, `prepare()` writes through its `toStorage()` before
  the config schema judges the object. The constructor and the service
  wiring stay as they are, so a subclass that needs a service for its
  layout already has one.
- **The stored schema, said once.** A layout implementing
  `StoredSchemaInterface` answers `storedSchema()`: the config schema of
  each key it translates, as stored, by dotted path. The storage's
  `translatedKeys($built)` joins it with the references the engine
  stores by uuid ([A config object stores a reference by
  uuid](#a-config-object-stores-a-reference-by-uuid)), and both readers
  ask that one method: `widerThanSchema()` skips each key it names, and
  the generator writes its schema in place of the asked one, labeled
  with the key's own label where it gives none, `nullable: true` on a
  key asked as one value by the three-way rule, and installed with what
  the install file gives it, else nothing, since a declared default is
  in the asked shape.
- **Beside `StorageLayoutInterface`, not on it.** Every existing layout
  could answer the question: `AddressStorageLayout` its countries as a
  sequence, `ReviewDeadlineLayout` its seconds as an integer. But a field
  type's settings and an alter's mount are stored under a schema their
  owner writes, which nothing here generates, so the answer would have
  no reader, and adding a method to the interface breaks every layout an
  adopter wrote. An optional interface costs a layout nothing until it
  is read. It is in `src/Storage/`, which the counted surface does not
  include ([The surface was counted and cut](#the-surface-was-counted-and-cut)).
- **A layout stays pure.** It reads no service and no site state, as
  before; a rule that needs the site stays in the storage's `prepare()`,
  on the values as asked (the pathauto port leaves out a type whose path
  field another module provides there, then calls the parent).

Moved onto it: the recipe's alias settings (`CheckedListLayout`), and
the pathauto port on the development site, whose `widerThanSchema()`
and `load()` overrides are gone.

Rejected: the generator reading `load()` and `prepare()` by running them
on sample values, which guesses at a shape a class states in one line;
a schema override per key on the resource, which puts a storage fact on
the resource the pattern keeps it out of; and `layout()` on
`ConfigEntityStorage`, which maps keys to properties and has
`fromEntity()` and `toEntity()` for the rest, with no case yet.

*Amended 2026-10-27:* an alter's layout of its mount
(`AltersStorageLayoutInterface`) that implements `StoredSchemaInterface`
tells the check and the generator as the storage's own does, each key
at `third_party_settings.<module>.<key>`
(`HeldToConfigSchemaTrait::mountedLayouts()`, both config storages'
`translatedKeys()`). A reference an alter mounts was already among the
keys the uuid layout names there. An entry of a map of keys from the
site is named `*` in a translated path (`pages.*`, `authors.*.author`),
since which entries there are is the site's: the check matches `*`
against any entry, and the generator writes the sequence's item as
stored.

*Open:* `configSchemaPaths()`, for a key stored under another name, is
still its own override.
(`ConfigObjectStorage::layout()`, `StoredSchemaInterface`,
`HeldToConfigSchemaTrait::translatedKeys()`, `ConfigSchemaGenerator::translated()`,
`Resources::storageInstance()`, `ReferenceStorageTest`,
`ConfigSchemaCheckTest`)

### A resource may hold some keys of a config object it does not own

Decided 2026-10-28, with `surface_examples_performance`. Core's config
objects keep settings several forms edit, at paths:
`system.performance` keeps the page cache's maximum age at
`cache.page.max_age`, beside `css.compress`, `js.compress` and
`fast_404.*`, which no form of core's performance page edits. A
`ConfigObjectStorage` stored each key at its own top-level name, and the
check resolved one level of the schema, so such an object could not be a
resource's.

- **The resource declares the keys it edits, by their own names.** A
  name is what every door shows (`max_age` in the tool, the document and
  the form), and a dotted name would read as a nested key.
- **The storage says where each one lives**: `STORED_AT`, a dotted path
  by key, beside `CONFIG`. `load()` reads it there, `prepare()` lays it
  into the whole object there and files the schema's violations under
  the key, `commit()` sets the path alone, and `mayClear()` reads the
  schema there. A dry run reports the key by its path, which is the
  storage layout.
- **What it does not declare is kept byte for byte**: nothing sets the
  map a path runs through, only the path, so `css.compress` and
  `fast_404` stay as stored.
- **The check holds each key to the property at its path.**
  `HeldToConfigSchemaTrait` follows a dotted path through each nested
  `mapping`, for `STORED_AT` and for `configSchemaPaths()` alike, so the
  resource cannot offer a value the schema refuses and a refusal names
  the key.
- **No schema is generated for it.** The object's schema is its owner's;
  the generator writes each key at its own name, so it refuses a storage
  with `STORED_AT` by name rather than write a wrong one.

What it shows: core's schema says only `type: integer, label: 'Max
age'`. The resource declares the title, the description and the list of
fourteen periods with their labels once; the form, the tool, the MCP
listing, the OpenAPI document and the React page read them from it, and
core's schema stays as core wrote it. Rejected: a layout
(`StorageLayoutInterface`) that moves keys, which works on the values
alone and cannot keep the siblings, and cannot tell the check where to
look; a constant naming the map the keys share, which a key at another
depth (`cache.page.max_age` beside `css.preprocess`) does not fit.

This answers the open point of [A layout is the storage's one word on
its shape](#a-layout-is-the-storages-one-word-on-its-shape) for a config
object; `ConfigEntityStorage` still answers `configSchemaPaths()` itself.
(`ConfigObjectStorage::STORED_AT`, `HeldToConfigSchemaTrait::schemaAt()`,
`ConfigSchemaGenerator::describe()`, `PerformanceStorage`,
`ConfigObjectStorageTest`, `PerformanceSurfaceTest`)

### A config object stores a reference by uuid

Decided 2026-10-27, closing the open uuid representation of [A
reference is an id with a list the site decides, listed or looked
up](#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up).
A content entity's id differs between environments: node 7 on a
development site is another node, or none, on the site its config is
imported into. A config entity's id is its machine name and deploys as
it is. So a reference key whose target is a content entity type, stored
by a config storage (`ConfigObjectStorage` or `ConfigEntityStorage`), is
stored as the entity's uuid; a config entity's stays its id.

- **The wire does not change.** The documents, the tool and HTTP take
  and answer ids and labels, `x-surface.reference` as before; only the
  stored config holds the uuid. A uuid on the wire would make every
  caller look an entity up twice, and the lookup and the selection
  handler answer ids.
- **A stock layout the engine applies.** `ReferenceStorageLayout`,
  applied by `StorageAdapter` to every resource whose storage is a
  config storage, outside the storage's own layout and in the layout
  values are asked for (as the secrets decorator works in it), so a
  storage class sees the uuid and its own layout sees the uuid too. It
  translates on load, uuid to id through the entity repository, and on
  prepare, id to uuid. It is the one layout that reads the site, since
  what a uuid names is site state; it reads it the same for every door.
  The build marks a key that refers to a content entity type
  (`References::CONTENT`), so the check and the generator know it
  without a service.
- **Where.** Wherever a config storage keeps a key: at the top, in an
  attached structure or a map, in each item of a collection, in the
  variant a deciding key chose among those a class declares, and in a
  module's mount. Variants the site lists are stored open (`type:
  ignore`), and keep the id. A child with storage of its own translates
  its own.
- **The bare `entity` key: one shape, `{entity_type, id}`, two strings.**
  `id` holds the uuid where `entity_type` names a content entity type
  and the id where it names a config entity type. The stored value
  carries its entity type, so it says which `id` holds, by the same rule
  as a key of one entity type, and the generated schema states it as it
  already did, two strings. Rejected: `{entity_type, uuid}` for content
  beside `{entity_type, id}` for config, a key that changes its name
  with its value, which a schema can state only by making both
  optional; and a uuid for every entity type, which stores a config
  entity by a uuid that differs on each site that installs it from a
  module, against core's own naming of config by its name.
- **The schema.** A key that refers to one content entity type is typed
  `uuid`, core's type with its `Uuid` constraint, `nullable` by the
  three-way rule; the generator writes it so, and the check skips it
  ([A layout is the storage's one word on its
  shape](#a-layout-is-the-storages-one-word-on-its-shape)).
- **A uuid that names nothing reads as nothing stored**, a bare key's
  two parts both, and the stale rule applies: a write that holds nothing
  for it keeps the stored uuid, as an untouched stale value is kept, and
  a new choice replaces it. So config deployed before its content keeps
  its references through a save made before the content arrives. An id
  stored before this rule that names nothing is not kept: it names
  nothing on any site.
- **Ids stored before.** A stored value that is not a uuid is read as an
  id while that entity exists, and written as its uuid at the next
  write. It hides nothing: the uuid type's `Uuid` constraint refuses it,
  so config validation names each one left. A module converts them in a
  post-update with `surface_store_references_by_uuid()`, which
  reads and writes the object through its storage; example 16's is
  `surface_examples_references_post_update_references_by_uuid()`.
- **Opting out.** `->setSetting(References::STORED_AS,
  References::BY_ID)` on the key, or on a bare key itself, keeps the id
  in config. Right only for config that never leaves its site, or where
  each site gives the entity the same id by other means (a migration
  that keeps ids); the reference then deploys as wrong as an id does.
- **Dependencies.** A config entity saved through `ConfigEntityStorage`
  depends on what its keys refer to, as core's own config entities do: a
  config entity by its config name under `config`, a content entity as
  `<entity type>:<bundle>:<uuid>` under `content`. `StorageAdapter`
  adds them to the record the storage rehearsed, so a dry run shows
  them, and `commit()` saves through `ReferenceDependencies::during()`,
  whose presave hook adds them after core recalculated the entity's own
  (core invokes `hook_entity_presave()` after `preSave()`). Rejected:
  enforced dependencies, which core keeps through recalculation but
  never drops, so a reference changed would leave its old dependency
  behind. A simple config object records no dependencies in core, and
  this module does not invent a record; it is a gap
  ([core gaps](core-gaps.md#smaller-gaps-outside-the-sequence)).

*Amended 2026-10-27:*

- **An entry from the site is translated.** In a map of keys from the
  site, each entry of `addEach()` holding a content reference, and each
  reference inside the structure `attachEach()` attaches at every entry,
  is stored by uuid, kept when it names nothing, and depended on, as any
  other place a config storage keeps one. Its translated path names the
  entry `*` (`pages.*`, `authors.*.author`), which the check and the
  generator read
  ([A layout is the storage's one word on its shape](#a-layout-is-the-storages-one-word-on-its-shape)).
- **An install file that holds an id is read by the rule for ids stored
  before.** A module's `config/install` file is written by hand, and the
  generator keeps what it says for a key stored by uuid. Installed as
  written, an id there is read as the id while its entity exists and
  stored as the uuid at the next write, as an id stored before this
  decision is. Nothing new was built for it; `ReferenceStorageTest`
  pins it. The generator does not convert install values to uuids: a
  content entity named by an install file is site state the module
  cannot know.

*Open:* a save of the config entity made another way (core's form,
`$entity->save()`) recalculates without the reference dependencies until
the next save through the storage; the entity class's own
`calculateDependencies()` is where core expects them. A config entity
resource has no conversion helper for ids stored before. A site's
default for such a key (`surface.defaults`, [A site sets a default
in configuration](#a-site-sets-a-default-in-configuration)) is a value
as every door sends it, an id, written over the declared default and
never through the storage's layout, so it deploys as an id does;
resolving a uuid there would make the build depend on whether the
entity is on the site yet.
(`ReferenceStorageLayout`, `References::storedByUuid()`,
`References::stored()`, `ReferenceDependencies`,
`ReferenceDependencyHooks`, `StorageAdapter::referenceLayout()`,
`surface_store_references_by_uuid()`, `ReferenceStorageTest`,
`ReferenceStoredByUuidTest`)

## Access

### Access has three answers

Access answers open, locked or omitted (`Resource\AccessAnswer`, a value
object carrying cacheability like an access result), because field
access has a middle: a field one may see and not change. An access class
may still return core's `AccessResultInterface`, and the interface's
return type is the union, so every class written before keeps
implementing it unchanged. Core's answer is read as allowed is open and
forbidden is omitted at a child, denied at the top; neutral leaves a
child open, since its parent's access already let the caller in and a
child's access only narrows what the parent allowed, and is a refusal
at the top ([Neutral is a refusal for an operation's
caller](#neutral-is-a-refusal-for-an-operations-caller)). At a child, the
permission of an operation the build resolved comes first (lacking it
omits the child), then the access class, asked however the child was
invoked ([One invocation model for both
levels](#one-invocation-model-for-both-levels)), and the narrower answer
stands. Locked is the existing lock on the parent's key, so no
door needs a second mechanism. The answer's cacheability is added to
the parent's builder, so the contract and the form carry
`user.permissions` when the answer read permissions. That cacheability
also says which of two kinds the answer is, static (the account alone
decides it) or runtime (the subject or site state does), which decides
where it is stated: [Access is static or runtime, read from its
cacheability](#access-is-static-or-runtime-read-from-its-cacheability).
Another module's alter may answer as well, after the owner, the stricter
standing: [An alter may answer access, and the stricter
stands](#an-alter-may-answer-access-and-the-stricter-stands).
(`AccessAnswer`, `Gating::childAccess()`)

### Access is static or runtime, read from its cacheability

Access is split the way refinement is
([Static by default, runtime by declaration](#static-by-default-runtime-by-declaration)):
what the account alone decides is stated ahead of time, for the account
that asks; what only the subject decides is marked, and settled once it
is known. Decided 2026-10-18, point by point.

- **Two kinds, read and never declared.** An answer is static when its
  cacheability names nothing but the account's permissions or roles:
  every cache context is `user.permissions`, `user.roles` or
  `user.roles:<role>`, it carries no cache tag, and its max-age is
  permanent. Anything else is runtime: an entity's or a config object's
  tag, the `user` context, max-age 0 for a State read
  (`AccessAnswer::kindOf()`). Core's results and these answers are read
  alike. Why: Drupal already requires an access result's cacheability to
  be honest, since the render and page caches are keyed by it, so the
  fact is there to read; a declared kind would be a second statement of
  it, free to drift from what the answer really varies by.
- **An answer with nothing is runtime.** An answer an access class
  returns with no cacheability at all (`AccessResult::allowed()`, a bare
  `AccessAnswer::open()`) promised nothing, and is read as runtime
  whatever is added to it afterwards (`AccessAnswer::fromAnswer()`). For
  a cache, nothing means "the same for everyone, forever"; for an access
  class it was mostly an omission (`RecipeAccess` reads its context and
  says nothing), and reading it as static would state in a cached
  listing what may not hold, where reading it as runtime costs a marker.
  The honest spelling of a permission check reads static:
  `AccessAnswer::locked()->addCacheContexts(['user.permissions'])`, or
  core's `AccessResult::allowedIfHasPermission()`. An answer the build
  makes itself from nothing (a child whose only operation permission is
  the one its parent's gate asked) is static: it read nothing.
- **Combined, the narrower stands**, static only when both are, except
  that a static omission settles it: nothing is narrower than left out,
  so what the other side read cannot change it, and the combination
  depends on that side alone, as core's `andIf()` reads a forbidden
  result. A static lock over a runtime answer is runtime: it may still
  be left out (`AccessAnswer::andIf()`).
- **Enforced in three layers, as refiners are.** At build:
  `ResourcesInterface::buildStatic()` builds for the account alone, each
  static answer applied and each runtime one asked and set aside, its
  key kept as the static answers leave it and marked
  (`DefinitionMap::isRuntimeAccess()`); `build()` applies both. A static
  answer is asked once per context and account kind (the account's roles
  and its permissions hash) and kept, so a class that lied is wrong
  visibly rather than now and then (`Gating::ask()`); the latest 256
  are kept, since a context knows its identity and a worker asking for
  one entity after another would otherwise keep one per entity. Under
  `assert()`: the first asking of a static answer is repeated with the
  global container guarded and must answer the same, static, having
  asked for no service that reads site state beside the account
  (`PipelineGuard::guardAccess()`, `Gating::answersAgain()`).
  The refiner's guard is reused with another rule for what passes: a
  refiner may reach nothing but the typed data manager, while an access
  class is a service with injected collaborators and core's handlers
  reach the container lazily for theirs (the module handler, the entity
  field manager), so an allowlist would refuse honest answers; the
  guard refuses the services that read site state instead (State and
  key-value stores, configuration, the current user, the time, the
  request and route, the database). A State read through a service the
  class was built with passes, as a refiner's static property does.
  Agreement: `StaticAccessAgreementTrait` holds, for sampled accounts,
  the full schema to the build it came from, and every key it states
  without the runtime mark to what `build()` applies in each sampled
  situation (a stored value changed, a State flag set).
- **Stated for the account that asks.** The full schema is emitted from
  `buildStatic()`: a key a static answer locked is `readOnly`, one it
  left out is not there, as the schema for these answers always had
  them; a key a runtime answer may lock or leave out is there, as the
  static answers leave it, marked `x-surface.live` (`access: "runtime"`
  until [contract 2](#contract-2), `access: "live"` until
  [contract 4](#contract-4-fewer-keywords-nothing-new-on-the-wire)),
  and the schema for these answers, from
  `build()`, settles it and marks nothing. So the full schema is
  account-dependent only through static answers: its cacheability
  carries what they read and the cache contexts of the answers set
  aside (which chose their kind), never those answers' tags or max-age.
  The schema route serves it so; a child with a marked key inside is
  described where it sits rather than as a bare reference to its def.
  The tool listing does the same: the derived definitions are built from
  `buildStatic()`, and a tool instance lists `values` again for the
  account that asks (`ResourceOperationTool::create()`,
  `OperationInputs::listedValues()`), a static lock as a locked
  property, a static omission left out, and a runtime key's description
  saying that a call may find it locked or left out. When the runtime
  answer is the resource's own, at the top, every key carries the mark,
  and the tool's description says it once instead, "Every key may be
  found locked or left out once the thing is known.", and no key repeats
  it; a key whose mark is its own (a child's access, a mixin's) keeps
  its note (decided 2026-10-21, from the fourth fresh-eyes log, entry 18,
  where the same sentence on fourteen keys buried what each key said of
  itself; `SurfaceInterface::isRuntimeAccessWhole()`,
  `isRuntimeAccessOwn()`). The tool's description is cached for every
  account, so whether to say it is asked of the answers at the top for
  the anonymous account alone, without a build
  (`ResourcesInterface::isRuntimeAtTheTop()`): an answer at the top that is
  static for it (the tool test module's cabinet) says nothing, and a key
  in the listing an account asks for drops its note only where the
  description says it, so no account loses the statement. *Open:* an
  answer that is static for the anonymous account and runtime for
  another (the exhibit's) keeps its notes on each key for that account,
  and one runtime for the anonymous account and static for another says
  in the description what that account's listing does not mark. The description,
  not a keyword: the Tool API's normalizer writes JSON Schema from typed
  definitions, which carry a description and no extension keyword. Why:
  a full schema and a listing are what clients cache, so they can only
  hold what the subject cannot change; an account's own permissions are
  that, and anything read from the subject would be stale once the
  subject changes. The derived definitions themselves, cached for every
  account, are built for no account (*changed 2026-10-20*: they were the
  discovering account's; [A static tool definition is no
  account's](#a-static-tool-definition-is-no-accounts)).
- **Narrowing by account is a runtime refiner, not access.** Access
  answers whether keys may be changed, seen, or neither; which of its
  values a key allows for an account is the key's own definition,
  narrowed by a refiner that reads the account and so says `runtime:
  TRUE` (`ExhibitResource::roomsForAccount()`: only an account that may
  use the vault is offered it). Access has three answers by design and
  cannot say "fewer values"; a refiner can, and the full schema already
  keeps such a key wide and marked `live`.
- **The operation's permission is the first static answer.** One
  permission as written depends on the account alone, so it is static
  (`OperationDefinition::staticPermission()`); one with a `%key`
  placeholder names the subject's permission, filled from what the
  context knows, and is runtime. The same rule makes the schema route
  refuse an account the operation is not open to (403) and a tool declare
  its permission to the Tool API, which a listing filters by
  (`ResourceOperationToolDeriver::declaredPermission()`, which also
  leaves out a `,` or a `+`, the Tool API's own syntax); the read tool
  declares the same one. No second mechanism: the permission is asked
  where it always was, and only where it is stated follows from its
  kind.
- **Children, variants, mixins and ports.** An attached structure's
  access answers in its own frame, its kind with it. A mixed-in
  structure's access answers for its members in the owner's frame, the
  stricter of the owner's and its own standing ([A mixin is merged
  flat](#a-mixin-is-merged-flat)); set aside, each member is marked, and
  a mark from a structure it mixes in itself is carried through. A
  variant whose answer is runtime is offered in the build for the
  account alone and the key it sits at marked, since it may be withheld once the
  subject is known; a variant chosen by a locked deciding key still
  refuses the whole parent at the top, asked with the subject ([A chosen
  variant may refuse](#a-chosen-variant-may-refuse)). A port carries no
  access. The kinds compose by frame: a static lock on a parent over a
  runtime answer in its child leaves the parent's keys locked by the
  account alone and the child's key locked with them and marked; a
  runtime parent over a static child is marked, and the child's static
  answers are applied inside it.

- **The existing access classes say theirs.** The test module's spice
  rack, ownership and archive read permissions and say so: static.
  `FieldItemAccess` carries core's field access answers: one that read
  the entity is runtime, one that read permissions static, and one core
  answered with nothing at all (no hook restricts the field), the same
  for every account, says `user.permissions`, the widest the account
  alone varies by. A context naming no field, as a tool's static
  definition for an operation that needs a subject is built in, asks
  nothing and is runtime, so an entity's update tool lists every field
  marked, where it listed them open before. The classes asked only at
  the top (`NodeTypeAccess`, `FieldInstanceAccess`,
  `ContentEntityAccess`, the test module's recipe) answer whether the
  caller gets in at all, which nothing ahead of time states beyond the
  operation's permission, so their kind changes nothing.

The fixture is the test module's exhibit: one access class
(`ExhibitAccess`) on the provenance it mixes in and the loan it
attaches, locking for an account that may inspect it and leaving out
for an archivist (static), locking once the exhibit is sealed and
leaving out once it is withdrawn (runtime, a stored value and a State
flag); the loan's insurance is runtime for everyone
(`StaticRuntimeAccessTest`). (`AccessAnswer`, `Resources::buildStatic()`,
`Gating::childAccess()`, `ContractEmitter::frame()`,
`InputDefinitions::runtimeAccessNote()`)

*Changed 2026-10-24, with
[contract 4](#contract-4-fewer-keywords-nothing-new-on-the-wire):* the
mark is `x-surface.live`, the runtime refiner's, and no longer
`access: "live"`. A client does the same for both: the key's final
state is per request, the schema for these answers settles it, and
nothing about it is cached. The two kinds stay apart where a person
reads: the tool listing's description of such a key still says
"Whether this may be changed is decided once the thing it belongs to is
known: a call may find it locked or left out.", and the build still
tells them apart (`Entry::$runtimeAccess`, `Entry::isRefinedAtRuntime()`).

### An alter may answer access, and the stricter stands

Until this, a resource or structure had exactly one access answer, the
class its attribute names, beside the operation's permission, and
another module's alter could add keys and rules but no answer. So a
workflow, group or field permissions module could not lock or leave out
keys of a resource it does not own unless that resource was a content
entity, whose access class asks core's handlers. Decided 2026-10-20,
closing that gap (found 2026-10-19), point by point.

- **The spelling.** An `#[Alters]` class implements
  `OperationAccessInterface`, the access class's own interface (*changed
  2026-10-24:* it was `AltersAccessInterface`, the same method under a
  second name; the role, owner or alter, is what `#[Alters]` on the class
  says, and an access class named on `access:` that carries `#[Alters]`
  is refused at discovery, since it would answer in both roles; [The surface was counted and cut](#the-surface-was-counted-and-cut)),
  whose `access(OperationContext $context,
  AccountInterface $account): AccessAnswer|AccessResultInterface` is an
  access class's method, on the alter. One spelling, an instance method,
  as an access class has: an alter is an autowired service, so it may
  hold what it reads the subject with (State, configuration), and a
  static method could read the subject only through the global
  container. A static spelling beside it would also be a second way to
  say what the answer's cacheability already says, and would read as
  "a static answer", which is the cacheability's to decide. Implementing
  the interface is what makes an alter answer, as implementing
  `AltersInputsInterface` makes it add; nothing is declared beside it.
  An alter of a shape that implements it is refused at discovery: a
  shape is one value with no access of its own. An alter that adds,
  narrows and answers nothing is not refused, as before; only a
  shape's alter must add.
- **Where it is asked.** For the resource or structure the alter names,
  in the frame where that thing is built, wherever it appears: at the
  top, where locked builds it locked whole and omitted refuses the
  caller; as an attached child; as a variant, offered, offered
  read only or not offered, and refusing its parent as a chosen variant
  may ([A chosen variant may refuse](#a-chosen-variant-may-refuse)); a
  mixed-in structure's members, held in the owner's frame; a
  collection's item, once for the list; every entry of keys from the
  site. A structure attached twice, or by two resources, gets the
  alter's answer at each site. It applies in the operations its
  `#[Alters]` names, as the rest of the alter does.
- **Composition: the stricter stands.** The operation's permission,
  then the owner's access class, then each alter's answer in discovery
  order, combined with `AccessAnswer::andIf()`. Two reasons. Two answers
  about the same keys already combine this way: a child's operation
  permission and its access class, a mixed-in structure's access and its
  owner's ([A mixin is merged flat](#a-mixin-is-merged-flat)). And an
  answer applies wherever the thing appears, the field permissions
  rationale: access to a field is the field's, on every entity form and
  through every door it is reached by, so a module answering for a
  structure answers for it at every site. So an alter can lock or leave
  out the keys of what it alters (its own mounted keys and a structure
  it attached at its mount among them), and can never open what the
  owner or an earlier alter locked or left out: an alter answering open,
  or core's neutral, changes nothing (the narrowing invariant). At the
  top an alter's answer is read as a child's is, neutral as open, then
  as one asked on its own, omitted as a refusal, and combined with
  core's `andIf()`, which keeps a refusal. The order matters only for
  the reason a combined answer carries: of two answers as narrow, the
  earlier one's.
- **The operation's permission stays the owner's.** The tool declares
  it and the schema route asks it, as before. An alter that wants a
  further permission answers locked or omitted for an account without
  it, with the `user.permissions` context, which reads static: stated in
  the full schema and the tool listing for that account.
- **Each answer keeps its kind.** Built for the account alone
  (`buildStatic()`), each answer is applied or set aside by its own
  cacheability: the static ones combine and apply, and each runtime one
  is set aside and the key marked. A static lock from the owner beside a
  runtime answer from an alter leaves the key locked and marked, where
  the two combined first and read once would have been runtime and set
  aside whole, taking the static lock with them. A static omission
  settles it: nothing set aside could narrow it further, so nothing is
  marked. An alter's static answer is kept per class, context and
  account kind, and asked again guarded under `assert()`, as an access
  class's is; `StaticAccessAgreementTrait` holds the full schema to the
  build with alters answering, as it does without.
- **At the top, set aside marks every key.** A runtime answer set aside
  at the top, an access class's or an alter's, now marks every key of
  the resource (`x-surface.live` in the full schema, the
  words in the tool listing), since the whole of it may be found locked
  once the subject is known. *Changed with this decision: until then
  nothing was marked, and the full schema stated open keys the build
  would lock, which the agreement trait found on example 10 with its
  workflow module.* (`SurfaceBuilderInterface::markRuntimeAccessWhole()`)
- **Read by the wall.** Like any access answer, an alter's is the
  site's, never the def's: the def stays the widest reading of the
  child ([The wall is the reference
  boundary](#the-wall-is-the-reference-boundary)). Nothing of an owner's
  may name a member of a mixed-in structure an alter answers for, as for
  one with an access class: what the answer takes away for one account
  nothing may depend on for every account.

The fixture is the test module's gallery, which names no access class
but its wall's: alters answer for the gallery (in its curating
operation), the plaque it mixes in, the wall it attaches twice and the
annex once, the oil finish one of its variants is, and the room
at every entry of its keys from the site; one more alter tries to open
the wall (`AlterAccessTest`, `AlterAccessFormTest`). The first case on
a site is example 10's workflow module, `surface_examples_workflow`.
*Open:* an alter cannot answer for one key of its owner, since an answer
is about a resource or structure, and a key that needs an answer of its
own is a structure, as a field is; a structure at a mount that an
alter answers locked or left out is refused, as one whose access class
does ([An alter attaches at its own mount](#an-alter-attaches-at-its-own-mount));
a reason an alter gives at a child is not shown, as no child's is.
(`OperationAccessInterface`, `AlterDefinition::$answersAccess`, `ResourceRegistry::assertAccessClass()`,
`ResourceDefinition::answersAccess()`, `Gating::answering()`,
`Gating::answers()`, `Gating::alteredAtTheTop()`,
`Gating::lockedAtTheTop()`)

### A gate is borrowed, never copied

Decided 2026-10-25, from the owner's case: a module ships a resource of
its own, with its own keys, storage and permission, that acts on things
another module owns and must respect the access their owner and every
other module decide, without naming them. A bulk retitle may retitle
only the nodes the account may update and whose title it may edit.
`gate:` on `#[Operation]` names that access, and the module asks it.

- **Borrowed, not copied.** Who may change a node is decided by node's
  access handler and by every module that implements
  `hook_entity_access()`, `hook_node_access()`, node grants or
  `hook_entity_field_access()`: a workflow, a group, field permissions.
  A resource that copied the check ("has edit any page content") would
  agree with that answer only until one of those modules is enabled or
  changes, and would then let through what the node's own form refuses.
  A gate asks the owner each time, so it holds whatever the site adds.
- **Two forms.** Another resource's operation by id,
  `entity.content:node.page:update`, answered as that operation is when
  it is asked on its own: its permission, its gates, its access class
  and every alter answering access for it, by `andIf()`. And core's
  access on an entity or a field, `entity:node:update` and
  `field:node.title:edit`, through the entity type's access handler.
  Core's form is there because entity access is what every module
  already extends, and because it needs no derived resource for the
  entity type: the gate works on a site without `surface_examples_entity`.
  Field access does not include entity access, as core's entity form
  asks both, so a list may name both, and each is answered by itself.
- **The subject is what the context hands, or each item's.** A gate is
  asked about the entity an identity key refers to, or the
  `entity_type` and `id` a content entity resource knows. Where none
  does and an item of a collection has a key referring to one, the gate
  is asked per item, because a bulk call is many subjects: the items do
  not share one answer, so a refusal is filed at that item's key, with
  the gate's reason, and the other items carry no refusal. A collection
  is otherwise asked access once ([Access once per list](#access-once-per-list));
  the gate is per item because its answer is about the item's entity,
  which differs per item, where a child's access is about its keys,
  which do not. Per item, the gates are asked after validation and
  before the storage's `prepare()` (`ItemGates`, from
  `StorageAdapter::prepare()`), so a storage sees only items every gate
  allowed, and a refused item refuses the call, as any refusal does. A
  gate whose subject nothing names refuses: it is never let through
  unasked.
- **Narrow only.** The operation's own permission is asked first, and is
  static; then the gates; then the resource's own access class and its
  alters, combined with `andIf()`. The resource can refuse what a gate
  allows, never allow what a gate refuses.
- **Its kind is its cacheability.** At the top, each gate's answer is
  static or runtime as any access answer is
  ([Access is static or runtime, read from its cacheability](#access-is-static-or-runtime-read-from-its-cacheability)):
  core's update access for an account with "edit any page content" read
  the account's permissions alone, so it is static and stated for that
  account; one that read the entity is runtime and marks every key. A
  gate asked per item cannot be stated ahead, since its subject is a
  value: the full schema marks the collection `x-surface.live`, and the
  tool listing says on it, in the existing sentence, that it is decided
  once the thing is known. The listing declares the operation's own
  permission alone.
- **Refused at discovery.** A gate naming a resource, an operation or an
  entity type the site does not have, or a field operation other than
  view or edit, is refused when discovery reads it, naming it
  (`ResourceRegistry::assertGates()`). Which fields an entity has is
  known only once one is asked about, so a missing field refuses then.

The worked example is example 17, `ai.retitle`; the fixture is the gate
test module's notes (`GatesTest`). *Open:* a gate on a child's
operation is not asked, since gates are asked where the operation is
the one called; the tool listing's sentence for a list a gate is asked
of says "locked or left out", where a gate refuses the item; and a gate
is asked per item for the collections at the top only, not inside an
item. (`Gate`, `Gates`, `ItemGates`, `Gating::gatePlaces()`,
`Gating::gatesAtTheTop()`, `Gating::gatesAside()`)

### A permission a child cannot name

A child operation's permission whose `%key` the child's context cannot
fill is a refusal, as at the top ([An unknown
placeholder](#an-unknown-placeholder)): the child is left out. Except
in a static listing (`buildStatic()`, `buildDeclared()`), the one build
that knows too little on purpose, before any subject: there the child
is described as it may be, and the per call definition, built in the
real context, applies the permission. *Changed 2026-10-20 (B26): it
said nothing at any child, on the reading that the parent's access had
gated the caller; that left a child one author's mistake away from
being let through unasked, and a build that knows too little anywhere
else is that mistake ([Every door projects what the read tool
projects](#every-door-projects-what-the-read-tool-projects)).*
Adding a field invokes its storage's `create`, whose permission names the
entity type the field's context knows; a bare context knows none. For
the same reason a child operation's permission that resolves to the very
permission its parent's operation asks says nothing either: the parent's
gate asked it already, and a child narrows only what the parent allowed.
That is the storage's case when the field's context does know the
entity type, so a field is built with its storage for any account, as
it always was, and only the field's own access decides.
(`Gating::childAccess()`)

### Omitted is omitted both ways

An omitted child is not in the data surface, so it is in no contract,
no form and no tool input, and a value posted for it is an unknown key,
which the pipeline already refuses. It is also not read: the read tool
leaves it out, because a caller who may not see a thing should not
learn it through the other door, and no door answers it ([Every door
projects what the read tool
projects](#every-door-projects-what-the-read-tool-projects)). And it is
not written: the storage adapter hands what is stored under the key
back to the parent's storage, which may store its values whole and
would otherwise drop what the caller never saw; which is why what
storage rehearsed is never served. (`SurfaceBuilder::omit()`,
`StorageAdapter::prepare()`, `Pipeline\Outbound::declared()`)

### Every door projects what the read tool projects

Decided 2026-10-20, from the security review (B1, B2, B3, B4, B5 and
B26 of the reviews' synthesis). One rule: whatever a door hands a
caller of a resource's values is what the read tool would hand that
caller of them, never more. The projection is the read tool's, now one
function of the built data surface (`Pipeline\Outbound::project()`,
`SurfacePipelineInterface::outbound()`): a key the build left out
for the caller is not there, at any depth (a child the parent stores
whole included, which only the read's top level left out before); a
secret is not there, at any depth, under every variant that
keeps it secret and inside each item of a list; a key that does not
apply to the values is not there; a locked key is there as stored,
which the accepted values hold for it. It applies to the read tool's
`values`, the write tool's `values` on a dry run and on a write, React
`/validate`'s `values` (projected, then written as a document), and the
contract `/submit` rebuilds, which the emitter already masked; the
form draws no omitted key and never fills a secret's box. Why: the
read tool's filter was the only one, so a caller who could not read a
key through the read could read it through a dry run (the fees example
10 leaves out, a field a field permissions module hides), and a secret
came back from every write; "omitted is omitted both ways" and "a read
leaves a secret out" are promises about every door, or about none.

- **What is written, as a read after it loads it.** The write tool's
  `values`, on a write and a dry run, and React `/validate`'s and
  `/submit`'s, are projected from what the run writes, not from every
  key accepted: a key storage never held that the run leaves at its
  default is not written (absent keeps), so it is not answered either
  (`SurfaceResult::held()`). Decided 2026-10-21, from the fourth
  fresh-eyes log (entry 20): a write of one key answered a mounted key
  never sent, at its declared default, which the read tool right after
  did not. A refusal still answers the values as accepted, since nothing
  is written and the caller corrects what it sent.
- **`prepared` is not served.** What prepare rehearsed is storage's own
  shape, and the one answer no access rule read: storage puts back what
  the caller could not see so the write does not drop it, and a layout
  (third-party settings, an encrypted layout) is storage's to know. A
  dry run's caller needs the accepted document as it may see it, which
  is `values`, and whether storage's own checks passed, which is the
  run's success; so the write tool declares no `prepared` output and
  React's `/validate` answers no `prepared` key. It stays on
  `SurfaceResult` for PHP callers, who hold the storage anyway.
  Projecting it instead was weighed and refused: storage's shape is not
  the resource's, and a projection that must know every layout is one a
  new layout breaks.
- **A secret in a collection's item keeps its place's.** The read never
  returns an item's secret, so a caller sending back what it read sends
  none; accepted over the child's defaults, the item erased it. Now an
  item that leaves a secret out keeps what is stored at its position,
  as a key left out keeps what it holds, when the list sent is as long
  as the one stored: an edit in place, which is what a read, edit, write
  loop and a form's rows send. Every other key of the item is still the
  child's default when left out: the list is sent whole. With an item
  added or removed a position may hold another item, and positions are
  all a list has until items carry an identity, so a secret stored
  there is neither kept, which could hand one item's credential to
  another, nor erased: the run is refused by the secret's path
  ("Password is stored for the item at this position, ..."), and the
  caller sends the secret again or the clear marker. A list reordered at
  the same length is read as an edit in place; a caller that reorders
  items with secrets sends them again. *Open:* an item identity to match
  by.
- **A note says nothing of a secret.** A value sent for a locked key is
  noted when it is not what the key keeps; a secret inside a locked map
  or child was compared too, so a right guess went unnoted. Now anything
  sent for a secret is not taken and is noted, and the rest is compared
  without it.
- **The permission first, and one reason.** The React route's access
  check and the tool's access ask the operation's static permission
  from the resource and operation ids alone
  (`ResourcesInterface::permissionAccess()`), before a parameter is
  read, the subject loaded or anything built, and the tool does not
  refine its `values` (which reads what is stored) for an account the
  operation is not open to. A refusal past the permission gives one
  fixed reason, whether a parameter named nothing or the access class
  refused what it named, so the reason never says whether an id exists;
  the real one goes to the log. Only an account holding the operation's
  permission for the context the parameters name hears the access
  class's reason. Before, the reason was the exception text, an
  anonymous existence oracle for entity ids. At the tool door, an
  account the operation's own permission lets in, read off the tool's id
  with no `%key`, already tells an id that names something from one that
  does not by the reasons it hears, so it is told plainly: the call is
  let through and answers "Not found: ..." as input to correct, not
  "access denied", which an agent reads as final (decided 2026-10-20,
  B13; `ResourceOperationToolBase::notFound()`). Every other account
  still hears the one fixed reason. *Amended 2026-10-29:* the operation
  form's route asks in the same order, and both page doors, it and the
  React page, let an account the permission lets in through to a 404
  for a value of the URL that names nothing, in the request's own
  dispatch alone, through one gate (`OperationGate`; [A route may fill
  or fix what an operation asks
  for](#a-route-may-fill-or-fix-what-an-operation-asks-for)).
- **A refusal no input can change is access.** The write tool answers a
  permission refusal, an access refusal inside the pipeline and a
  resource locked whole in the Tool API's `Access` failure category,
  which no client reads as correctable, so it is not handed the input
  schema and invited to retry; a value refused stays `Input`, one
  violation per line ("path: sentence") after "The values were
  refused:" (decided 2026-10-20, B13).
- **What an option list read, it says.** A reference's options are the
  selection handler's referenceable entities, which core's handlers
  narrow by the account's permissions and, for a node (and a comment,
  through its node), by node grants. They now vary by
  `user.node_grants:view` beside `user.permissions` for those, by
  `user.permissions` for a term, a user and a media item, and by `user`
  for any other target type, whose handler may read the account itself
  (a file's offers what the account uploaded)
  (`ReferenceableOptions::CONTEXTS`). The full schema's cacheability
  carries them, so a cached schema is never another account's labels.
- **A permission nothing can name refuses.** A child operation's
  permission whose `%key` its context cannot fill leaves the child out
  outside a static listing, as it refuses at the top ([A permission a
  child cannot name](#a-permission-a-child-cannot-name)).

(`Pipeline\Outbound`, `SurfacePipeline::outbound()`,
`Accepting::acceptCollection()`, `Accepting::takes()`,
`ResourceOperationTool::doExecute()`, `ResourceApiController::validate()`,
`OperationInputs::stored()`, `Resources::permissionAccess()`,
`ServedOperationAccessCheck`, `ResourceOperationToolBase::checkAccess()`,
`ReferenceableOptions::CONTEXTS`, `SecurityProjectionTest`,
`ReferenceableCacheabilityTest`,
`AdvertisedSchemaAcceptanceTest::testSecretInCollectionItemKeepsItsPlace()`)

### One result at every door

Decided 2026-10-20, from the deterministic callers and AI agents
reviews (group C of the reviews' synthesis, approved by the owner). A
run is answered in one shape wherever it is asked, refused or not, and
every refusal carries a code a program branches on beside the sentence
a person reads. Before, the tool answered a refusal as one translated
sentence with no context, `notes` and `stale` as maps by path, the
React endpoints as lists of `{path, message}` with `valid` beside
them, and a refusal of the whole call under the pseudo-path `@access`:
three shapes for one result, and nothing to branch on but text that
moves with the site's language and its alters.

- **The shape.** `{ok, committed, changed, changes, values, violations,
  notes, stale}`, every key always present (`Pipeline\ResultDocument`,
  `SurfaceResult::document()`): whether nothing was refused, whether
  anything was written, whether the write moved a stored value, the
  paths it changed or a dry run would, the accepted values as the read
  tool would answer them, and three lists. A violation and a note are
  `{path, code, message}`, a stale reference `{path, code, message,
  stored}`; the message is plain text, rendered by the renderer the
  door chooses ([A refusal names what it depends on](#a-refusal-names-what-it-depends-on)):
  for a machine at the tool, keys by path and values as sent; for a
  person at React, which a person reads as a form, labels alone, as the
  form says it. The paths and the codes are the same at every door, so
  an agent calling React still has the machine's facts in `path` and
  `code` (decided 2026-10-20, the owner's follow-up: the renderer is
  the door's choice, `SurfaceResult::document(machine:)`, not the
  sentence's).
- **A code on every violation.** `Violation::$code`, required by its
  constructor: a constraint's Symfony code by the name its constraint
  gives it (`not_in_range`, `too_long`, `no_such_choice`), a
  constraint with no code named for itself in snake case, and the
  module's own for its own refusals (`required`, `does_not_apply`,
  `unknown_key`, `locked`, `cannot_clear`, `type_mismatch`,
  `wrong_variant`, `no_variant`, `secret_in_item`, `not_found`,
  `access`, `permission`, `conflict`, `no_schema`, `stale`, and since
  2026-10-23 `taken`). The table
  is in [semantics](semantics.md#codes). Why the name and not Symfony's
  UUID: the name stands for it one to one (`Constraint::getErrorName()`)
  and reads in a log and a transcript. The pipeline dropped the
  constraint's code until now.
- **No `@access`.** A refusal of the whole call is a violation with an
  empty path and its code, `access`, `permission`, `locked`,
  `not_found` or `conflict`; `SurfacePipelineInterface::ACCESS_VIOLATION_KEY`
  is gone. The pseudo-path was a path that named nothing, and the only
  way to tell a resource locked whole from a refusal by access; the
  code says which. A form files it as a form-level error, as before.
- **`notes` and `stale` are lists.** The same entries as `violations`,
  with codes: a value sent for a locked key and not taken is `locked`,
  a stored value kept that the key no longer offers is `stale`, and
  carries the value it keeps (left out for a secret).
- **Every path a JSON Pointer** (decided 2026-10-22, with
  [contract 3](#contract-3-the-operation-is-the-method)). `path` in a
  violation, a note and a stale entry, and each entry of `changes`, is a
  JSON Pointer, `/contact/email`, at every door: the tool's result and
  its text lines, Drush's JSON, the HTTP results and problem details.
  The whole call is the empty pointer, as it was the empty path. Until
  then they were dotted paths, `contact.email`. The form keeps its own
  naming, and a message names a key as its renderer does
  (`ResultDocument::pointer()`).
- **At each door.** The write tool's result carries the shape as its
  context, on a refusal as on a success (`drush tool:run --json` prints
  it as `outputs`), and declares each key as an output, a read only
  operation's too, whose `committed` and `changed` are false and
  `changes` empty; its text is one line per violation, "path:
  message", after "The values were refused:", a refusal of the whole
  call its message alone on its line, and a line per note after. The
  resource's own outputs follow the shape, never over one of its keys.
  HTTP answers it with its own `outputs`, `contract` and `created`
  after, and a refusal as problem details whose `errors`, `notes` and
  `stale` are its entries (until contract 3, `/validate` answered the
  shape alone and `/submit` refused with a 200).
  The read tool answers a read, `values` alone, and its refusals in the
  shape. The form reads the same violations and renders them for a
  person.
- **The one place it cannot.** The Tool API holds a call to its input
  definitions before the tool runs; through its runner (`drush
  tool:run`, MCP, the AI connector) such a refusal, a `values` sent
  null or a key of the wrong type, is answered by the Tool API itself,
  `{success, error, error_type}`, and the tool never sees it. Where the
  tool is the one answering, `execute()` called directly as a PHP
  caller, ECA or a page does, the same refusal is answered in the
  shape, each violation by its path in `values` with a code, `required`
  for a property left out.

(`Pipeline\Violation`, `Pipeline\ResultDocument`,
`SurfaceResult::document()`, `ResourceOperationTool::doExecute()`,
`ResourceOperationTool::execute()`, `ResourceOperationToolBase::refused()`,
`OperationInputs::outputs()`, `ResourceApiController::validate()`,
`ResourceApiController::submit()`, `ResultCodesTest`,
`ToolResultShapeTest`)

### Nothing an operation runs reads the request

Decided 2026-10-24. Every door asks one surface, so the form, the HTTP
API, the tool and a PHP caller give one answer for one document; the
one way to break that is code run on an operation's behalf reading what
only one door has. So nothing the build or the pipeline runs for an
operation reads the request, its route, the session, the form being
built, the theme a page renders in, or the current user: a runtime
refiner, an access class, static or runtime, an alter, a computed key's
deriver, an options resolver, a provider of keys or variants from the
site, a deriver of variants or resources, and a storage's `load()`,
`prepare()`, `commit()`, `prepareDelete()` and `delete()`. Everything an
operation needs comes through it: the parameters and identity its
context knows, the values, the account, and site state through services
whose answers carry their cacheability. The doors read the request:
that is their job ([No door-specific context](resources.md#no-door-specific-context)).

- **The account travels as an argument.** A door sets the account as the
  current user; the framework reads it once and hands it on. An access
  class has it in `access($context, $account)`, as before. Each storage
  write verb takes it, `prepare($context, $values, $account)`,
  `commit($context, $prepared, $account)`, `prepareDelete($context,
  $account)` and `delete($context, $prepared, $account)`: the account
  `ResourcesInterface::storage()` is given, or the current user. A
  runtime refiner whose last parameter is typed `AccountInterface` is
  handed it, and that parameter is no watched key; a static refiner that
  takes it is refused at build, since what an account may choose is a
  fact no schema states ahead of time. `load()` takes none: what is
  stored is no account's, and what a caller sees of it is the read
  tool's projection and the access answers'. Why an argument and not a
  read: a door that runs for another account than the session's (a
  queue worker, a tool called for a delegated account) hands one account
  to every piece, and nothing reads a second one behind it. Why not on
  the context: the context keys every build kept for the request and
  rides in a serialized form; an account on it would key them by user
  and serialize a session.
- **Enforced as static refiners are.** Under `assert()`, each such call
  runs with the global container replaced by `Pipeline\PipelineGuard`
  (the name was `StaticRefinerContainer` while it held static refiners
  alone), which refuses the door-specific services (`request_stack`,
  `current_route_match`, `router.request_context`, `session`,
  `session_manager`, `tempstore.private`, `form_builder`, `path.current`,
  `path_alias.manager`, `theme.manager`, `current_user`) when the class's
  own code asks for one, naming the service, the class, the method and
  why: "a door-specific service makes the doors disagree". Core's code
  it calls is core's: an entity's save reads the current user for its
  own hooks. A service injected when the class was built never passes
  through the container, so the instance's properties are looked through
  once for one (`PipelineGuard::holdsNothingDoorSpecific()`). Site state
  with cacheability passes; a static refiner and a deriver still reach
  nothing but the typed data manager (and the stock `MachineName`
  transliteration besides, *amended 2026-10-26*,
  [A machine name is a computed identity](#a-machine-name-is-a-computed-identity)),
  and a static access answer asked again nothing that reads site state. A declared rule is data the
  engine evaluates and runs no code of the author's, so it has nothing
  to guard. Before anything runs, the module's PHPStan rule
  (`DoorSpecificRule`) flags, in those classes, the `\Drupal` shortcuts
  to these services, a container's `get()` naming one, and a constructor
  parameter typed for one. Production compiles no assertion and pays
  nothing.
- **Held by a test over every door.** `DoorAgreementTrait` runs one
  document through the pipeline, the tool (`execute()`, dry), the HTTP
  submit (`?dryRun=true`) and the operation's form (drawn with the
  document, posted as a browser posts it, submitting dry) and holds them
  to one verdict, one accepted document, one refusal list by pointer and
  code and one set of notes. `ResourceTestBase::testEveryDoorAgrees`
  runs it for an adopter, and `EveryDoorAgreesTest` over every resource
  this repository ships and every fixture, with the documents the
  declaration generates and the examples' README calls. A door it
  cannot drive for a document is counted by kind (the Tool API's own
  shape check, a value no form posts, a key the form does not draw, a
  secret, a delete's confirmation, an operation not asked on its own, a
  resource with no storage, a subject the site there does not have), and
  the kinds are held to that list, so a new one fails.
- **What it found.** Four disagreements, fixed with it. The operation
  form said a refusal that storage's `prepare()` or the access answer
  made from its submit handler, which Form API refuses once validation
  is over (a placed block made again threw); it now runs the whole run
  dry in validation and says every refusal on its elements. A collection
  whose items hold variants (a placed block's visibility conditions) was
  read through the item's plain map, which knows none of the variants'
  keys, so an untouched save cleared them; it is read a level at a time.
  Refinement's first pass handed a refiner a value its key's declared
  definition refuses, so a refiner typed for a number threw on a word
  (`ComplianceStructure::stewardsForCapacity()`); such a value is now
  withheld before any refiner runs ([A refiner never sees an invalid
  sibling](#a-refiner-never-sees-an-invalid-sibling)). And the HTTP API
  writes a whole float as a JSON integer, `20` for `20.0`, which the
  comparison reads as JSON does.

*Open:* the listing form draws a boolean filter that holds nothing as a
checkbox left clear, so it cannot say "any" (the venues' `status`); the
plugin hosts' forms (`SurfaceHostFormTrait`, the example form) still
say a refusal from their submit handler; core's selection handler reads
the current user itself for a reference's options, which every door
sets alike. (`PipelineGuard`, `RefinesInputRefiner::invoke()`,
`StorageAdapter`, `ResourceStorageInterface`, `DeletableStorageInterface`,
`Tests\surface\PHPStan\DoorSpecificRule`, `PipelineGuardTest`,
`DoorAgreementTrait`, `EveryDoorAgreesTest`,
`ContentEntityStorageTest::testTheWriteIsMadeAsTheAccountItIsHanded()`)

### Locked at the top

A resource whose own access class answers locked, asked on its own, is
shown and fixed: the caller is let in, every key is held to what is
stored, and nothing is written. Decided 2026-10-19, replacing the
reading under which a locked answer at the top was a refusal.

- **Access.** `ResourcesInterface::access()` reads locked as allowed at
  the top, so the route, the form's floor, the read tool and the React
  page let the caller in, as for a locked child.
- **The build.** The build asks the access class itself, at the top, and
  a locked answer locks the resource whole (`lockWhole()`): every key is
  held to what is stored, as `hold()` holds one, after the variants, so
  a variant is chosen by what its deciding key holds and not preselected by
  its default; the sealed data surface says why
  (`SurfaceInterface::lockedWhole()`). A class whose `access()` is
  declared to return core's answer alone cannot answer locked, so it is
  not asked here and nothing changes for it; an alter that answers
  access is asked after it, the stricter standing. Built for the account
  alone, a runtime answer is set aside, as a child's is, and every key
  is marked, since the whole may be found locked: the call settles it
  (*changed 2026-10-20: nothing was marked; [An alter may answer
  access](#an-alter-may-answer-access-and-the-stricter-stands)*).
- **The doors.** The form draws every element disabled, says why above
  them and offers no Save. The pipeline refuses a submit before storage
  is loaded, by name ("This may be seen and not changed, so nothing was
  written: ..."), a violation of the whole call with the code `locked`
  (until 2026-10-20 under `@access`), so the form, the write tool and the
  React `/submit` and `/validate` refuse alike, the write tool in the
  Tool API's `Access` category, since no other values would be taken
  (2026-10-20, B13); the read tool answers.
  Both documents say `readOnly: true` at the top (beside
  `x-surface.locked: true` until
  [contract 4](#contract-4-fewer-keywords-nothing-new-on-the-wire)),
  beside every key `readOnly`, and the React page offers no
  Submit for such a document.

Why refused at the pipeline rather than at access: locked is the
answer that lets the caller see, so access cannot be the refusal; and a
submit written back unchanged would report a write that did nothing.
(`Gating::lockedAtTheTop()`, `Gating::atTheTop()`,
`SurfacePipeline::submit()`, `DocumentHead::lockedWhole()`,
`LockedAccessTest`, `LockedAccessFormTest`)

**A lock says its own reason, once** (decided 2026-10-20, from the Form
API review, B16 of the reviews' synthesis). A key an access answer
locked carries the answer's reason, recorded with the lock
(`SurfaceBuilderInterface::lock()`, `Entry::$lockedBecause`), and
the form prints that reason beside the fixed element, as it prints a
locked variant's; "Fixed for this operation." is left for a lock with
no reason, an operation's (an identity key) or an answer that gave
none. A form locked whole says its reason once, above the elements, and
no element repeats it or carries a required marker: nothing can be
sent, so nothing is asked for. An operation that changes nothing has
nothing to save either, so its form's button is the operation's label
("Check a program"), not "Save". (`Form\Drawing::describeLocked()`,
`Form\Drawing::withoutRequired()`,
`SurfaceOperationForm::buildForm()`, `LockReasonFormTest`)

**A locked key inside an open write is said, not refused** (decided
2026-10-19, from the third fresh-eyes log, entry 22). A key locked one
at a time (an identity key the operation knows, an entry of keys from
the site its provider locks, a key a locked variant or a locked child
holds) keeps what it holds whatever is sent for it, as the never
stricter audit settled: a posted value is a notation of the document,
not a refusal. A caller whose value did not land learned nothing, so
the run notes it: one note per key whose value sent is not what it
keeps, read as though the key were open (a form's `"0"` for a stored
`FALSE`, or a map sent with fewer keys, each as held, is what it keeps
and says nothing), "<Label> is locked and kept as stored; the value
sent was not taken." The notes block nothing and are no violation
(`SurfaceResult::$notes`): the write tool puts each on a line of
its own after its message, by its full path, and every door lists them
under `notes` beside `stale`, `{path, code, message}`, code `locked`
([One result at every door](#one-result-at-every-door)). The form
needs none: it draws the key disabled, and posts what it holds. A
resource locked whole is refused above, not noted.
(`Accepting::acceptKey()`, `Accepting::takes()`,
`ResultReportingTrait::withNotes()`, `LockedAccessTest`,
`KeysFromSiteTest`, `ServedSubmitEndpointTest`)

### A variant is open, locked or not offered

A variant invoked through an operation whose access omits it is
dropped from the variants, so its deciding value can no longer be chosen,
and no derived variant is put in its place. A variant its access locks
is offered read only (decided 2026-10-19, replacing "not offered"): it
is built locked whole, every key held to what is stored, so it can be
chosen and is drawn fixed. Chosen anew, it starts from its own
defaults, as any variant chosen anew does, and holds them. Its keys are
`readOnly` where the variants are stated, the form
draws its fieldset disabled, and a value posted for it is kept as
stored. A locked variant chosen by a locked deciding key no longer
refuses its parent ([A chosen variant may
refuse](#a-chosen-variant-may-refuse)): the parent is written, the
variant's keys kept. (`Resources::buildResource()`,
`SurfaceFormBuilder::buildSurfaceForm()`)

### A chosen variant may refuse

When the deciding key is locked, the variant is chosen by what the
parent is, as a field's settings are chosen by its type, and the parent
cannot be written without that variant. Its access class, asked in the
context it is invoked in however it was invoked, may refuse the whole
parent, never allow; a locked answer is no refusal, since the variant is
then built locked and kept. The build leaves an omitted variant out of
the variants ([A variant is open, locked or not
offered](#a-variant-is-open-locked-or-not-offered)), so it is looked up
among the declared variants. A field type's settings refuse the field
they belong to this way, and nothing below a parent can open what its
own access closed. Every other child's access decided its own keys when
it was built ([One invocation model for both
levels](#one-invocation-model-for-both-levels)) and refuses nothing
above it: a field its access hides is left out of its entity, which is
not a reason to refuse the entity. This replaces an earlier rule under
which any child invoking no operation of its own could refuse its
parent; it was asked nowhere else, and now that its access answers at
build, refusing the parent as well would turn a hidden field into a
refused form. Since only such a variant may refuse, `access()` builds the
parent to find its variants only when some declared variant names an
access class, not whenever anything does (decided 2026-10-19), and only
when that variant is the parent's own (decided 2026-10-20, from the
performance review): one of its keys holds it, or a structure the parent
holds at any depth does, found by declaring each one's keys into a
builder that is never sealed, nothing refined and nothing asked. A
variant that answers access elsewhere on the site costs every other
resource nothing, and where none does (the index lists the holders),
nothing is declared at all.
(`Resources::access()`, `Gating::childAnswers()`,
`Gating::childrenMayAnswer()`, `VariantsOfThisResourceTest`)

### No declared operation, no permission tier

A context whose id names no declared operation (a plugin host's
`configure`) has no permission tier: the access class alone answers, or
neutral when there is none. The host already checked the permission
that guards its own form. (`Gating::ownAccess()`)

### An unknown placeholder

A `%key` in an operation's permission that the context does not know
makes access forbidden. The permission cannot be named, and guessing
one would grant or refuse the wrong thing. (`Gating::ownAccess()`)

### Neutral is a refusal for an operation's caller

A route or tool addressing an operation reads a neutral answer as a
refusal (`SurfaceAccess::decisive()`). Asked on its own, an
operation has no parent to allow it, so nothing else is going to.
(`ResourceOperationTool`, `OperationAccessCheck`)

## Tools

### Identity before the parameters

A tool's static definition reads which identity keys an operation knows
off its signature: a scalar parameter supplies its own name, an entity
parameter every identity key no scalar names. The tool then refines
`values` to the real context once the parameters arrive. What an
operation knows is only on the context it returns, and a static
definition cannot call it. (`OperationInputs::inputs()`) *Amended
2026-10-28:* an operation whose parameters name a whole thing, an edit
or a create that names as much identity as an edit does, supplies every
identity key ([A parameter is identity the caller
knows](#a-parameter-is-identity-the-caller-knows)).
(`ResourceDefinition::namesWholeThing()`)

### A parameter named for a key

A scalar operation parameter named for one of the resource's plain keys
is described as that key: its label, meaning and allowed values. It is
that key's value, so it says what the key says.
(`OperationInputs::parameterInput()`)

### A plugin's structure is no tool

A class any plugin names with `#[UsesStructure]` gets no tool,
whatever it declares. Only the plugin's host holds the instance it
configures. (`ResourceOperationToolDeriver`, `ResourceCatalog::standalone()`)

### A permission nothing can name is no tool

An operation whose permission has a `%key` none of its parameters can
supply is no tool: it could never be allowed. `field.storage`'s `create`
is only ever a field's child. (`ResourceOperationToolDeriver`,
`ResourceCatalog::standalone()`)

### A Symfony placeholder is filled in when shown

A constraint message written with Symfony's `{{ max }}` is filled in
where the violation is made into the module's own (`ViolationMessage`
wraps it), and respelled `%max` where a constraint is copied into a tool
input, whose validator is core's translator. Core's translator turns the
`{{ max }}` parameter into `%max` and leaves the message alone, so a
custom message reached the person with the braces in it; rewriting the
message at declaration would change what the author wrote and what a
translator matches on, and the wrapper keeps the original translatable
string. (`Pipeline\ViolationMessage`, `InputDefinitions::constraints()`)

### A refusal names what it depends on

A refusal says what is allowed for the values sent, in one sentence per
key, so a caller fixes each key in one try. Decided 2026-10-19,
amending the second sentence this entry first settled ("This depends on
Capacity."), which named the keys and not what they hold, and left a
value not on its list unexplained.

- **The form.** The constraint's own message comes first, whole, still
  the object it built. When the refiners changed the refused key's
  constraints for these values, a second sentence names each watched key
  with what it holds, by label, an option by its label: "This value
  should be 3 or more. This applies when Capacity is 150." When a list
  of allowed values made the refusal (a `Choice`, `LabeledChoice` or
  `AllowedValues`), a sentence lists the values the key allows as
  refined for these values, each by its label, a value with no label of
  its own by itself: "The value you selected is not a valid choice.
  This applies when Venue is Riverside Hall. Choose Main hall or East
  room." At most ten (`SurfacePipeline::CHOICES_SHOWN`),
  then "or one of 2 more"; an empty list says "Nothing may be chosen."
  An item of a list refused by its list of values says its item's
  choices the same way, at its delta: "days.1: The value you selected
  is not a valid choice. Choose Friday, Saturday or Sunday." (decided
  2026-10-21, from the fourth fresh-eyes log, entry 19, where a
  multi-select's item listed none). A key that does not apply keeps its
  sentence, "Send day does not apply when Frequency is Monthly." A bound
  and a pattern say themselves, through the constraint's message.
- **A misspelled key is named.** A key no definition declares is refused
  by its path, `unknown_key`; when a key declared beside it is at most
  two edits away, and the edits are fewer than half its length, a
  sentence of its own names the nearest: "Unknown key venue_nmae. Did
  you mean venue_name?", inside a map by its dotted path. A short key,
  or one near nothing, gets no guess. Decided 2026-10-21, from the
  fourth fresh-eyes log (entry 19).
- **The machine's rendering: keys by path, values as sent** (decided
  2026-10-20, from the AI agents review, group C of the reviews'
  synthesis, approved by the owner, amending the form's bullet above,
  whose list named each label with its value in brackets; amended
  2026-10-24 for the key a condition names, which both renderings now
  name by its label, in the next bullet). The tool's
  text and the `message` of its result ([One result at every
  door](#one-result-at-every-door)) render the same sentences for a
  machine: a key a condition names by its dotted path in the values a
  caller sends, a port by the path of the key it reads, an item's
  sibling by its path with the item's delta, and a value by what is
  sent with its label beside it: "This applies when venue is riverside
  (Riverside Hall). Choose riverside_main (Main hall) or
  riverside_east (East room)." The form keeps labels alone, which is
  what a person reads beside the field, and so does React's `message`,
  a person's door too; its `path` and `code` are the tool's. Why: the listing names a key
  by its path and the refusal named it by its label, which an alter
  relabels and a language translates, so an agent matched two
  vocabularies; and a label alone does not say what to send. One set of
  sentences, two renderings: the keys and values are `Pipeline\Named`
  placeholders, spelled by the renderer `Pipeline\Sentences::machine()`
  chooses, so a translator still meets each sentence once. The refused
  key itself keeps its label in "@label is required when ...": the
  violation's path already names it.
- **A condition names a key by its label** (decided 2026-10-24,
  amending the bullet above). The clauses "when <key> is <value>", "when
  <key> is set" and the rest name the key a condition reads by its
  label, at every door and in both renderings; a key held by another (a
  key an alter mounted, a property of a map, a key of a child, a variant
  or an item) by the label of what holds it, a colon, then its own; an
  item by its list's label; a port as the key it reads; and only a key
  with no label by its JSON Pointer, `/third_party_settings/x/flag`.
  Only the sentence changes: the refused key's pointer stays the
  result's `path`, and the value keeps what is sent beside its label
  for a machine. Example 14's webhook said "API key does not apply when
  third_party_settings.surface_examples_keys.key_id is set." and
  says "API key does not apply when Managed key: Key is set."; the
  parcel's declared value said "... Customs code is empty." and says
  "... Surface API Resource Test: Customs code is empty." (the mount is
  titled with its module's name); a crate's layers, in the fruit
  variant at `contents_settings`, say "This applies when Contents
  settings: Fruit is peach."; the bulletin's send day says "when
  Frequency is monthly (Monthly)" where it said "when frequency is
  ...". Why: a path in the middle of a sentence reads as storage
  layout, and a mounted key's names the module that mounts it, not the
  thing a caller chose; the caller already has the path of what to fix
  in `path` and the path of what it depends on in the listing's
  "Depends on" line, so the sentence carries the words a person and an
  agent both read, and one quote of it holds at every door. Why
  "Managed key: Key" and not "Key, under Managed key,": the colon joins
  two names with no translated word, as the semicolons between three
  or more conditions do, so each clause stays one source string, and
  "under" would be a fragment glued into a name or a second set of
  clauses for every kind. Why the holder alone and not the whole trail:
  one level says where the key is, and the levels above it
  ("Third party settings") are grouping a caller never chose
  (`Validating::conditionKey()`, `RefusalLabelsTest`).
- **Whole sentences, for translation** (decided 2026-10-20, from the
  multilingual review, B10). Every sentence a refusal adds is one source
  string a translator sees entire, in the context `surface
  refusal`, and none is glued from translated fragments: the
  constraint's message is no longer cut at its full stop (the old
  `Pipeline\Clause` only knew an ASCII one), and the sentences are set
  side by side by `Pipeline\Sentences`, outside translation. A condition
  is one whole clause per kind: "@key is @value", "@key is empty", "@key
  is empty (@value was refused)", "@key is set", "@key is on", "@key is
  off", and "@key holds its current value" for a value no word can say.
  The sentence that carries them is one source per count of conditions:
  "This applies when @condition.", "This applies when @first and
  @second.", and, plural by its count, "This applies when all @count of
  these hold: @conditions.", where three or more whole clauses are set in
  a row with semicolons; the same three shapes say "@label is required
  when ..." and "@label does not apply when ...". The list of choices is
  "Choose @choice.", "Choose @choices or @last.", and, plural, "Choose
  @choices or one of @count more."; a key that cannot be cleared says
  "on by default" and "off by default" in sentences of their own. So the
  promise in [declaring a resource](declaring-a-resource.md) holds: a
  refusal is never a concatenation of translated fragments.
- **What the rule was handed** (decided 2026-10-20, from the AI agents
  review, B11). A condition describes the values the refiners were
  handed, not the values sent: a watched value its own key refused was
  handed as nothing ([A refiner never sees an invalid
  sibling](#a-refiner-never-sees-an-invalid-sibling)), so it is said as
  empty, with what was refused. With an event license of "EV-2048" and a
  capacity of 150, the capacity's refusal reads "This value should be
  between 1 and 100. This applies when Room is Main hall and
  Compliance: Event license is empty (EV-2048 was refused)."; it read
  "... is set", the
  opposite of the reason, and an agent cleared the license
  (`SurfaceInterface::withheldValues()`).
- **Why each part.** The caller who sent stewards and no capacity learns
  which sibling to change, which the key's own message cannot say, and
  what it holds, so a caller that sent nothing for it sees that. Every
  watched key is named, held or not, because a refiner that watches a
  nullable value (the license) narrows when it holds nothing. The list
  is the one that applies, not the listing's wider one, so the next try
  is accepted; the value beside each label is what a caller sends, which
  a label alone does not say. A refusal by a rule the key declared
  itself names no condition.
- **Required, the same.** A key a refiner or a declared rule made
  required for these values says so with the same clause, a port by the
  container's key it reads, at the site: "Phone is required when
  Maximum visitors is 80." A key required by its own declaration in
  `defineInputs()` says "Phone is required." alone, as before. Decided
  2026-10-19, from the second fresh-eyes log (entry 18): a declared
  `require()` said only "Phone is required.", where a constraint the
  same rule tightened named its condition, so the reason a caller had
  to fix a sibling was missing from exactly the refusal it caused.
  `->describe()` stays the form's sentence under the field. A refused mounted key is matched to the
  property its refusal is about. The contracts are unchanged: this is
  the message, not the schema. The form says the same sentence: a
  select offers only what is allowed, so a person rarely reads it.
- **A presence condition says set.** A key a declared rule reads only
  by whether it holds a value, `present()` or `absent()`, with nothing
  else watching it, is said as set or empty and never by what it holds:
  "Name is required when Email is set.", "Signature does not apply when
  Email is empty." A key an `equals()`, an `in()` or a bound compares
  keeps its value, an option by its label ("when Service is Express"),
  since the value is what the rule asked about; so does a key a coded
  refiner also watches, since what the code read cannot be told. Decided
  2026-10-19, from the third fresh-eyes log (entry 22): "Name is
  required when Email is digest@example.com" read as though only that
  address made the name required.
- **The tool judges by the pipeline.** A derived tool's `values` is held
  by the Tool API to its shape alone before the tool runs, its types,
  the keys it requires and its nesting
  (`ResourceOperationTool::validateInputValues()`,
  `InputDefinitions::shapeOf()`), and every rule is the pipeline's,
  which narrows `values` from the document sent before it validates.
  Held to the listing instead, a room outside every venue's list was
  refused with no list that applies, and a capacity of 2000 with the
  listing's bound of 1000 rather than the room's 30: a second try per
  key, and in the Tool API's nested spelling ("values: (property
  room) ..."), which the MCP bridge prints without the path. The listing
  keeps every constraint it advertises, and the MCP server, which holds
  arguments to the advertised schema before a tool runs, refuses a
  value off an advertised list with that list. A value of the wrong type,
  or a required key left out, is still the Tool API's to refuse, with
  one exception (decided 2026-10-24): a map or a list sent for a key of
  a scalar type, at any depth, is refused by the tool's shape check
  before the Tool API's validators run, `type_mismatch` by its path, in
  the pipeline's sentence ("This value must be of type uri, array
  given."), and taken out of what they read. Core's
  `PrimitiveTypeConstraintValidator` reads a `uri` with `parse_url()`
  and a `decimal` with `preg_match()`, and either throws a `TypeError`
  on an array, which the Tool API answered as "Tool execution failed:
  TypeError" (`InputDefinitions::withoutNonScalars()`,
  `ArraySentForAScalarTest`).
- **The listing names what a key depends on.** In a tool listing a key
  another narrows says so in its own description, once: "Depends on
  venue." Each key by its path in `values`, what a caller sends it
  under; a port by the container's key it reads, at the site; never a
  stored value. The deciding key of variants is said by their key's own
  description, and a key that holds a child names nothing its ports
  read, which the keys inside it name. Inside a collection's item a key
  names its siblings by their keys in the item, the frame a caller sends
  them in together, on the item's own property (decided 2026-10-19;
  until then keys inside an item said nothing). The read tool's output
  says nothing about it. The listing stays small: a key that depends on
  nothing says nothing more.

(`Validating::sayingWhatIsAllowed()`,
`Validating::conditions()`, `Validating::appliesWhen()`,
`Pipeline\Sentences`, `Pipeline\Named`, `Entry::presenceDependencies()`,
`Validating::choices()`,
`Validating::isRequired()`,
`InputDefinitions::withDependencies()`, `ToolFeedbackTest`)

### An operation states how it behaves

`#[Operation]` says whether a call creates (`creates`), whether it
changes nothing (`readOnly`) and whether it may destroy what is there
(`destructive`), all `FALSE` unless said, and whether the same call
twice leaves what the first left (`idempotent`), derived unless said:
an operation that does not create is idempotent, the same document
twice writing the same state; one that creates is not, the second call
finding what the first made; a read only one is. Decided 2026-10-19;
`creates` moved onto the attribute 2026-10-20. A read only operation
that destroys, or that is not idempotent, is refused at discovery.
`OperationDefinition` carries the facts (`kind()`, `idempotent()`,
`facts()`), and so does a derived operation (`DerivedOperation`).

- **Why on the operation.** A client asks before it calls a tool that
  destroys and retries one that is idempotent, and MCP reads both as a
  tool's annotations. Only the author knows whether a call destroys; a
  module that writes cannot read it off a storage class. Whether an
  operation creates is on the attribute too, stamped on its context
  ([Whether an operation creates](#whether-an-operation-creates)), so
  idempotence is derived from it and declared only where the author
  knows better: an operation that appends is not idempotent, and a
  create whose caller names the thing is. An edit destroys nothing: it
  changes the keys it is sent, and a key left out keeps its value. The
  first operation that does is the placement's `delete`, a delete
  (decided 2026-10-23); a delete that does not say it is destructive is
  refused at discovery.
- **A create the caller names is idempotent** (decided 2026-10-20).
  `node.type:create`, `field.instance:create` and `field.instance:reuse`
  create a thing whose identity the caller sends, a machine name or a
  field name on a bundle: the second identical call finds that name
  taken and leaves what the first left (the content type's is refused
  for the name, the field's writes the same field again), which is
  Smithy's put. They declare `idempotent: TRUE`, and their `kind`
  stays `create`: whether a tool requires the resource's keys at its
  door follows from creating, not from idempotence, and no `put` kind
  was added. A create whose storage gives the id (a content entity's
  create) stays derived, not idempotent. Derivation was weighed (a
  creating context that leaves an identity key for the caller) and not
  taken: it reads the context, which waits for the parameters, and the
  attribute already says it in one word.
- **The Tool API.** The tool deriver makes a read only operation the
  `Read` kind and every other the `Write` kind, and passes `destructive`
  to `setDestructive()`; a read tool is `Read` by what it is
  (`ResourceOperationToolDeriver::behaves()`). A read only operation's
  tool takes no `dry_run`, since it writes nothing at any door; every
  tool declares what it answers, each with a description, so a model
  reading a success knows where to look for "accepted, but not as sent"
  (decided 2026-10-20, B14): the one result shape, `ok`, `committed`,
  `changed`, `changes`, `values`, `violations`, `notes` (values sent
  for a locked key, not taken) and `stale` (stored values no longer
  available), a read only operation's too, whose `committed` and
  `changed` are false and `changes` empty (*changed 2026-10-20: it
  answered no `committed` and no `changes`; [One result at every
  door](#one-result-at-every-door)*). The MCP bridge derives
  `readOnlyHint` from a kind that does not modify, `idempotentHint` from
  the kind, and `destructiveHint` from the flag. *Gap, to file:*
  idempotence is a property of the kind in the Tool API, not of the
  tool, and every kind that modifies is not idempotent, so an update
  cannot say it is idempotent while a create says it is not: both are
  `Write`, and both read as not idempotent. `Write` is the least wrong
  kind for an idempotent operation that changes what exists: every
  idempotent kind does not modify, and would also say the operation
  changes nothing, which a client acts on.
- **Both documents.** The schema for these answers and the full schema
  state the facts at the top, first under the top's `x-surface`, beside
  the title that is the operation's label:
  `x-surface.operation: {id, label, description, kind, creates,
  destructive, idempotent}`, `description` only when the
  operation says one, so a client reading the contract directly has the
  operation's own answer, idempotence included. No `readOnly` among
  them since [contract 2](#contract-2): `kind: "read"` says it, and
  JSON Schema's `readOnly` at the same top means the resource is
  [locked whole](#locked-at-the-top). The attribute keeps `readOnly`,
  and the tool reads it. As the label is the
  document's `title`, the description is its top `description`
  (decided 2026-10-20), and a change to either is a note to the
  contract diff. A document
  asked for in no operation states none.
- **A kind, derived.** `kind` names the call in one word, `create`,
  `update`, `read` or `delete` (decided 2026-10-19; `delete` 2026-10-23):
  a read only operation is a read, one that creates is a create, one
  that says `deletes: TRUE` a delete, which takes no values and removes
  the thing it names
  ([A placed block is a resource](#a-placed-block-is-a-resource)), and
  every other an update
  (`OperationDefinition::kind()`), read off the attribute, so known
  before anything is built. It is never declared, so it
  cannot disagree with `readOnly`, `creates` and `deletes`. The contract diff
  compares it beside `creates` (until [contract 2](#contract-2) it
  compared `readOnly`, which the documents no longer state). It is the
  fact a client needs first,
  and it says what a generated tool asks for at its door: a create
  requires the keys the resource requires, an update none ([Required is
  the resource's, and an update tool requires nothing at its
  door](#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door)).
  A tool says it in its description's line of call rules, which starts
  with the kind ("Update: send only the keys to change; ..."), beside
  the Tool API's own `Read` or `Write`, which `tool:info` prints as its
  operation.
- **A description, and a title of its own** (decided 2026-10-20). A
  tool's description is the operation's `description`, or its label
  where it says none, then one line of call rules for its kind, with no
  literal quotes, which the MCP bridge escapes into entities: "Create: a
  key left out takes its default, and every key marked required must be
  sent.", "Update: send only the keys to change; a key left out keeps
  its value, null clears it, and values {} changes nothing. The read
  tool returns the current values." (the last clause since 2026-10-20,
  [`values` required](#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door)),
  "Read only: nothing is written; a key left out is checked at
  its current value." It replaced a template that gave 38 of 65 tools
  one 32-word sentence. The read tool is named by what it does, not by
  its Tool API id, which an MCP client renames. No two tools share a
  title: the examples' three registration steps say which step they
  are, and where operations of several resources still share a label,
  each tool adds its resource's label, or its id for a class, which has
  none; the read tool is titled "Current values:" and the write tool's
  title (`ResourceOperationToolDeriver::title()`,
  `OperationInputs::description()`).
- **No read tool beside a read only operation.** It changes nothing, so
  there is nothing to read before changing it
  (`ResourceCatalog::reads()`).

A read only operation writes nothing at any door (decided 2026-10-19,
closing the point this entry left open): the build marks the data
surface (`SurfaceInterface::changesNothing()`), its pipeline
accepts, validates and prepares and then stops where a dry run does, and
`commit()` refuses values prepared for it with a `LogicException`, so a
caller driving the stages itself cannot write either. Its form and its
tool say the values were accepted and nothing was written. `prepare()`
performs no side effects for it as for any operation, which is the
storage's contract already ([Prepare](resources.md#prepare)). No
build-time check was added: whether a storage would write is whether
its `commit()` is called, which no class can be read for ahead of time,
and the pipeline is the one place that calls it, so the refusal there is
the cheapest honest check. Whether a call reaches beyond
the site (MCP's open world) is a fact of the storage, not the
operation, and a later flag there; the MCP bridge says open world for
every tool today. (`Operation`, `OperationDefinition::facts()`, `OperationDefinition::kind()`,
`DocumentHead::operation()`, `SurfacePipeline::commit()`,
`OperationFactsTest`, `OperationKindsTest`, `LockedAccessTest`)

### A violation summary is plain text

A derived tool's refusal names each violation with its message rendered
as plain text before the line is handed on as a placeholder value. The
line is escaped there, so a message's own placeholder markup (Range's
`<em class="placeholder">`) would otherwise reach the caller, Drush
included, as literal tags. Every door's `message` is rendered the same
way. (`ResultDocument::message()`, `ResultReportingTrait::refusedMessage()`)

### Required is the resource's, and an update tool requires nothing at its door

A derived tool for an operation that creates marks a `values` property
required exactly when the resource, built for the operation, requires
the key, whatever its default, except a secret, which is always
optional ([A secret is optional, never nullable](#a-secret-is-optional-never-nullable)).
A tool for an operation that changes a thing that exists marks no
property required, at any depth, and each key the resource requires
says so in its description instead: "Required; a value already stored
stands when left out." The pipeline is unchanged: it judges the merged
document, what is sent laid over what is stored, so an edit of a thing
that holds no value for a required key is refused with the key's own
message ("Event title is required."), and one that holds a value keeps
it. The refined `values` a tool builds once its parameters arrive says
the same as the listing (decided 2026-10-19, so a client reading it is
not told what the door does not enforce): nothing required, each key
the resource requires saying so in words, each defaulting to what is
stored. The tool holds what it is sent to that shape alone before it
runs, requiring nothing on an update
(`ResourceOperationTool::validateInputValues()`), so the call reaches
the pipeline. The schema for these answers and the form keep the
resource's `required`. A collection's items keep their required keys: an item
sent is sent whole, with nothing stored under it to stand.

What a property defaults to in the tool's static definition, its
listing, depends on whether the operation creates. One that creates
lists the declared defaults, which are what a key left out becomes. One
that changes a thing that exists lists none, at any depth, and its
description's call rules say "Update: send only the keys to change; a
key left out keeps its value, null clears it, and values {} changes
nothing. The read tool returns the current values."

**`values` itself is required, and never null** (decided 2026-10-20,
from the AI agents review, group C of the reviews' synthesis, approved
by the owner; section 3.6 of the synthesis). The map is required and
carries `NotNull` (`OperationInputs::alwaysSent()`), and its
description says "Always sent, as an object and never null: {} sends
no key.", so the listing advertises an object and nothing else, and a
call that leaves `values` out or sends `null` is refused by the Tool
API's own validation before the tool runs ("values: This value should
not be null."): the tool keeps the input as it was sent rather than the
Tool API's reading of a required map left out, its definition's
default, a map of every key's default, which on an update is a map of
nulls (`ResourceOperationTool::resolveInputValues()`). Required here is
the door's, not a key's: what a key inside requires is still the
resource's, nothing on an update, so an update with nothing to change
is `values: {}`, and the round trip rule keeps it a no-op
([Absent keeps, null clears](#absent-keeps-null-clears)): it writes
nothing and answers `changed: false`. Why: at the top `null` meant
nothing while one level down it clears, so a caller that learned "null
clears" read `values: null` as "clear everything", and a call with no
`values` was read as every key's default.

Why no required key on an update (decided 2026-10-19, replacing "a
required key cannot be left out"): the Tool API refuses a required input
a payload leaves out before the resource runs, default or not
(`TypedInputsTrait::validateInputValue()`), so "required" on an update
tool told callers something false: that a key with a value stored must
be sent again, when the pipeline would have kept it. Updates are a
patch, and a patch requires nothing at the door. Required is still the
resource's, and still said, in words, where a caller reads it. The case
that shows the rule holds is a required field added to an entity later:
a thing stored before it holds no value there, so the next edit of that
thing must supply one, and does, because the pipeline refuses the merged
document without it, in the key's own words; a thing that has one keeps
it. A create still lists the resource's required keys: nothing is
stored, so a key left out holds only its default.

Why no stored value in a listing (decided 2026-10-18, replacing
"defaults are what is stored"): a listing is a discovery channel that
clients cache, `tools/list` in MCP among them, so a stored value in it
is as of the last discovery and goes stale unseen, and a default on an
edit says what a key left out becomes, which is not what happens: it
keeps what is stored. The read tool is the per-request answer to "what
is there". Once an operation's parameters arrive, the refined `values`,
which no client caches, still defaults each property to what its storage
loads now, and the schema for these answers keeps current values in
`values`; the full schema keeps declared defaults. A write through the
tool no longer clears the definition cache, since nothing in the
listing changed. (`OperationInputs::declaredValues()`,
`OperationInputs::values()`, `OperationInputs::alwaysSent()`,
`ResourceOperationTool::resolveInputValues()`, `InputDefinitions::withoutRequired()`,
`InputDefinitions::withoutDefaults()`, `InputDefinitions::withStored()`,
`OperationKindsTest`)

### Which operations read

An operation that can be asked on its own has a read tool when it changes
a thing that exists: when its kind is `update`, neither creating nor
read only, both read off its attribute. A read only operation has none:
it changes nothing ([An operation states how it
behaves](#an-operation-states-how-it-behaves)). Until 2026-10-20 the
signature was read for an operation that needs a subject (one entity,
or a scalar for every identity key, named one thing whole), because
whether it creates was on its context, which cannot be built before the
subject is named; `creates` on `#[Operation]`, checked against the
context the way its id is, replaced the reading, closing the point this
entry left open. The narrowing tool, `:schema`, is a read beside the
write tool for another reason, a key the listing states open
([A budget for what the site decides](#a-budget-for-what-the-site-decides)).
(`ResourceCatalog::reads()`)

### A read promises shape, not membership

A read tool's `values` output is the write tool's `values` map said as
an output: data types, labels, descriptions and nesting, with no
constraint and no property required. The Tool API holds a tool's outputs
to their definitions, and a stored value may have gone stale, which a
read reports and must not fail on; a key storage holds nothing for is
absent. The vocabulary is on the write tool's input.
(`InputDefinitions::outputFromInput()`)

### A read leaves a secret out

A secret is never returned by a read, and is not in its output
definition either: left out, as the write tool's defaults leave it out,
rather than sent as an empty placeholder a caller could mistake for the
value. Among variants, a key any variant keeps secret is left out
whichever variant holds. Inside a list of maps, a collection's items
among them, only the secret is left out of each item, and the list and
the rest of every item are read (decided 2026-10-19; until then a secret
in an item hid the whole list); a list of secrets is left out whole.
No other door returns one either ([Every door projects what the read
tool projects](#every-door-projects-what-the-read-tool-projects)).
(`Pipeline\Outbound::secrets()`, `Pipeline\Outbound::EACH`,
`OperationInputs::stored()`, `ReadToolTest`)

### A static tool definition is no account's

A derived tool's definition is cached for every account, so it is built
for none (decided 2026-10-20, replacing "a static tool definition is one
account's"). The deriver builds each resource for no account
(`ResourcesInterface::buildDeclared()`): every access answer is set
aside, static or runtime, the operation's permission at a child among
them, and none is asked, so every key is there as declared and each one
an answer could lock or leave out is marked, its description saying a
call may find it locked or left out. And it derives as the anonymous
account, so what an option list reads of the site (a reference's
targets) is never a privileged account's. A tool instance lists
`values` again for the account that asks, built for that account alone
(`buildStatic()`): a static lock stated, a static omission left out, a
runtime answer marked ([Access is static or runtime, read from its
cacheability](#access-is-static-or-runtime-read-from-its-cacheability));
and the `values` input is refined for the caller on every call, which
is what the pipeline holds a payload to. Each resource's tools are kept
in an entry of their own and derived the same way, so no entry is any
account's either.

Why no account's rather than the least privileged one's: built as the
anonymous account with its static answers applied, the cached
definition would leave out every key some account may not see, so a
consumer reading the definitions without an instance (ECA's model
editor through `eca_tool`, which lists a tool's inputs for an
administrator) could not offer them; and built as whoever discovered
it, it was that account's view, cached for everyone, which is what the
security review found. What it holds now is what the code and the site's
structure declare, the shape a module's source and its configuration
already say, never a stored value and never one account's answers;
what an account may do with each key is the listing and the call's to
say. Until this, the definition was built for whichever account caused
the derivation, and a consumer reading it without an instance saw that
account's view (the tool comparison and the field tools' tests now ask
as an account the tools are open to, as a real client does).

It is no language's either (decided 2026-10-20, from the multilingual
review, B6): the definition is cached once for every language, so every
string the module writes into it is lazy, a `TranslatableMarkup` that
renders in the language that reads it, never one rendered in the
language that discovered the tool ([A machine document carries plain
text](#a-machine-document-carries-plain-text)). Two kinds of string are
not yet lazy: a label read from configuration, which config translation
overrides when it is loaded (a content type's, a field's), is a string
in the language that loaded it, so a content entity resource's tools
name the bundle and the field in the language that discovered them; and
a key with variants describes each variant's keys joined into one
string, whose word for a variant with none ("nothing") is rendered then.

*Open, outside this module:* the MCP bridge's config deriver
(`mcp_server_tool_bridge`'s `McpToolConfigDeriver`) normalizes a tool
instance's input schema when it derives its MCP tools and caches that
for every client, so the MCP `tools/list` is still the listing of the
account that caused that derivation. An instance's listing is the
account's by design; the bridge would need to normalize per request, or
from the cached definition, to stop that. The same holds for language:
the bridge casts a tool's description to a string when it derives, and
normalizes the input schema in the language of that request, so the MCP
listing is in the language that caused the derivation.
(`OperationInputs::listedValues()`, `OperationInputs::listed()`,
`ResourceOperationToolDeriver::getDerivativeDefinitions()`,
`Resources::buildDeclared()`, `SecurityProjectionTest::testCachedToolDefinitionsAreNoAccounts()`)

### Reading asks what writing asks

A read tool's access is its write tool's: the operation's permission,
then the resource's access class, for the context its parameters build.

A caller who may not change a thing may not read it through the tool
either. *Open:* a read permission of its own.
(`ResourceOperationToolBase::checkAccess()`)

## Forms

### A panel inside the operation form

A route may name a read-only panel in `_surface_panel`, a service
id or class implementing `SurfaceFormPanelInterface`, the way it
names a cosmetic layer. The operation form places what it returns
inside the form's `surface` container, and the AJAX rebuild replaces it
along with the dependents of whatever changed, because a panel
describing the resource as it stands has to follow every change; it is
handed the values the elements are built with, not a refined data
surface, so it can tell which variant is shown. The
contract panel lives in the base module and shows the
derived tool's JSON Schema where the tool module is installed. Its "Right now" column, and the React
page's, says "narrowed, and checked at runtime" for a key that is
narrowed and that a runtime rule also checks (decided 2026-10-19,
replacing "narrowed at runtime", which named the runtime rule as what
narrowed a key a static refiner had narrowed); neither knows which of
them narrowed it, so neither says. (`SurfaceOperationForm::surfacePanel()`,
`ContractPanel::status()`, `ContractPanel.tsx`)

### An error on the trigger renders inline

A refinement request validates one value, the trigger's, and what is
wrong with it is said under the trigger, once. A violation the data
surface reports on the trigger is not made a Form API error on that request:
Form API skips the rebuild once anything has errored, and the
dependents would come back refined against the answer before this one.
`flagSurfaceErrors()` holds it in temporary form state instead, the
rebuild goes ahead (refining as though the refused value were absent,
by [the rule above](#a-refiner-never-sees-an-invalid-sibling)), and
`refreshSurface()` gives the trigger the error: `#errors`,
`aria-invalid` and the `error` class, printed in the form element
template's own error slot by the module's preprocess, which is where
Inline Form Errors would print it, so the two never print it twice.
The response then replaces the trigger's own wrapper (the one
exception to never redrawing the element that was touched), and the
trigger's next request says it showed an error (`INVALID_INPUT`), so
it is replaced once more and an error the person fixed is taken away.
An error Form API's own element validation sets on the trigger, a
maximum length, does stop the rebuild; it is printed the same way and
taken back out of the messages at the top, so it is not said twice
either. The data surface's message wins when both have one. On a full
submission nothing changes: core prints every error at the top and
marks the elements. (`SurfaceFormBuilder::flagSurfaceErrors()`,
`SurfaceFormBuilder::refreshSurface()`, `Hook\InlineErrorHooks`)

### An element hints and the rule speaks

A stock widget puts what a constraint allows on its element as a hint
(`#min`, `#max`, `#maxlength`), and the number widget clears core's
`#element_validate`, so the pipeline's violation is the one message a
refusal shows. Core's number element checks `#min`, `#max` and `#step`
before any form level handler, a form keeps the first error per
element, and its wording ("must be lower than or equal to 1000")
replaced the rule's own message, a refiner's `notInRangeMessage`
included; the tool and the served contract said the rule's. Mapping the
element's error back to the rule's message would keep two checks that
can disagree; one check is the module's whole case. The email and url
elements keep their validation for now: it also trims the value, which
the pipeline does not. (`NumberWidget::buildElement()`)

The string widget does the same for a `Length`'s maximum (ROADMAP item
10, and the adopters' gate, 2026-10-19): core checks `#maxlength` in the
form validator, before any form level handler, and its "cannot be
longer than" replaced the rule's own message. The maximum reaches the
browser as the `maxlength` attribute, and `#maxlength` is cleared, the
element type's own default with it (128 for a text field, 254 for an
email), so the form enforces no limit the definition does not declare.
(`StringWidget::lengthHint()`, `RefusalMessagesTest`)

And for `#required` (decided 2026-10-19, from the third fresh-eyes log,
entry 21): core checks it before any form level handler, and its
"Name field is required." replaced the rule's own "Name is required
when Email is set.", so the form said one thing and the tool another.
The element keeps `required` as a hint: the form builder, processing
the container before any element in it is checked, clears `#required`
on every element that holds a value (any widget's, a module's own
included), marks it (`SurfaceFormBuilderInterface::REQUIRED_KEY`),
and puts it back when the element is drawn, first in its `#pre_render`
list, so the browser's `required` attribute, the label's marker and the
radios' legend are drawn as core draws them, on the page and in an AJAX
response alike. The pipeline's violation is the one message: "Name is
required." for a key required by its declaration, "... is required
when ..." for one a rule made required. A details or a fieldset holds
no value, so its `#required` is the marker alone and stays. A list is
never drawn required, a multiple select now as the checkboxes already
were: the empty list satisfies a required list, and a `required`
attribute would ask for one item more than the rule does. The widgets'
`#required_error` went with this. (`Form\Refreshing::requiredHints()`,
`SurfaceFormBuilder::preRenderRequired()`, `RefusalMessagesTest`,
`FirstResourceTest`)

The form names the field where the message is not printed beside it
(decided 2026-10-20, from the Form API review, B15 of the reviews'
synthesis). The rule still speaks the one message; a constraint's own
words name no field, which is enough beside the element and nowhere
else (WCAG 3.3.1). With Inline Form Errors off, a full submit's
messages are printed at the top of the page, so each is put after the
element's title in one translatable sentence, "@field: @message", the
message object a placeholder in it: "Capacity: This value should be
between 1 and 1000." A message that already begins with the title (a
required key's) and an element with no title are left as they are; with
Inline Form Errors on nothing is added, since it prints the message
beside the element and names the field in its own summary. The tool
and the served contract already say the path beside the message, and
are unchanged. On an AJAX rebuild the trigger's error, printed under
it, carries an id the input's `aria-describedby` names, and the
response ends in one polite announcement (WCAG 4.1.3): the refusal,
named, then what the rebuild changed on the dependents it replaced, in
whole sentences ("Send day no longer applies. Items per issue now
allows 1 to 20."), read off what each was drawn as on the page the
request came from. (`SurfaceFormBuilder::flagSurfaceErrors()`,
`Form\Drawing::named()`, `Form\Refreshing::changesSaid()`,
`Hook\InlineErrorHooks`, `ErrorsNameTheirFieldTest`,
`RebuildAnnouncesTest`)

### A Regex is explained by its message

A pattern is for machines, and is carried only where both engines read
it alike ([A pattern both engines read alike](#a-pattern-both-engines-read-alike));
the message is always there. Wherever the module explains what a key
allows (the contract panel's "Allows" column, the React app's twin of
it), a Regex is said in its own `message`, the sentence a refusal is
reported in, and the pattern stays in the machine schema (`pattern`,
with the message beside it as `x-surface.patternMessage`). Only a Regex
with no message falls back to "matches a required format" and the
pattern in code. Neither the string widget nor the React text input
puts the pattern on the input: an HTML `pattern` makes the browser ask
for "the requested format", and Form API's `#pattern` says
"is not in the right format", both vaguer than the message the data
surface answers with. (`ContractPanel::regex()`,
`PropertySchema::keywords()`, `ContractPanel.tsx`)

### The empty option rule

A single select shows its empty option according to four points, and
the stash model under them is unchanged. "Required" in them reads "holds
a value": a required key, or one [its storage always
holds](#a-key-its-storage-always-holds), which is no less unable to be
cleared:

1. A stored value no longer among the options is shown as the empty
   option, selected, which says so: "- Keep @value (no longer offered)
   -" (decided 2026-10-20, from the Form API review and section 3.5 of
   the reviews' synthesis), posting the empty string as before. It is
   not put back into the list as a choice; the stored value travels on
   the element
   (`#surface_stale`), and the container posts the paths of such
   selects in a hidden input (`SurfaceFormBuilderInterface::STALE_MARKER_KEY`).
   A save that leaves the select empty keeps the stored value, and its
   dependents, with the non-blocking stale warning; only an explicit
   new choice replaces it. The same after an AJAX change of a parent
   orphans a stored dependent: empty, standing for the stored value,
   which a Save then refuses because the parent moved in the same edit.
2. A required select shows the empty option only when no valid choice
   is selected: nothing stored and no declared default, or a stored
   value no longer offered. A stored value still offered, or a declared
   default the list offers, is a valid choice, and no empty option is
   shown: the key's author chose the default.
3. On first entry a required select comes up on the empty option, and
   a save leaving it there is refused with the key's own required
   message ("@label is required."), the pipeline's: core's check is off
   on every element the form builder draws
   ([An element hints and the rule speaks](#an-element-hints-and-the-rule-speaks)).
   A required select standing for a stale value is not required to the
   browser, since empty there means keep: no `required` attribute, which
   would refuse the option that keeps it. The rest of the hint is drawn
   as on every required element, so the eye and a screen reader agree:
   its label keeps the marker, and the select says `aria-required`.
4. A select that may be cleared (an optional key its storage does not
   always hold) keeps its empty option, `- None -`, always. Choosing it
   on a select with nothing stale behind it clears the key; on a stale
   one it is what was already selected, so it keeps. An optional key its
   storage always holds offers none once a valid choice is selected,
   the original forms' select with no empty choice for a key that
   always has a value (the adopters' gate, "Optional grows an empty
   choice", decided 2026-10-19).

Radios follow the same points with nothing checked where a select shows
its empty option: `- None -` is a radio only where choosing nothing is
an answer, a key that may be cleared, and a key that holds a value shows
no checked radio while it holds no valid choice, a stale value behind it
on the stash. The served contract says the same in `emptyOption`, for
radios as for a select, and the React app follows it.

The placeholder option this replaced ("Previous value X is no longer
available", posting a sentinel from the select) told the person what
was missing, but put an option in the list that was no value of the
key; that is why it was rejected, and the sentinel stays rejected. The
words came back on the empty option, which posts the empty string, so
the reason does not apply to them. They are said only where a save
keeps the value: an orphan of the edit, which that save refuses, and a
declared default the list does not offer, with nothing stored behind
it, say "- Select -" or "- None -" as before. The served contract's
`emptyOption` carries the same words, and the React app says them. Pipeline callers are unaffected: a payload that leaves a key out
keeps it, one that sends the stored stale value back keeps it with a
stale entry, as before. (`OptionsWidget::singleSelect()`,
`RadiosWidget::buildElement()`, `SurfaceWidgetBase::unstash()`,
`SurfaceHostTrait::surfaceFormValues()`)

### A widget is chosen from the definition

The stock widgets draw an ordinary settings form with no widget of a
resource's own, and each is still chosen from the definition and nothing
else (decided 2026-10-19, from both gates: checkboxes, radios, a list of
free strings and a time picker):

- **A list of allowed values is checkboxes while it offers at most
  twelve, a multiple select above that.** A multiple select asks for a
  modifier key to choose a second value and drops every choice on one
  stray click, so it is kept for the lists too long for a column of
  checkboxes to be scanned (a year of months is one column; every
  country is not). Counted on the definition as it stands, so a refiner
  that narrows a long list below twelve redraws it as checkboxes; every
  widget reads either element back, since which widget reads a value is
  not the one that drew it. Checkboxes post every option, each with its
  value or 0, and are read back as the list of the values checked: all
  unchecked is the empty list, which clears the list, and boxes left as
  drawn keep it. Never `#required`, as a boolean's checkbox is not: a
  required list must hold a value, and the empty list is one.
- **One allowed value stays a select unless it asks for radios.** A
  select already shows every choice on one click and has none of a
  multiple select's faults; radios spend a line per choice on every key,
  which is the page's layout and its author's call (pathauto's form draws
  radios for one key and selects for thirty-three rows beside it), and a
  count rule would redraw a key as another element when a refiner
  narrowed its list (a store's regions per country). `first-resource.md`
  promises a select for a `LabeledChoice`, which stays true.
- **A list of free values is rows**: one element per item, drawn by the
  item's own widget, "Remove" beside each and "Add another" under them,
  rebuilt over AJAX (or a plain submit) replacing the list alone, within
  the list's Count. Not a text area of one item per line, which the
  examples' widgets drew: an item's own rules (a Length, a Regex, an
  example) reach its element, a refusal of one item is shown on its row
  (`keywords.2`), an item may hold a comma or a line break, and the form
  draws what both schemas and the React app say, a list. An agent's
  contract is a list either way; rows are what keeps the form's reading
  of it the same.
- **A time of day is a time input when it asks for one**,
  `setSetting('widget', 'time')` on a string: core's own spelling of a
  time picker, a `date` element whose input type is `time`, posting the
  `HH:MM` the key's Regex still checks. Nothing is read off the pattern,
  which is for machines and which many patterns could spell.
- **The `widget` setting asks for one by name**, in core's setting
  spelling and the served contract's words: `radios`, `checkboxes`,
  `select` (a long or short list alike), `time`. A name that does not
  fit the definition (radios for a list, a word no widget answers to) is
  refused when the element is built, naming the widget that would draw
  it, rather than drawn as something it did not ask for. A widget of a
  module's own answers to its plugin ID. *Changed 2026-10-24:* so does
  every stock one, the select, fieldset and checkbox widgets renamed
  from the plugins `options`, `map` and `boolean`, one word per element
  across plugin ID, hint and setting
  ([The surface was counted and cut](#the-surface-was-counted-and-cut)).

The served contract's `x-surface.widget` mirrors each choice by asking
the same rule (`CheckboxesWidget::drawsList()`,
`RadiosWidget::drawsOne()`, `TimeWidget::draws()`), and is still written
only when asked for. (`SurfaceWidgetManager::asked()`,
`SurfaceWidgetBase::answersTo()`, `SurfaceWidgetBase::checked()`,
`ListWidget`, `StockWidgetsTest`)

*Amended 2026-10-24,* from the alter-pattern survey: a module's widget
may claim every key of a kind on the site,
`#[SurfaceWidget(claims:, priority:)]`. A contrib module that ships
a color picker serves every color key on a site; here only a key's
owner could ask for a widget, by name. A claim is a data type
(`['type' => 'string']`), a data type and the key's `format` setting
(`['type' => 'string', 'format' => 'color']`), or a shape id
(`['shape' => 'event.license']`): what a definition says its value is,
so a claim needs nothing from the owner. A list or a map is drawn by
what it holds, and a claim of one is refused at discovery. The manager
asks the claims before the stock rule, the highest priority first, then
by plugin ID; the first whose claim matches and whose `isApplicable()`
says yes draws the key. A key whose `widget` setting names a widget is
its owner's choice, and no claim is asked, so the owner wins. A widget
that claims is asked for no key it does not claim, unless a key names
it. `x-surface.widget` says the claimed widget's word in both documents
(the OpenAPI document carries no widget hints); the React page draws a
word it has no field for as the stock field for the key's JSON type
(`stockWidget()`), and a key no widget draws stays not editable. The
catalog's view of one resource names each key's widget. The `format`
setting is read by claims alone: no document writes it, and no rule
checks it. (`SurfaceWidgetManager::claimed()`, `WidgetClaimTest`)

*Amended 2026-10-27:* a date is the time input's twin,
`setSetting('widget', 'date')` on a string, drawn as core's `date`
element with its default input type. It also states `format: date` in
both documents, because a date input posts the RFC 3339 full-date,
`YYYY-MM-DD`, exactly; the reader reads `format: date` back as the
setting, so a full schema still reads back to itself. A time states no
format: a time input posts `HH:MM`, and RFC 3339's time requires seconds
and an offset, so `format: time` would refuse every value the input
posts. The format stays an annotation
([`format` is an annotation](#format-is-an-annotation)), and the key's
Regex is still the rule. (`DateWidget::draws()`, `PropertySchema::type()`,
`SchemaReader::value()`)

### The form never refuses to draw a declared key

Decided 2026-10-27, from example 12 on the development site: of 312
components, 91 took the form down with "No data surface widget serves
the "any" data type" (a prop typed as several JSON types, read as `any`
with a JsonSchema constraint), and 25 with the same for a list (a list
of anything). The tool, HTTP and the React page worked from the schema;
only the form had no answer.

A key the schema states must be editable at every door. The `json`
widget is the Form API's last resort, by weight: a text area of the
value as JSON for an `any` key holding no variants and a list the list
widget refuses, decoded on save and accepted and validated as any value,
text that is not JSON an error on the element. It is the worst
acceptable form of an editable key, and it is marked for replacement
([roadmap](https://github.com/mlander/surface/blob/main/ROADMAP.md), item 29): a text field for a value of several
scalar types, and rows for a list of maps. The served contract names no
widget for it, since it says nothing about the key.

Rejected: throwing, today's behavior, which takes the whole form down
for one key; and leaving the key out of the form, which makes the form
and the contract disagree about what may be set.
(`JsonWidget`, `ListWidget::isApplicable()`, `SpotlightFormTest`)

*Amended 2026-10-27, the widgets the JSON fallback stood in for:*

- **A union of scalars is a text field**, the `scalars` widget, weight
  999: an `any` key whose kept schema's `type` lists two or more of
  string, integer, number and boolean, and nothing else but null. What
  was typed is read as the first stated type it spells, integer, number,
  `true` or `false`, then string, and the JsonSchema constraint judges
  what came out. One input per type was weighed and turned down: a
  person types `12` or `true` into one field either way, and a choice of
  type before the value is a step the schema did not ask for. The types
  are read in one place, `SchemaReader::statedTypes()`, which the JSON
  widget's description reads too. On example 12, 114 props of 312
  components.
- **A list of maps is a collection, wherever it stands**: inside a
  variant, an attached part, a collection's item or a plain map, and at
  the top of a frame where no `attachList()` declared it. The form
  builder draws it with the collection drawing and reads it item by item
  (`Drawing::collectionsInside()`, `Extracting::collectionsIn()`); the
  map widget draws only an item standing in for it, since a widget knows
  neither the list's place nor the container its buttons rebuild. A
  list read from a schema is an `attachList()` of a read structure, so
  it has a child; a list declared with a map as its item has none, and
  one is sealed from the item's keys for the form alone
  (`Collection::forList()`). This draws the `components` list of a slot
  too, which the slot decision left not drawn.
- **Every data type core defines is drawn**: `timestamp`, `timespan` and
  `decimal` by the number widget, `duration_iso8601` by the string
  widget, `datetime_iso8601` by core's `datetime` element (read back as
  the ISO 8601 string), and `binary`, `language`, `language_reference`
  and `entity_reference`, whose value nobody types, as an item saying it
  is not drawn on this form, which posts nothing, so the key keeps what
  it holds (`NotDrawnWidget`). `StockWidgetsTest` walks every type core
  defines.
- **What the JSON widget still draws**: an `any` key whose types include
  an object or a list, or that states none, and a list of lists or of
  `any`.

No hint moved: the served contract names no widget for any of these,
and the documents are byte-identical.
*Left open:* a data type a module adds that no widget names
(`filter_format`, `layout_section`) still throws naming it.
(`ScalarsWidget`, `DateTimeWidget`, `NotDrawnWidget`, `Collection::forList()`,
`StockWidgetsTest`, `SpotlightFormTest::testSeveralTypesAndListsOfObjectsAreDrawn()`)

*Also 2026-10-27, the form's door:* a value of a shape its key cannot
hold, an array for a string, is refused on the form as at every other
door: an error on its element, in the pipeline's words, the value left
out and the rest accepted, never an exception
(`Extracting::accepted()`, `Accepting::typeMismatchViolation()`).

### Arrangement is declared once

How a key is laid out where it is drawn is declared on its definition,
once, and read by every door (decided 2026-10-20, from the Form API
review's second change and section 3.4 of the reviews' synthesis): a
group (a fieldset or a details, with its label and whether it starts
collapsed), a weight, a placeholder, a prefix, a suffix and a help text,
in a `Resource\Arrangement` that an owner sets in `defineInputs()` with
`DefinitionMetadata::setArrangement()`. Why: one place to describe a
form, read by the operation form, every plugin host (a block, a
formatter, a condition, a field type, which had no seam short of
`hook_form_alter()`), the example's standalone form and the React app. The
cosmetic layer was a second place, and only one door read it: the React
page never saw the content type's groups, and a block had none to give.

- **What it may say is presentation only.** No rule, no default, no
  requiredness, and no path moves: a key in a group is posted, stored and
  sent at its own path. The form builder draws each group where its
  first key is declared, an empty element under `@group.<id>` that the
  container's pre-render fills at render time, as a placed fieldset is
  moved, so extraction, errors and the AJAX callback find every key
  where its value lives. Keys of one frame naming one group must draw it
  alike; the build refuses two that differ. A weight orders a key in its
  frame, or in its group.
- **An alter places and rewords, never removes.** `describe($key,
  arrangement:)` lays an alter's arrangement over the owner's, under the
  rule for everything an alter says of a key it does not own: it may put
  the key in a group, weigh it and reword its texts, and a text it would
  empty where the owner gave one is refused. A shape is a value and
  takes none; an output is never drawn and takes none.
- **Stated as a widget hint.** The served contract writes it under
  `x-surface.arrangement` only when a renderer asks for the widget hints,
  as `x-surface.widget` is written: additive in contract 2, no version
  raised, and the baselines, emitted without the hints, do not move.
- **The placeholder is only ever declared.** The string and number
  widgets drew a key's first example as its placeholder, so an author
  could not give the contract an example without giving the form a
  placeholder, and an empty field showing "+1 555 0100" read as filled.
  An example is now the documents' alone.
- **A map alone in its group is one details** (decided 2026-10-27, from
  [the adopter log of 2026-10-26](adopters-2026-10-26.md), which drew
  "Punctuation" inside "Punctuation"). A map draws its own details, so a
  group around a map of keys from the site, or around an attached
  structure, drew two. Where a group holds one key and that key is a map,
  the form draws the group's details alone: the group's label and
  collapse state, the map's description and required marker moved to it,
  and the map a plain container inside, still under `@group.<id>` for a
  cosmetic layer. A group of several keys, or of one key that is not a
  map, draws as before, so no shipped form's markup moved. The React app
  still draws the map's fieldset inside the group's details.
- **One spelling, `describe()`** (*amended 2026-10-27*, from [the adopter log of 2026-10-26](adopters-2026-10-26.md), where the owner's
  `DefinitionMetadata::setArrangement($definition, new Arrangement(...))`
  beside each key read worse than the Form API form it replaced). An
  owner and an alter arrange a key the same way, `$inputs->describe('key',
  group: ..., weight: ..., placeholder: ..., prefix: ..., suffix: ...,
  help: ...)`, after the key is declared; the six arguments are
  `Arrangement`'s, and the `arrangement:` argument is gone. `Arrangement`
  and `DefinitionMetadata::setArrangement()` stay as the stored form the
  engine reads: `describe()` builds one and lays it over what the key
  has, so the alter's rule above is unchanged, and what the contract
  states (`Arrangement::toArray()`) did not move.
- **The cosmetic layer keeps what is truly one form's**: a borrowed
  element (the content type example's vertical tabs, which gather the
  groups its resource declares, by their `@group.<id>` keys), markup,
  the message and the redirect. `NodeTypeResourceFormCosmetics` lost
  its groups and its hand-tuned weights.

(`Resource\Arrangement`, `Resource\ArrangementGroup`,
`DefinitionMetadata::setArrangement()`, `SurfaceWidgetBase::arranged()`,
`Form\Drawing::withGroups()`, `Form\Drawing::gatherGroups()`,
`SurfaceBuilder::assertGroupsAgree()`, `PropertySchema::arrangement()`,
`ArrangementTest`, `ArrangementFormTest`)

Declined, each in a line:

- **Cutting the cosmetic layer into `hook_form_FORM_ID_alter()`**
  (drupal-architecture): a borrowed element, the message and the
  redirect are still one route's, and a route key is where that route
  says so; a form alter hook would be a second spelling of the same seam.
- **Renaming it the form display, `_surface_display`** (form-api):
  `_surface_cosmetics` is a route key, which [the product keeps its
  name](#the-product-keeps-its-name) holds to, and with the arrangement
  gone from it, what is left is cosmetic.

*Amended 2026-10-28:* a group may say what its keys are for,
`ArrangementGroup::details(..., description:)` (or `fieldset()`), drawn
under its summary or legend on the form and the React page, and stated
as plain text in `x-surface.arrangement.group.description`, only when
declared. Core's performance form says one sentence of its bandwidth
settings, which `surface_examples_performance` declares once.

### The form builder draws, extracts and refreshes

`SurfaceFormBuilder` had grown to 2,973 lines. It is now the service
and its Form API callbacks, and three final internal classes in
`src/Form/` do the work:

- **`Drawing`** answers what the form shows: each key drawn by its widget
  as its arrangement says, a collection with its buttons, a computed key,
  a key that does not apply, a locked key and its reason, and, when the
  container is rendered, the groups and the placed fieldsets.
- **`Extracting`** answers what the form posted: each element read back
  through its widget into the data surface's shape and handed to
  `accept()`, each violation flagged on its element, an error on a
  refinement trigger held for the request, and, on a refinement request,
  which in-progress input still stands against what is stored
  (`refinementOverlay()`).
- **`Refreshing`** answers the rebuild over AJAX: what triggers it, the
  wrappers it replaces by, the container's `#process`, the response and
  what it says changed, and the container's place in a host's own
  element.

`SurfaceFormBuilder` stays the one service.
`SurfaceFormBuilderInterface`, the service id and the constructor's
arguments are unchanged, and the constructor builds the three from the
services it already has. The Form API callbacks stay on the service
under their own names: `refreshSurface()`, `processSurfaceContainer()`,
`processOptionTrigger()`, `preRenderSurfaceContainer()`,
`preRenderRequired()`, `addListItem()` and `removeListItem()`. A render
array names a `#process`, `#pre_render`, `#submit` or `#ajax` callback by
class and method, and the form cache stores that render array, so a
callback that moved would break every form cached before the move. The
first three call the static method of the same name on `Refreshing`, and
`preRenderSurfaceContainer()` calls `Drawing`.

`Drawing` holds the instance of `Refreshing` it wires the container with.
The static helpers the three share, such as `Drawing::elementChildren()`
and `Refreshing::containerKey()`, are public on the internal classes and
not on the service. Nothing changed in what is drawn: the method bodies
moved as they were, the examples' forms render byte for byte as they did,
and the documents and tool definitions did not move.
(`SurfaceFormBuilder`, `Form\Drawing`, `Form\Extracting`,
`Form\Refreshing`)

### Defaults follow the definitions under a stored value

A door that starts from storage lays the declared defaults under what is
stored by the definitions, in one place, `SurfaceInterface::withDefaults()`
(decided 2026-10-27, from the second port of pathauto's settings form,
[the adopter log of 2026-10-26](adopters-2026-10-26.md), entry 17):

- **A map's entries merge one by one**, at any depth: the keys of an
  attached structure, the entries of a map of keys from the site, the
  properties of a plain map, and each module's part of
  `third_party_settings`. An entry the stored map leaves out holds its
  default, as [Maps](semantics.md#maps) says.
- **A list is the stored list.** Two items stored are two items, never
  padded with a third default. Each item of a collection merges with the
  defaults of the structure it is.
- **Variants merge within the chosen variant.** The deciding key, stored
  or at its default, chooses; a value written for another variant stays
  as it is, and the form draws the chosen one from its defaults, as
  before.

Why: the operation form merged with `array_replace()`, one level deep, so
a stored map replaced its default map whole. A map of keys from the site
stored before the site added an entry (a punctuation character, an
entity type) drew that entry empty: a select on "- None -", saved as
`null`, and a locked checkbox that starts checked drawn unchecked. The
pipeline never had the defect: `accept()` fills a child's left-out keys
from its defaults, and `kept()` and the rule for what does not apply read
defaults by nested path from `getDefaultValues()`, which is the whole
tree.

Which doors had it: the operation form (`SurfaceOperationForm`). The
envelope (`GET /surface-api/...`, the React page) and the narrowing (the
schema route's `POST`, the narrowing tool) seeded with the same one-level
merge, but the emitter lays each child's defaults under its value as it
walks, so their values were right; they now seed through the same
method. A plugin host's form (`SurfaceHostFormTrait`) seeds from a
configuration the pipeline already completed, and does too. The read
tool and a write's answer report what storage holds: an entry storage
holds nothing for is left out, never `null`, and a save leaves it
unstored, since [a default storage never held is not
written](#absent-keeps-null-clears).
(`SurfaceInterface::withDefaults()`, `SurfaceOperationForm::buildForm()`,
`ServedOperations::current()`, `ResourceOperationSchemaTool`,
`SurfaceHostFormTrait`, `DefaultsUnderStoredMapTest`)

## Discovery

### Whether an operation creates

Whether an operation creates is on `#[Operation(creates:)]`, read at
discovery, and stamped on the context its method returns, which may say
it too and must then agree (decided 2026-10-20, reversing "on the
context, not on `#[Operation]`"). The catalog, the tool listing and the
kind read it without building or asking anything, so the catalog knows
it for every operation, one that needs a subject included, where it
used to say "once given what it needs", and the tool bridge no longer
reads the signature to guess it. A context that says otherwise is
refused, at discovery when the operation needs nothing, as [a context
under another id](#a-contexts-id-is-the-operation-id) is.
(`OperationDefinition::stamp()`, `ResourceCatalog::describe()`)

### A class that is not there

An alter, operation or variant naming a class that does not load, or
that loads and is a resource or structure but was not discovered (its
module is off: it carries `#[Resource]` or `#[Structure]`, or it is a
plugin that is its own structure), is skipped; one naming a class that
loads and is neither is refused. A disabled module must not break the
site, and a typo must not pass silently. (`ResourceRegistry`)
An `#[Alters]` naming by its id a resource, structure or shape that has
a class is refused with the class to name instead (decided 2026-10-26;
until then it was skipped as a class that does not load): an id names
only a derived resource or a deriver's base, and an id nothing claims is
still skipped. (`ResourceRegistry::assertAltersByClass()`)

### `#[UsesStructure]` into the plugin definition

Core's discovery reads only a plugin type's own attribute, so one
definition alter per plugin host copies `#[UsesStructure]` into the
definition under `UsesStructure::DEFINITION_KEY`, run last so a swapped
class is the one read. That is what lets a catalog list the plugins
that use a structure without instantiating any. (`ResourcePluginHooks`)

### `#[UsesStructure]` is inherited

A subclass inherits its parent's `#[UsesStructure]`; the nearest class
naming one wins. A subclassed block keeps its parent's form, as it
would with `blockForm()`. A subclass of a plugin that is its own
structure is its own structure in turn, inheriting its static methods, so an
alter of the parent's class does not reach it.
(`ResourcePluginHooks::usedStructureOf()`)

### A plugin that is its own structure

`#[UsesStructure]` with no argument means the plugin class is its own
structure: it implements `StructureInterface`, with a static
`defineInputs()` and static `#[RefinesInput]` methods, and its
definition records its own class under `UsesStructure::DEFINITION_KEY`,
so every host builds it exactly as it builds a named structure. Such a
structure is found through the plugin definitions
(`ResourcePlugins::ownStructures()`), not a directory: a plugin lives
where its plugin type says. Its id is `<host type>:<plugin id>`, the
catalog's name for the plugin, unless the class also carries
`#[Structure]`, whose id and access then apply; a class
several plugins share (a deriver's derivatives) is one structure, named
after the first of them in sorted order. The catalog lists it as used
by that same plugin, and an alter names it by the plugin class. The
registry's cache id hashes the plugin-own list beside the compiler
pass's, so a plugin more or less is a different entry. The compiler
pass never sees such a class, so an access class it names is
not registered as an autowired service; the class resolver builds it,
which means no constructor arguments or `ContainerInjectionInterface`.
The example formatter is written this way and the example block is not, so
the two spellings sit side by side.
(`ResourceRegistry::discover()`, `ResourcePluginHooks::usedStructureOf()`)

### What is paid once and what per request

Decided 2026-10-20, from the performance review, measured on this
site (91 definitions, 65 tools, 22 bundles, PHP 8.4, assertions on).
Every layer is keyed by what it was read from and dropped by tag; none
is kept for one account or one language; none holds a stored value.

| Layer | Paid | Kept until |
| --- | --- | --- |
| Discovery's index: every name and id, kind, module, base, whether it names storage, the shapes, the holders of a variant that answers access | once, when a tag of it fires | a bundle created, a form display saved, field definitions changed (`entity_field_info`, `entity_bundles`, `config:entity_form_display_list`), or a cache rebuild |
| One discovery entry per definition | once, and only the entries whose tags fired are written again | its own tags: a field item its field's and storage's configuration (a base field its override's, named before one exists), a bundle resource its bundle's, its form display's and its fields', a class none |
| One tool entry per resource, every tool of it | once per resource, its operations built once for all its tools, under core's lock | that resource's tags: its discovery entry's and every build it was made from |
| The Tool API's item, every tool | read on every listing | any tag a tool was read from; derived again from the tool entries |
| A sealed build for no account | once, in the build cache | a tag it read, a discovery tag, `config:core.extension`, `config:surface.settings` or `config:surface.defaults` ([The static build is cached, the rest is per request](#the-static-build-is-cached-the-rest-is-per-request)) |
| A sealed build | once per request, per kind and account kind | the end of the request, a tag it read, or 32 newer builds ([A build is not cached across requests](#a-build-is-not-cached-across-requests)) |
| A variant the site lists | once per request, when a door reads it | as the build that holds it |
| The OpenAPI document | once, in the build cache | a tag it read; by contract version, site and language |
| A refined surface | once per combination in one document | the end of the document |
| A static access answer | once per context and account kind | 256 newer answers |

Reading discovery again derives everything, because no deriver can say
which bundles exist without being asked; what it no longer pays is
writing, reading and unserializing what did not change. A deriver says
what decides which derivatives exist with
`KnowsDerivativeListTagsInterface`; one that does not has its index
kept under every tag its derivatives carry, as before. An entry whose
tags did not fire but whose content changed is written again when the
index is read again, so a fact a deriver read without a tag is right
again at the next site-wide change. The risk is a tag left out, which
leaves an entry stale until then; the field item, the bundle and the
storage two bundles share are tests for that reason.

Measured, before and after:

| What | Before | After |
| --- | --- | --- |
| Discovery in the bin | one item, 294,090 bytes, read whole by every request (0.5 ms to unserialize, 1.38 MB in memory) | an index of 23,951 bytes (0.02 ms), and 91 entries: classes 1,504 bytes on average, bundles 6,262, field items 3,933 (0.005 ms each) |
| What building a basic page reads | the whole item | the index and 15 entries, 89,630 bytes |
| Tool listing after one field saved | 240 ms, 106 builds, every tool derived | 149 ms, 3 builds, the page's three tools derived and 62 read from their entries; the rest is the Tool API discovering every other module's tools |
| Tool listing, warm | 7.6 ms | 6.1 ms |
| This module's deriver, everything cold | 54 to 58 ms, 106 builds | 45 to 52 ms, 45 builds |
| A dry-run call to a basic page's update tool | 3 builds | 2 |
| A schema request | 2 builds of the operation, and 1 for the account alone | 1, and 1 |
| The schema route's access check | 1 build | none |
| A content type saved | every tool and every definition read again | the index, the page's entry and three resources' tools written again (the page, and two whose keys list content types); the other 90 entries and 24 tool entries kept |

At a hundred content types (an estimate at the measured averages:
about 20 fields shown a bundle, so 2,000 field items and 120 bundles),
the single item would have been about 7.5 to 9 MB, read whole by every
request (about 13 ms and 37 MB unserialized), and over memcached's
default 1 MB item limit, so never stored there. Now the index is about
480 KB of plain arrays (about 0.4 ms), the largest entry about 12 KB, a
request for one bundle reads about 0.6 MB, and a field saved writes two
entries and derives that bundle's three tools, about four builds where
it was about 650.

Two costs are not this module's to remove. The Tool API keeps every
tool in one item read by every listing (1.5 MB here, about 6 to 9 MB at
a hundred content types); the tool entries make deriving it again cheap,
not reading it. And a field saved clears core's field definitions for
every bundle (`entity_field_info`), which reading the index again pays
with core.

Two more were decided with these. Assertions are compiled out in
production, `zend.assertions = -1`, which [Installation](installation.md)
says and why; with them on, a full schema costs up to about 11% more
and a build the same. And base field items shared across bundles (11 of
a basic page's 14 field items are base fields derived again for every
bundle) are deferred to the entity side (ROADMAP item 11), since they
change the id shape.

(`ResourceRegistry::getIndex()`, `ResourceRegistry::rebuild()`,
`ResourceOperationToolDeriver::derive()`, `OperationInputs::derivation()`,
`Conditionals::refinedFor()`, `DiscoveryPerDefinitionTest`,
`ToolDefinitionsPerResourceTest`, `EmitMemoTest`,
`PerformanceBudgetTest`)

### The static build is cached, the rest is per request

Decided 2026-10-24, measured on the development site (312 components,
20 field types, 35 resources; `docs/performance.md` has the method and
the numbers). Four choices, and why:

- **A build for no account is kept across requests.** The declared
  build (`buildDeclared()`) asks no access answer and reads no account,
  so it is a pure function of the code, the site's structure and what
  its providers list: the same for every request until one of those
  changes. It is kept in its own bin, `cache.surface_build`, so a
  site can give it a backend of its own and a development site that
  turns the discovery bin off still keeps it. The key is the memo's:
  the resource, everything the context says (its parameters among
  them), the kind of build and the reference limit, with the current
  and configuration override languages, since a label read from
  configuration is in the language that loaded it. The tags are the
  contract: what the build read (each provider's list, the site's
  defaults), each discovery entry it built from, discovery's index, and
  `config:core.extension`, `config:surface.settings` and
  `config:surface.defaults`, which every build depends on. One
  that expires or varies by a cache context is not kept. The request's
  memo stays in front of it. A build for the account alone
  (`buildStatic()`) or with every answer (`build()`) is not derived
  from the kept one: its access answers lock or leave out keys while it
  is built, which a sealed build cannot be given after the fact, so it
  is built in each request, by the memo once.
- **A sealed build stores as it is, or is refused.** Its definitions are
  plain, a refiner is a class and a method or an alter by its service
  id, an access answer carries its cacheability, a provider's list is
  data. Core's map definitions drop the properties they were given when
  serialized, so a data surface stores each map with its properties
  beside it and gives them back when it wakes (`SerializedMaps`). A
  structure read from a schema is registered by building its owner, so
  a kept build stores the definitions of those it built, and a request
  that reads it registers them again. One that holds what cannot be
  stored (a closure, an object of an anonymous class, the container a
  service held whole brings) is refused with a message naming the
  resource, the operation and what could not be stored, and nothing is
  kept.
- **A variant the site lists is built when a door reads it.** Variants
  from the site, listed by a provider or read from a schema, are sealed
  by value, label and access answer, and each one is built the first
  time something reads it, then kept (`VariantToSettle`). Kept with a
  build for no account it is stored as what builds it, so the cache
  holds what the documents state and not every component of a theme;
  the structures its owner's list reads are registered again from that
  list when one is built. What each door builds: the full schema and
  the tool listing the variants they state (all of them within the
  site's list limit, none past it); the schema for chosen values and
  the form the chosen one; the pipeline every variant at a key it
  judges, since a secret any variant declares is never returned under
  that key; the catalog all of them, so a refusal inside one is listed
  under the operation that holds it, as before. A variant a class
  declares, an attached child, a collection's item and a structure
  mixed in are built with their owner: they are the owner's own
  definitions (an attached child's map is the parent's, a mixin's
  members are the owner's keys), and a refusal in code is found by the
  build. Lazy equals eager: the never-stricter, every-door and round
  trip tests run on it unchanged, and every example's documents and
  tool definitions, and the site's catalog, are byte for byte what the
  build that built everything gave. What a variant built later read is
  what a caller that cached something made from it asks for
  (`SettledCacheability`), once it has made it: the form, the tool
  listing, the memo when a tag is invalidated.
- **One prepare per submit.** The operation form's validation runs the
  whole pipeline dry, prepare included, so a refusal storage raises is
  said on its element; the submit commits what that run prepared
  (`SurfacePipelineInterface::commitDryRun()`) when the values are
  the ones it judged and a fingerprint of what storage holds is the one
  it was judged against, and runs the whole submit otherwise. The host
  form trait validates without preparing, so it prepares once already.

The OpenAPI document is kept the same way, by its tags, the contract
version, the site and the language, and served with an `ETag` and 304.

(`Resources::buildAs()`, `Resources::keep()`, `Resources::settleVariant()`,
`VariantToSettle`, `SerializedMaps`, `SettledCacheability`,
`ResourceRegistry::exportRead()`, `SurfaceOperationForm::submitForm()`,
`OpenApiDocument::build()`, `BuildCacheTest`, `LazyVariantsTest`,
`OnePreparePerSubmitTest`, `BuildBenchmarkTest`)

*Amended 2026-10-28,* from a site at PHP's stock 128 MB `memory_limit`,
where example 12 ran out of memory. On the development site (312
components; each page a Drush sub-request as user 1, the peak PHP
allocated) example 12 peaked at 144 MB, example 13 at 184 MB, the
spotlight's schema at 132 MB and its React page at 114 MB. Five choices:

- **A provider may answer an entry when a door reads it.** A provider
  implementing `EntrySchemasInterface` lists each entry by value and
  label alone; `entrySchema($id)` and `entryDefs()` answer what `schema:`
  and `addDefs()` would have said. The build registers each such entry
  by name, its inputs `SiteEntries::fill()`, and reads nothing of it.
  `ComponentProps` and `ComponentSlots` are such providers, so building
  the spotlight reads no component. Why: the slots' defs are 13 MB as PHP
  arrays at 312 components (2.2 MB serialized), and every build of the
  spotlight wrote them again, two to four builds a request, whether a
  door read an entry or not.
- **A prepared document is shared.** `SchemaReader::prepared()` reads a
  document that holds no object as it is, not through JSON, writes a
  subschema again only where it changes, and keeps a large one (from
  64 KB of JSON, eight at most) by a hash of its JSON. Preparing the
  slots' defs held 18 MB beside the 13 MB they were read from, and
  peaked 36 MB above them; it now holds 0.2 MB more and peaks 1.7 MB
  above. Why: decoding the JSON made a copy that shared no string with
  the list, and each of the three passes after it copied every array.
- **A document is asked for secrets before its variant is built.** A key
  read from a schema is secret only where the schema says `writeOnly:
  true`, or where it holds a named shape. A variant held unsettled whose
  document says neither, at any depth, holds no secret
  (`SchemaReader::holdsNoSecret()`), so the pipeline's walk for secrets,
  `Variants::keptSecrets()` and every door's projection leave it unsettled;
  for an entry a provider answers when read, its schema is read and the
  variant is not built. A secret any variant declares is still never
  returned under its key. Why: example 13's five tool calls built 6,142
  variants to learn that none held a secret.
- **The memo keeps a build that varies by the interface language.** Core's
  language module puts `languages:language_interface` on every
  configuration object (`LanguageConfigFactoryOverride`), so on a site
  with it every build carries that context, and the memo refused every
  one: no build and no structure read from a schema was reused, and each
  variant built its whole tree again (281,821 builds and 1.8 GB for one
  OpenAPI document on such a site). The memo's key, the key of a
  structure read from a schema and the build cache's id name the current
  interface language and the configuration override language, so that
  context is kept and stored. No other language type is in those keys,
  so a build varying by one is still built every time. Within one
  request nothing else the build reads varies without its key: the
  reference limit is in the key, the site list limit is read by the
  documents and not by the build, and the account is in the key of every
  build for one.
- **What is left grows with the list.** Reading one slots variant reads
  all of the shared defs, since a slot that holds any component refers
  to every component's props and slots: about 42 KB per component, 13 MB
  at 312. Reading only the defs one entry reaches would take a provider
  that answers one def by name, a reader that fetches a document's defs
  one at a time, and read structure names hashed from the def and its
  provider rather than from the whole document. Each entry still costs
  the build a read definition, an attachment and a holder, about 2 KB per
  component. A refusal naming the variants that declare a stray key
  (`Variants::owners()`) builds every variant.

After, the same pages: example 12 at 92 to 96 MB, example 13 at 88 MB, the
spotlight's schema at 60 MB (88 MB for `?choose=/slots=`, which reads the
defs), its React page at 72 MB, and the schema tool at 78 MB where it was
114 MB; every example page answers under a 128 MB limit. About 34 MB of
each page is this development site's own: its discovery bin is the null
backend, so each request derives every tool again
([Performance](performance.md#method)).

(`EntrySchemasInterface`, `SiteEntries`, `SchemaReader::prepared()`,
`SchemaReader::holdsNoSecret()`, `Resources::keepable()`,
`Resources::languages()`, `ManyListedVariantsTest`, `LanguageMemoTest`)

*Amended 2026-10-29:* a build for no account whose context holds what a
request read, a route's value of its path or a reference read from it,
is held for that request alone (`ResourcesInterface::holdDeclared()`):
the build cache is neither read nor written for it, so the cache holds
no entry per value a URL carried, which an update's route that fixes a
key would otherwise write for every thing visited ([A route may fill or fix
what an operation asks
for](#a-route-may-fill-or-fix-what-an-operation-asks-for)).

### The catalog is the site's live map

Decided 2026-10-24. `drush surface:catalog` lists what discovery finds
on the site the command runs on, from any module, derived resources and
read-back structures included, not what a repository declares: a person
reading it wants the thirty-second answer for this site, every
resource, where it lives and how it is reached, and a check wants to
know what fails to build before a caller finds it. Three choices, and
why:

- **Doors are named by the module that opens them.** Each module that
  opens a door implements `CatalogDoorsInterface`, a service tagged
  `surface.catalog_doors`: the main module names the form routes
  (any route naming the operation in `_surface_resource`), the
  tool bridge the tool and the read tool it derived, the HTTP door
  its path, the OpenAPI document its operation, the React example
  (`surface_examples_react`) the page. The
  main module cannot know a submodule's paths, and a door listed where
  its module is off would be a promise nothing keeps. The tool door is
  read off the Tool API's definitions, so it lists the tools that
  exist, not the ones the rule says should.
- **Every operation is built, for no account.** A refusal that only a
  build raises (an operation's context that does not match, a refiner
  watching a key it never declares, a child that cannot be attached) is
  per resource on purpose ([What is paid once and what per
  request](#what-is-paid-once-and-what-per-request)), so listing it
  means building: each operation as its tool listing is
  (`buildDeclared()`, as the anonymous account), so the map is the same
  whoever runs it. A refusal points at the first class its message
  names, the file a reader opens; `--refused` exits 1 when it lists
  anything.
- **What a build reads from a schema is listed, grouped.** A structure
  read from a schema document (a component's props and slots) is
  registered only when a build reads it, so the catalog lists them
  after building everything, by the name each is built by. A theme of
  components gives thousands, so the table and the markdown count them
  where they are read; the JSON lists every one. A key's variants past
  the site list limit are listed by value and their keys not walked
  ([A budget for what the site decides](#a-budget-for-what-the-site-decides)).
- **The repository's catalog is the command's markdown.**
  `docs/catalog.md` is `--format=markdown` for a test site with this
  repository's modules installed, written by `ResourceCatalogTest`
  through `SiteCatalog::markdown()`, the call the command makes; one
  renderer, so the document and the command cannot drift. The static
  layer's own reading (`ResourceCatalog::describe()`) stays what the
  rest of the module reads.
- **The page is the command's table, linked** (decided 2026-10-24).
  `/admin/config/development/surfaces` shows the rows the command
  prints, read from the same call (`CatalogDocument::rows()`), each door
  linked by the module that opens it (`CatalogDoorLinksInterface`), and
  the refusals; `/admin/config/development/surfaces/<id>` shows one in
  full. Under a restricted permission of its own, since it names every
  class and file a refusal points at. No dry run on it: the doors
  themselves run one. The data is no account's, so the page says the
  per-request documents differ by account; `CatalogPageTest` reads the
  page's table as text and holds it to the command's rows on whatever
  the site has.

(`ResourceBuild\SiteCatalog`, `ResourceBuild\CatalogDocument`,
`ResourceBuild\CatalogDoorsInterface`,
`ResourceBuild\CatalogDoorLinksInterface`, `Drush\Commands\CatalogCommand`,
`Controller\CatalogController`, `CatalogCommandTest`, `CatalogPageTest`,
`ResourceCatalogTest`)

## Derived resources

### Derived definitions at discovery

A resource or structure whose keys are the site's (one per bundle, one
per field) is made by a deriver at discovery, `#[DerivesResources]` on a class
implementing `ResourceDeriverInterface`, and cached as an entry of its
own under the cache tags of what it was read from, beside the index of
what there is ([What is paid once and what per
request](#what-is-paid-once-and-what-per-request)). Not a context
handed to `defineInputs()`: it takes nothing but the blueprint, so
that what it declares is the same wherever it is asked for and a static tool
definition, a catalog and the full schema can describe it before
anyone calls; a `defineInputs()` that read a bundle from its context would
declare different keys in every context, with one id. And not one class per
bundle: a bundle is site configuration, made and deleted in the UI, and
a class per bundle would be code generated from config, out of date the
moment a field is added. The plugin system settled the same question
the same way, with derivers. What the deriver reads, it reads at
discovery, so a derivative is listable, addressable and has a tool
without being built; what changes per request is still refinement and
access. (`ResourceRegistry::derive()`, `ResourceRegistryInvalidator`)

### A derived resource has no class

A derived resource or structure is a `ResourceDefinition` like any
other, with `class` NULL and what a class would have said carried
instead: the methods
that fill its blueprints (`ResourceCallable`, a class, a method and the bound
arguments), the base it was derived under and its deriver, a label, a
description, notes on what the derivation could not carry, and its
cache tags. Not a sibling class behind a shared interface: every caller
of the registry reads the same fields of either, and two classes would
have doubled every signature that takes a definition for no difference
any caller acts on. Every caller asks for a definition by `name()`, its
class or, for a derived one, its id, which is what the registry is
keyed by; the build step fills a derived resource's blueprints through its callable and
calls a class's static methods otherwise, and that is the whole
branch. A callable is a class, a method and plain values, so a derived
definition survives the discovery cache, and the refiners bound from it
survive a cached form, where a closure would not. A static callable
method is called on the class; an instance method on the class
resolver's instance, so a deriver's own methods may read site state,
which is what a derived resource is for. (`ResourceDefinition::name()`,
`Declaring::fillBlueprints()`, `Resources::operation()`)

### The id shape

A derived id is `<base>:<derivative>`: the base dotted like
any other id, one colon, then the derivative, dotted and holding no
colon, `entity.content:node.page`, `field.item:node.page.body`. A tool
is `surface:<id>:<operation>` and a read tool adds `:read`;
the tool reads the operation off the last colon, so a derivative with
a colon of its own would be misread, and is refused. The MCP bridge
mangles tool ids, so the derivative adds one colon, between base and
derivative, and dots inside it, which ids already have; a route
parameter, `/surface-react/{resource}`, takes a colon as it is. An alter names one derivative by its id or
every derivative of a deriver by its base, `#[Alters('entity.content')]`.
A string that names no resource, structure or base is skipped, as a class from a
module that is off is skipped, so a mistyped id passes silently where a
mistyped class does not. (`ResourceRegistry::discover()`)

### Derived, then layered

A field item's structure is derived from the item's typed data, since a
field type already says what its item holds, and layered with what
typed data does not say: the field's label and description, required
on the main property, the literal default, the constraints a structure
can state (`Length`, `Range`, `Regex`, `Choice`) and what the settings
narrow (a list's allowed values, a text field's formats, a reference's
targets, a date's form). What typed data says that a structure cannot
(`LinkType`, `ValidReference`) is left to the entity's own validation
at prepare, whose violations land on the key. A field type whose
derivation is thin is written by hand, `#[FieldItemStructure(type:)]`, and
replaces the derived keys and their refiners only: the field's label, operations
and access stay derived, because they are the field's, not its type's.
(`FieldItemDerivation`, `FieldItemStructures`)

### Field access is child access

Each field of a content entity resource is a child invoked through its
own operation, `create` or `update` to match the parent's, so field
access is the child's access, through the three answers access already
had: edit allowed is open, view allowed is locked, neither is omitted.
No mechanism of its own was needed, which is what the child operations
were built for ([One invocation model for both
levels](#one-invocation-model-for-both-levels)). Field access takes
items, so on add it is asked of a throwaway entity of the bundle, as
core's entity form makes one. A field whose operation cannot be invoked,
because its context knows nothing yet (a tool's static definition),
inherits, and its access is still asked; a context that does not say
which field of which entity type has nothing to ask about, and it says
nothing, as [A permission a child cannot
name](#a-permission-a-child-cannot-name) does, and the per call
definition asks in the real context. (`FieldItemAccess`)

### The content entity storage

`ContentEntityStorage` is `surface_examples_entity`'s, not one of the
engine's storage classes: what it reads and writes is what the field item
derivation declares (a child per field, a key per property), which the
engine does not own, and it reads the derivation to know which fields
those are. It maps an entity violation to a key by its property path,
the delta dropped, and one about the whole entity to `@entity`; it
drops one about a field the resource does not carry, or one the account
may not edit, as core's form does. (`ContentEntityStorage::prepare()`)

### Tool definitions after a field changes

*Settled 2026-10-19, with [keys that come from the
site](#keys-that-come-from-the-site).* The registry forgets a derived
definition when the tags it was read from are invalidated, and the Tool
API keeps its plugin definitions apart, with no tags, until a cache
rebuild. An invalidator in the tool door closes the gap without
coupling the engine to the Tool API: the tool deriver remembers the cache
tags of every derived definition and of every resource it built, and an
invalidation of any of them clears the tool definitions, so a field added
to a bundle reaches the `create` tool's static `values` at the next listing.
Since 2026-10-20 the listing derived again is made from each resource's
own entry, kept under that resource's tags, so the field's bundle is
derived again and every other resource's tools are read as they were
([What is paid once and what per
request](#what-is-paid-once-and-what-per-request)).
(`ToolDefinitionsInvalidator`, `ResourceOperationToolDeriver`)

### Keys that come from the site

A resource whose keys are one per thing the site has (a select per
punctuation character, a checkbox per form the site has) declares them
inline, where a key is declared, and names who lists them:

```php
$inputs->addEach('punctuation', PunctuationAction::class, from: PunctuationCharacters::class)
  ->setLabel(t('Punctuation'));
$inputs->attachEach('benches', BenchStructure::class, from: WorkshopBenches::class)
  ->setLabel(t('Benches'));
```

The `from:` class is a provider, `SiteListInterface::list()`, answering
a `SiteList`: an id and a label per thing, optionally what it starts
from and whether it is locked, and the cacheability of what it was read
from. *Changed 2026-10-24:* it was `KeysFromSiteInterface::keys()`
answering a `KeyedList`, beside `VariantsFromSiteInterface::variants()`
answering a `VariantList`; the two had the same contract, so they are
one interface and one list type, each verb reading what it needs of an
entry (a key per thing its id, label, start and lock; a variant its
value, label, and schema or structure) and refusing the rest when the
resource is built, in the words each used; an id holding a dot or a
colon, which no key can be, is refused there, since a variant's value
may hold either ([The surface was counted and cut](#the-surface-was-counted-and-cut)). The build step asks it and declares one key per entry in a
child sealed at the key, which the owner stores: of the type or shape for
`addEach()`, holding the structure for `attachEach()`. Each choice, and
why:

- **Inline, by a provider, at build.** The resource stays static: it
  names a class, as a constraint names an options resolver, and the
  engine asks; what reads the site is a service. Both adopters wrote a
  `#[DerivesResources]` deriver, a service, a derived id and an
  `attach()` by that id for a structure nobody else attaches: 107 code
  lines for pathauto's punctuation, where the original loop was about
  twenty. A deriver stays for what is a resource or structure of its own
  (operations, storage, identity, use at many sites: a bundle, a field).
  Rejected: a deriver base class taking only the provider, which still
  costs a class, an id and an attach by that id, and keeps the keys in
  the discovery cache, apart from the build that reads them; and a
  `#[StructureEach(from:)]` attribute on a structure, which leaves the
  owner attaching it by an id nobody wrote down and cannot say one value
  per thing, which both adopters needed.
- **Two verbs, as there are two for one key.** `addEach()` is `add()`
  per entry, a typed data type or a shape: one value per thing, as
  pathauto's action per character and honeypot's flag per form are, and
  as their stored layouts are. `attachEach()` is `attach()` per entry, a
  structure: a group of keys per thing. Each refuses what the other
  takes, in the words `add()` and `attach()` use.
- **Still static.** [A resource or structure is
  static](#a-resource-or-structure-is-static) holds: the build declares
  the keys once, from what the class says and what the provider lists,
  and both are cacheable. The keys are ordinary keys: the full schema
  states every one, the map is a def of the owner's, `<owner>.<key>`, and
  a structure at the entries is one def every entry refers to.
- **Cacheability travels.** The list's cacheability is the built
  resource's, so the form, both documents and the schema route are kept
  by it, and a thing added to the site is a key at the next request. The
  tool listing is cleared by the tool door's invalidator when one of
  the tags it was built with is invalidated ([settled
  above](#tool-definitions-after-a-field-changes)), and only the
  resources that read the list are derived again. A list with max-age 0
  is refused when the resource is built, naming the provider, always and
  not only under assertions: it costs one comparison, and a key that
  cannot be cached cannot be listed. A list that varies by the account
  (`user`, `user.*`, `session`) is refused too: which keys there are is
  the site's, the same for every account, and who may change one is
  access. The static refiners' container guard does not fit: a provider
  is a service reading injected collaborators, which is its point, and
  the guard sees only the global container. A provider that reads State
  or configuration says so in the tags it declares.
- **Per entry state.** An entry the provider locks is held to what is
  stored (for `attachEach()`, a locked child): drawn fixed on the form,
  `readOnly` in both documents, locked in the tool listing, and a value
  posted for it not taken, and said so in a note
  ([Locked at the top](#locked-at-the-top)). That is per-option state for a map of things,
  a key per option. A plain list of choices, a `list` of `string` with a
  Choice, still has none: an option there is a value, not a key, and a
  lock is per key. An entry may say what it starts from, over the
  blueprint's `default:`; an entry holding a structure may not, since it
  starts from the structure's own defaults.
- **Composition.** The map is the owner's key: a rule of the owner's may
  rule it out, as honeypot's `#states` port does
  (`narrow('form_settings')->when(...)->doesNotApply()`), and names
  nothing inside it, as for an attached child. A rule on every entry is
  the structure's own, declared once and bound in each entry's frame. No
  wildcard path (`narrow('punctuation.*')`) is added here: an owner's rule
  reaching into the entries is the dotted path grammar of Phase C, item
  12, and a value per entry has no rule of its own yet. A shape's alters
  apply at every entry, and a structure's at every entry as wherever it
  is attached. An alter neither adds entries nor mounts under the map:
  the entries are the provider's, and a module that adds one does so
  where the provider reads (pathauto's alter hook stays inside its
  provider). The structure at the entries is built once for the map, so
  its access answers once, as a collection's item's does; it may name no
  storage or identity, its owner storing it. Neither verb is offered to
  an alter, or inside a structure mixed in, which holds no children.
- **Ports on an entry.** Decided by the owner on 2026-10-21:
  `attachEach($key, Structure::class, from:, reads: ['port' =>
  'sibling'])` wires the port of the entry `<id>` to `sibling.<id>`, the
  entry of the same id in a sibling map declared with `addEach()` from
  the same provider. The structure's rules and refiners read the port as
  anywhere ([A port is a live sibling value](#a-port-is-a-live-sibling-value)),
  and a rule on a port may rule the structure's keys out, entry by
  entry. Example 9 is the case: a ticket type's limits do not apply
  while the type is off sale. The wiring names a map, not a path into
  it, because the entries are the provider's: the owner cannot name one
  entry, and the same provider makes the ids match. The build refuses a
  sibling that is no map of keys from the site, holds structures, lists
  its keys from another provider, has no entry for an id, or holds
  another type, and an unwired required port, as attach() refuses a
  wiring. What depends on the port is stated per entry on the owner's
  `allOf`, an `if` on the sibling's entry and a `then` reaching into the
  map at the entry, since only the owner knows both; a locked entry's
  rules are stated too, so both documents leave out what does not apply
  to it. Inside, the map reads the sibling through a port of its own,
  `:<sibling>` (`Port::$through`), and binds each entry's port when it
  is bound (`Surface::withPorts()`), so every door that asks an
  entry for its child hands it the sibling's entry; no document lists
  that port. Rejected: one port per entry on the map, named by the id,
  which an id could collide with; and a path per entry written by the
  owner, which names entries the code cannot list.
- **The cost.** Ported again, pathauto's punctuation is a shape (20 code
  lines, the original's select options) and a `keys()` method on the
  service that lists the characters (9), where the deriver was 44; with
  the service, 92 against 107. Honeypot's forms are a `keys()` method
  (11) where the deriver was 38; with the service, 68 against 95. Each
  resource keeps one line, `addEach()` in place of `attach()`, and its
  documents and tools are what they were, but for the map's def id, the
  shape's one def in place of 33 inline lists, and honeypot's documents
  now kept by the cache tags of the forms it lists.

Open: a per-option lock in a plain list of choices; a rule of the
owner's naming one entry by its id, which waits for a decision from the
owner, since the code cannot list the ids. Closed: ports on an entry
(above). (`SiteListInterface`, `SiteList`,
`BlueprintInterface::addEach()`, `BlueprintInterface::attachEach()`,
`Attaching::buildEach()`, `Attaching::keysFrom()`,
`Attaching::assertEachWiring()`, `Port::through()`,
`Form\Drawing::lockedInside()`, `ToolDefinitionsInvalidator`)

### Keys an alter adds to a content entity

*Open.* An alter may add keys to every content entity resource, and they
are built, validated and offered like any mounted key, but a content
entity has nowhere to keep `third_party_settings`, so its storage does
not keep them. A storage layout that maps a mount onto fields would be
the place to say where they go.

## Plugins

### A plugin host's context

A plugin host builds the structure its plugin names in a bare context
whose id is the host's own verb (`configure`, `field_settings`),
which is no declared operation. The pattern says the host "supplies the
operation" without naming one; a plugin's configuration has no create or
update, only the instance the host holds. (`SurfaceHostTrait`)

### A plugin host cannot overlay a full submission

*Open.* A plugin host (a block, or any plugin using
`SurfaceHostFormTrait`) answers NULL from `surfaceSubmissionPath()`,
so the build a full submission is processed against is built from what
is stored, not from what was submitted. A nested subform cannot know its
position in the input at build time: Form API assigns its `#parents`
only later. So a save without JavaScript that moves a parent and its
dependent together (the example block's entity type and bundle) is refused
by Form API's "is not allowed" select check even when the pair is valid,
because the dependent's select still offers the stored parent's choices.
With JavaScript the parent's AJAX rebuild offers the new choices first
and the case does not arise, and an invalid pair is refused either way.
The fix is a host supplying its subform's parents to
`surfaceSubmissionPath()`. `ExampleBlockTest` pins today's refusal. The
component block's create form is the same case with variants: without
JavaScript the props are drawn for the component stored, none, so a
component chosen and saved at once is refused for the props it requires
("Heading is required."), which `ComponentBlockPlacementTest` pins.
(`SurfaceHostTrait::surfaceSubmissionPath()`)

### Static defaults

A plugin protocol that asks a class for its defaults statically
(`defaultSettings()`, `defaultFieldSettings()`) gets the owner's blueprint
alone, sealed on the spot: no alter, no context. There is no instance
and no context to build with, and alters' keys are mounted where those
protocols do not look. (`ResourcesInterface::defaults()`)

### The example's bundle-field constraint

`ExampleBlockStructure` points at a bundle's fields with a constraint of the
example's own, `SurfaceExamplesBundleField`, and its options resolver.
The pattern fetches such a list through an options source, which is
not built yet, and no core constraint names a bundle's fields.
(`ExampleBlockStructure::fieldOfBundle()`)

### A block embeds a component through its structure

Decided 2026-10-23. `surface_examples_component_block` ships a block,
"Component" (`surface_component`), that embeds a single-directory
component: a component chosen from those the site has, and its props
read from the schema the component declares. Its settings are a
structure of two keys, example 12's two lines under the block's id,
`block.surface_component`. Each choice, and why:

- **A structure and a host, as for any plugin.** The plugin names the
  structure with `#[UsesStructure]` on `TypedBlockBase`, so the
  block host builds it in its `configure` context, draws the settings
  form, holds what is submitted to the structure and stores it in the
  block's settings ([A plugin host's
  context](#a-plugin-hosts-context)). The plugin's own code is
  `build()`: `#type => 'component'` with the component and its props.
  No `defaultConfiguration()`, `blockForm()`, `blockValidate()` or
  `blockSubmit()`. Choosing a component swaps the props below it for its
  own over AJAX, as it does on example 12's page, because the variants
  are the same. The config schema is written by hand, the props
  `type: ignore`, since a plugin's settings schema is not generated yet
  (ROADMAP Phase B, item 5) and which components a site has is the
  site's ([A schema can be read back into a
  structure](#a-schema-can-be-read-back-into-a-structure)).
- **One provider, in the main module.** `ComponentProps` moved from the
  examples to `Drupal\surface\Variants\ComponentProps`. Example 12
  must keep working with the submodule off and the submodule must work
  without the examples, so the provider can live in neither; the main
  module needs nothing new for it, since the component plugin manager is
  core's. One class means the two declarations list the same components
  with the same schemas, and both documents write the same defs under
  each owner's id. Rejected: a copy in the submodule, two places that
  decide which prop types are stored; the provider in the submodule,
  which would make the examples depend on the block module.
- **The component is not required**, where example 12's is. The block
  host constructs the plugin from the declared defaults through the
  pipeline (the block form, the block listing), so a required key needs
  a default the site has, and no component is one every site has: with
  the key required, installing the module failed with "Component is
  required." A block with no component renders nothing, and so does one
  whose component the site no longer has, with no error.
- **The props as stored, less what holds nothing.** The pipeline stores
  null for a prop given no value, and core's check of the props at
  render refuses null for a prop whose type has no null (the card's
  link). A null means the prop holds nothing, so the block leaves it
  out and the component reads its own default, as a prop never passed.
  The render array carries the built structure's cacheability, which is
  the component list's (`config:core.extension`), and the block names
  the extension providing its component as a dependency, so the block
  goes with that module or theme.
- **Props only.** A slot is markup another part of the site fills: its
  definition is a title and a description, with no schema for a value a
  block could store, so the block passes props and no slot. *Changed
  2026-10-23:* each slot holds text, one component or a list of them,
  narrowed to what the slot expects ([A slot holds text, a component, or
  components](#a-slot-holds-text-a-component-or-components)).
- **A placed block is configured through the placement resource.**
  *Changed 2026-10-23.* The submodule declared a resource for a placed
  component block, `block.placed_component`, until the general case
  landed: a placed block of any plugin is now `block.placement`, whose
  plugin chooses its settings, this block's structure for this plugin
  ([A placed block is a resource](#a-placed-block-is-a-resource)). The
  stop-gap resource and its storage are gone, and its two tools with
  them.

Example 13 (`/surface-examples/13`) shows the structure beside example
12's resource, the block's contract, the placement's tools, and three
calls to them: the create that placed the Spotlight block, made again,
an update of its props and its removal, each a dry run. The examples
place the Spotlight block through the create operation in the
default theme's content region, shown on that page alone, once the
block and the placement are on. *Open:* listing the placed blocks; the
create form without JavaScript ([A plugin host cannot overlay a full
submission](#a-plugin-host-cannot-overlay-a-full-submission)). *Closed
2026-10-23:* one resource for a placed block of any plugin, and placing,
moving within a theme and removing blocks through a tool
([A placed block is a resource](#a-placed-block-is-a-resource)); the
component's slots ([A slot holds text, a component, or
components](#a-slot-holds-text-a-component-or-components)).
(`ComponentBlockStructure`, `ComponentBlock`,
`Variants\ComponentProps`, `ComponentBlockTest`,
`ComponentBlockPlacementTest`, `ComponentBlockAjaxTest`)

### A slot holds text, a component, or components

Decided 2026-10-23. The component block and example 12 store a
component's slots beside its props: `slots`, one key per slot the
component's metadata declares. Core lets a slot say what it expects,
`expected`, `minItems` and `maxItems` (core issue 3514072), and leaves
how strictly to apply them to a display builder. Each choice, and why:

- **Two keys one component chooses, from two providers.** `props` from
  `ComponentProps` and `slots` from `ComponentSlots`, each
  `attachVariants(..., by: 'component', from: ...)`. One variant per
  component holding both was weighed first, so that one `anyOf` would
  state both. It would move the props every placed block and example 12
  store one key down, a breaking change to every stored value, and the
  engine has no variants whose keys are the owner's own. Two keys leave
  the stored props where they are and add `slots` beside them; the
  contract states each as `anyOf` of its defs, with one conditional per
  component and key. A component with no slot is a variant with no
  keys, so both keys offer the same components, and neither narrows the
  component to fewer.
- **A slot holds one of three things, chosen by `kind`.** `text`, a
  plain string; `component`, one nested component, the top's three keys
  again (`component`, `props`, `slots`); `components`, a list of those.
  Each kind's key does not apply while `kind` says another (a declared
  rule), so what is stored is what the kind holds and nothing beside it.
  `kind` starts from `text`.
- **Text is a plain string.** Rendered as `#plain_text`, escaped where
  the slot prints it. Formatted text was turned down: the formats a
  person may use depend on the account, and a provider's list is refused
  when it varies by the account; a format is also configuration the
  block would depend on. A slot's metadata says nothing of its value's
  type to choose otherwise.
- **Expectations are refusals.** `expected` narrows the nested
  `component` key to the ids it names, and, for an entry with no colon,
  to the components whose `tags` hold it; an empty list offers no
  component, so the slot holds text alone (the card's `body`). The
  `components` list gets `minItems` and `maxItems` as its Count, and a
  `maxItems` of 1 leaves no list. A slot that expects nothing offers any
  component. Why refusals, where core says expectations: the contract
  states what a slot offers, and a contract that lists an expectation
  and accepts anything would be stricter on paper than in practice. A
  component outside the list is refused naming the ones the slot
  expects: "Choose surface_examples:call_to_action (Call to
  action)."
- **Three deep.** `ComponentSlots::DEPTH` is 3. The slots of the
  component a block names are at depth 1, those of a component in one of
  them at 2, and a slot at depth 3 holds text alone. The cap is in the
  structure, so both documents state it: the def at the cap offers the
  one kind `text`, and a component sent there is an unknown key. Every
  level states each offered component's props and slots, so without a
  cap the schema would not end.
- **Each shape is one def, built once.** The provider lists every
  variant's defs once (`SiteList::addDefs()`):
  `component.props.<id>`, `component.slots.<id>.<depth>`,
  `component.slot.<id>.<slot>.<depth>`, `component.slot.text`, and
  `component.nested.<expected>.<depth>` for the component a slot holds,
  named for what the slot expects (`any` for nothing in particular). A
  read structure is built once per request and context and held at every
  key it is attached at (`Resources::buildRead()`), and what a walker
  asks of a child is answered once per child. On a site with three
  hundred components whose slots expect nothing, a structure per place
  it is used grows with the product of the components at each depth; a
  structure per def grows with their sum: 2,465 defs and a build of
  about two seconds on the development site. The documents and the tool
  listing state none of them there: 312 components are past the site
  list limit, so the props and slots are `deferred`, and the chosen
  component's defs are the full schema's fetched with it chosen, and
  the schema for these answers'
  ([A list left out for size is deferred](#a-list-left-out-for-size-is-deferred-not-refined)).
- **Rendering.** `ComponentBlock::build()` turns each stored slot into
  `#slots`: text as `#plain_text`, a component as `#type => 'component'`
  with its props held and its own `#slots`, a list as a render array of
  each. A slot that holds nothing, or only components the site no longer
  has, is not passed. The render array carries the built structure's
  cacheability, both providers' lists; the block depends on the
  extension of every component it embeds, at any depth.
- **On the block form.** Text and one component are drawn at any depth,
  a nested component's props and slots swapped over AJAX as the top's
  are, each drawn from its own defaults where it was chosen afresh
  (`Form\Drawing::nestedHeld()`), and a submission is read a
  level at a time, each variant through what its own deciding key chose
  (`Form\Extracting::extractFrame()`).
  A list of components inside a slot is drawn as a collection
  (*amended 2026-10-27*,
  [the form never refuses to draw a declared key](#the-form-never-refuses-to-draw-a-declared-key)).

*Open:* formatted text in a
slot; a component that drops a prop a placed block still stores, whose
edit is refused until the stored prop is removed; whether an empty
`expected` is how a component should say it takes text; a slot's
`examples`. (`Variants\ComponentSlots`, `SiteList::addDefs()`,
`Resources::buildRead()`, `ComponentBlock::filled()`,
`ComponentSlotsTest`, `ComponentBlockTest`, `BlockPlacementToolsTest`,
`ComponentBlockAjaxTest`, `ReactNarrowingTest`)

### A placed block is a resource

Decided 2026-10-23. A placed block, the config entity `block.block.<id>`,
is the resource `block.placement`, and the block plugin's settings are
the variant its `plugin` key chooses. An agent places, configures, moves
within its theme and removes a block through the tool door, HTTP and a
form, each validated as any resource is. Each choice, and why:

- **A submodule of its own, `surface_examples_block`.** The placement is the
  block layout's, not any plugin's: every plugin's settings are one
  variant of it. So the general resource lives beside the block layout,
  and the component block, one plugin, needs no resource of its own: its
  stop-gap `block.placed_component` is removed, and example 13's calls
  are the general resource's. Neither submodule depends on the other.
  The component block renders without the placement, and the placement
  offers whatever plugins the site has, the component block among them
  when it is on. Rejected: renaming `surface_examples_component_block`,
  which would tie every placed block to one plugin's module.
- **Three operations, by the machine name.** `create` creates under an `id`
  the caller types, idempotent since the caller names it, and refuses an
  id in use (code `taken`, from the storage); `update` takes the block and
  holds its theme to the one it is in, so moving a block between themes
  is not offered; `delete` deletes it. Each asks "administer blocks",
  the block layout's permission.
- **The settings are variants chosen by the plugin id.**
  `attachVariants('settings', by: 'plugin', from:
  BlockPluginStructures::class)`. The plugins are the ones the block
  layout offers (`getFilteredDefinitions('block_ui')`, less the hidden
  and the broken one), less any plugin that declares a context: which
  context a placed block reads is open, so such plugins are not offered
  yet, and the `plugin` key's description says so. A plugin whose class
  carries `#[UsesStructure]` is its declared structure, built as
  declared: `SiteList::add(..., structure:)` names a structure class where
  an entry otherwise carries a schema. Any other plugin is read with the
  schema reader from `block.settings.<plugin id>`, resolved as typed
  config resolves it (a wildcard type, and `block.settings.*` for a
  plugin with none), less the keys every block has, written as JSON
  Schema by `ConfigSchemaDocument`, the plugin's own defaults each key's
  default. Why both: a declared structure keeps its refiners, children
  and variants, which its schema read back would not (a coded refiner
  cannot be read back), and the config schema is the one description a
  plain plugin has. What a config schema says that JSON Schema says too
  is carried (Choice, Range, Length, Count, NotBlank); the rest is left
  to storage, which holds the whole block to its schema before anything
  is written, so neither document is stricter than the block.
- **The keys every block has are the resource's.** `label`,
  `label_display` (a boolean, stored as core's `visible` or `0`),
  `status`, `weight`, `theme` and `region`, beside `id`, `plugin`,
  `settings` and `visibility`; the provider and the context mapping are
  the storage's to write. Rejected: a structure mixed into every
  variant, which would state the same keys in every variant's def, and
  make a client read the chosen variant to find a block's title.
- **The theme and the region.** The theme is core's `ExtensionExists`
  (`theme`), which a new options resolver, `extension_exists`, reads as
  the installed themes. The region is any installed theme's visible
  region (`SurfaceAnyThemeRegion`) until the theme narrows it: a
  static refiner adds `SurfaceThemeRegion` with the theme beside it,
  and the one resolver answers the two together as that theme's visible
  regions, the ones the block layout offers. Declared with a list, the
  region is a select at every door from the start, which the React page,
  choosing a widget once, needs. Both lists are cached by
  `config:core.extension`. The themes can be
  listed, so the full schema enumerates the region per theme
  (`enumerated`), exact, rather than marking it `refine`. A region the
  theme lacks is refused by name: "The olivero theme has no region named
  header_nope."
- **The visibility is a list of conditions.** Each item is `{id,
  negate, settings}`, its settings the variant its condition id
  chooses, from `ConditionPluginStructures`: the conditions the block
  layout offers (those whose contexts the site can give, less the
  current theme, and the language while the site has one), each its
  declared structure or read from `condition.plugin.<id>`, with a
  `context_mapping` of one key per context it reads, offering the
  site's contexts that fit, the first the default, as the block layout
  assigns it. Storage writes core's map by condition id and refuses a
  condition held twice (`taken`, by its place in the list); a list of
  strings is stored as core's map of each value to itself, the way the
  block layout submits checkboxes. Rejected: a map keyed by condition
  id, since keys from the site attach one structure per map and each
  condition's settings differ.
- **`ConfigEntityStorage` is a resource's storage.** Abstract, its
  subclass naming `ENTITY_TYPE_ID` and `PROPERTIES` (a key to a
  property, or to `['method' => 'setX']`), as `ConfigObjectStorage`
  names its config object. `prepare()` copies the stored entity from its
  exported array (a clone shares the plugin collections of the entity
  the request holds), shapes the values onto it, calculates the
  dependencies its save would, and holds it to its config schema, which
  for a config entity is its validation; `commit()` saves that array.
  The engine's class of the same name in `Pipeline\Storage\` is gone: no
  host used it, and the naming review had found one short name for
  three classes, core's included. `BlockPlacementStorage` adds the
  settings, the visibility and the label to it; `NodeTypeStorage` is
  rebuilt on it, its base field overrides beside, with no change to any
  document.
- **A delete takes no values.** `#[Operation(deletes: TRUE)]`, beside
  `destructive: TRUE`, makes the kind `delete`. The pipeline accepts and
  validates nothing: storage rehearses the removal
  (`DeletableStorageInterface::prepareDelete()`) and removes it
  (`delete()`); the values answered are what was stored, and `changes`
  is `[""]`, the empty pointer, the whole thing. Values sent are refused
  by name (`unknown_key`). Why a flag, not the name: whether a call
  removes the thing is the author's fact, as whether it creates is, and
  a name is how a door picks a default. Why nothing is validated: a
  block whose region its theme dropped must still be removable.
- **At each door.** The tool takes the parameters and `dry_run` and no
  `values`, is a destructive Write, and has no read tool beside it.
  HTTP serves a resource's default delete, the one named `delete` or
  the only delete at a path, as `DELETE` on the thing's
  path, with no body; it answers 200 with the result and no envelope or
  `ETag`, the thing being gone, and takes `dryRun` and `If-Match` as any
  write ([Contract 3](#contract-3-the-operation-is-the-method)); the
  OpenAPI document follows. The operation form draws a confirmation: what
  is removed, that it cannot be undone, and one button with the
  operation's label.

*Open:* context-aware plugins (a placed block's context mapping);
moving a block between themes; Layout Builder's components; a block in
a region its theme hides, which an update reads as stale; a block setting
whose sequence is a keyed map, read as a list; the create form without
JavaScript for a plugin's settings, which are drawn once the plugin is
chosen. (`BlockPlacementResource`, `BlockPlacementStorage`,
`BlockPluginStructures`, `ConditionPluginStructures`,
`ConfigSchemaDocument`, `VisibilityConditionStructure`,
`ThemeRegionOptions`, `AnyThemeRegionConstraint`, `ExtensionExistsOptions`,
`Storage\ConfigEntityStorage`,
`DeletableStorageInterface`, `DeletingStorageInterface`,
`SiteList::add(..., structure:)`, `BlockPlacementResourceTest`,
`BlockPlacementToolsTest`, `BlockPlacementApiTest`,
`BlockPlacementFormTest`, `BlockPlacementReactTest`,
`ConfigEntityStorageTest`)

### A listing is a read-only operation with outputs; a reorder is an update over the collection

Decided 2026-10-23, with example 15, the first module of the series to
declare a config entity type of its own, a venue, and put a resource
over it from the start (`event.venue`). Two points the placement unit
left open are settled on it: how the things a resource holds are
listed, and how they are put in order.

- **No new kind.** A listing is `readOnly: TRUE`, a read; a reorder is
  neither, so it is an update. Both name no thing: they need no
  parameter, and they are addressed at the collection. A `list` or
  `search` kind was weighed and turned down: what a client needs to
  know of a call is still whether it changes anything, may destroy
  what is there, or creates, and a listing's answer is what its outputs
  declare, which a kind would not add to.
- **An operation may take keys of its own.** `#[Operation(structure:
  Structure::class)]` (*renamed 2026-10-24* from `values:`, the word the
  tool's input and the envelope use: [The surface was counted and
  cut](#the-surface-was-counted-and-cut)) names the structure whose inputs a call takes and
  whose outputs it answers, in place of the resource's keys: the
  listing's `offset` (0 to 10000), `limit` (1 to 50, 20 by default) and
  `status`, and its `items`, a list of `{id, label, capacity, weight}`,
  and `total`; the reorder's `venues`, a collection of `{id, weight}`.
  Why: a venue's keys (its name, its rooms) are not what a page or an
  order is made of, and declaring the paging on the resource would put
  it on every form and in the entity's config schema. The resource's
  permission, access class and storage stay its own; the structure has
  no identity and no storage, and one that has is refused when the
  operation is built. The keys are no keys the storage holds, so none is
  marked always held and the build does not hold them to the config
  schema the storage writes to. Refused on a create and a delete, which
  are about the thing's own keys.
- **A read answers its outputs from prepare.** A read only operation is
  never committed, so its outputs are what its storage's `prepare()`
  answered under an output's name ([Who produces
  outputs](#who-produces-outputs)): prepare is the last stage a read
  runs, and one with no side effects. `VenueStorage` reads the venues
  there, lighter first, and answers the page and the total. The tool
  answers them beside the one result shape.
- **`GET` on a collection picks the listing when one is declared.** The
  default read of a collection is a listing where the resource has
  identity: the read only operation named `list` or `read`, or the only
  read there. `GET` runs it with the query string as its values, read
  by the coercion table, and answers its outputs, `{items, total}`, with
  a `Link` to its full schema, the collection's `.../schema`; a value
  refused is 422. A resource with no listing answers `GET` on its
  collection with its create's envelope, as before; with one, the
  create's envelope is its custom method's, `GET .../{resource}:create`,
  and the listing's envelope `GET .../{resource}:list`. Why the outputs
  and not an envelope: the listing's answer is the thing a client asked
  for, and it carries stored values, so it is never stored either, as
  an envelope is not ([A served contract is never
  stored](#a-served-contract-is-never-stored)); the reason contract 3
  gave for no listing, that a listing carries stored values a client
  caches, holds of a tool listing, which is a discovery channel, and not
  of a `no-store` answer read when it is wanted.
- **Items are filtered by access.** The operation's permission gates the
  call (`view event venues`). Each venue is then asked the resource's
  access answer in the listing's own context, knowing that venue's id:
  the listing's permission, then the access class for that venue
  (`Resources::access()`), and a venue whose answer is not allowed is
  left out of the page and of the total. `VenueAccess` refuses a
  disabled venue to an account without `administer event venues`,
  which only the venue can say. Asking the update's answer, the read
  tool's rule ([Reading asks what writing
  asks](#reading-asks-what-writing-asks)), was weighed and turned down:
  an account that may list the venues need not edit them.
- **A reorder is an update over the collection, in one commit.** Its
  storage loads every venue's id and weight; its `prepare()` refuses an
  id that names no venue (`not_found`) and one named twice (`taken`),
  each by its place in the list, and rehearses each named venue with its
  new weight, held to its config schema; `commit()` saves them, all in
  that one write. A venue left out keeps its weight. Its read tool
  answers the current order. Why storage and not a `Choice` on the id:
  the refusal names the venue and the position, and the full schema does
  not list the venues, which change.
- **On HTTP, an update over the collection is a custom method run with
  `PATCH`.** The collection of a resource with identity has no default
  update: `PATCH /surface-api/event.venue` would change every venue,
  which no operation means. So the reorder is `PATCH
  /surface-api/event.venue:reorder`, and every custom method of an
  update is run with `PATCH`, as the default update is: the method says
  the kind. A custom method of any other kind stays `POST`. The tool
  test module's `append` and `clear`, the only custom updates before,
  moved from `POST` to `PATCH` with it.
- **The entity type's config schema is written from the resource.**
  `drush surface:config-schema --write` writes
  `surface_examples.event_venue.*` from `event.venue`, as it writes
  a config object's: the rule [A config object is described by its
  resource](#a-config-object-is-described-by-its-resource) applied to an
  entity type a module declares. The entity class has no form classes,
  no list builder and no links: the resource's routes add, edit and
  remove a venue.
- **Example 2 chooses from the venues.** Its venue and room are the
  venue entities', so a venue added through example 15 is offered at
  the next request with no cache rebuild. A resolver may say its list
  is the whole of its constraint
  (`#[SurfaceOptionsResolver(exact: TRUE)]`), as a `Choice`'s is,
  and the emitter then names that constraint under no `checkedOnServer`.
  *Changed 2026-10-26:* the venue was a constraint of its own, read with
  the room's by one resolver; it is a reference now, `entity:event_venue`
  ([A rule is a keyword first, a list second, code
  last](#a-rule-is-a-keyword-first-a-list-second-code-last)), its
  candidates core's selection handler's: every venue, enabled or not,
  sorted by name. The room keeps `EventVenueRoom` and, added by its
  refiner, `RoomOfEventVenue`, read by `EventVenueRoomOptions` under the
  venues' list tag, every room of every venue.
  *Open:* the capacity's cap per room still reads `Venues::seats()`: a
  static refiner reads only what it watches and cannot say what site
  state it read, so a room example 15 adds is offered with no cap on the
  capacity until a refiner can read a site's list as a resolver does.

*Open:* the placement resource may gain `list` the same way, a page of
the placed blocks of a theme; a listing's next page as a link rather
than an offset; a `sort` input. (`Operation::$values`,
`OperationDefinition::$values`, `Resources::shapeOf()`,
`StorageAdapter::outputs()`, `ServedPaths::listing()`,
`ServedPaths::defaults()`, `ResourceApiController::listing()`,
`OpenApiDocument::listed()`, `ConfigSchemaGenerator::describeEntityType()`,
`SurfaceOptions::isStatedByList()`, `VenueResource`, `VenueStorage`,
`VenueAccess`, `EventVenue`, `VenueResourceTest`, `VenueApiTest`)

### A reference is an id with a list the site decides, listed or looked up

Decided 2026-10-23, with example 16, the sponsorship
(`event.sponsorship`): a key that refers to an entity.

- **One spelling, the data type string.** `add('venue',
  'entity:event_venue', ...)`, `add('contact', 'entity:user', ...)`,
  `add('page', 'entity:node:article', ...)` and the bare `add('thing',
  'entity', ...)`: typed data's own names for an entity, an entity of a
  type and one of a bundle, so an author writes what core already calls
  it. Why not a verb of its own, `reference()`: the type string says the
  type and the bundle in the place a type is said, and `add()` returns
  the definition the rest is written on, as for any key.
- **The value is the id.** The key holds the entity's id as the entity
  type keeps it, an integer where a content entity type's id field is
  one and a string for a config entity, never the entity object: what is
  sent and answered is the id, at every door. *Amended 2026-10-27:* what
  a config storage stores of a content entity is its uuid, which deploys
  where its id does not ([A config object stores a reference by
  uuid](#a-config-object-stores-a-reference-by-uuid)). The tool door
  keeps the bridge's own representation, which already sends an
  operation's entity parameter as its id (`OperationInputs::parameterInput()`):
  the Tool API's `EntityInputDefinition` would hand the pipeline a
  loaded entity, and nothing in `values` is typed that way, so a
  reference in `values` is the id there too. The read tool answers the
  id and no label beside it: its answer is the write's values, and a
  label is no key of the resource; both documents label each listed id
  in their `oneOf`, and the lookup answers labels for the rest. The uuid
  representation is settled for storage and kept off the wire
  (*2026-10-27*).
- **The bare `entity` is two keys, narrowed by a refiner.** An entity
  type, then an id, attached at the key: `entity_type`, a choice of the
  entity types the account may reference one of, and `id`, a string,
  which a static refiner narrows to the entity type chosen with the
  constraint `entity:<type>` builds. So one mechanism serves all three
  spellings, and since the refiner is static the full schema states the
  id once per entity type as a conditional, which a client narrows from
  with no round trip. The parts are registered per key
  (`ResourceRegistry::defineReference()`), as a structure read from a
  schema is, because what the author narrowed the key by is the key's:
  `SurfaceReferenceableType` written on the key (`interface`,
  `targets`) is moved onto `entity_type` when the parts are built. A
  parent cannot name a key inside its child; this is the build writing
  the child from what the parent declared, not the parent reaching in.
- **The selection plugin for candidates and access.** Core's entity
  reference selection handler, `default:<type>` with the bundles as its
  `target_bundles` and the label as its `sort`, answers the options, the
  lookup and the check, for the account asking: one answer in three
  places, and the access filtering is the handler's query's own (a
  node's unpublished nodes, a user's blocked users, node grants). The
  check is core's `ValidReference` for a key that holds one id
  (`SurfaceValidReference`, its subclass): core's validator reads
  the handler off a field's item list, and a key has no field. One
  message for every refusal, core's "This entity (%type: %id) cannot be
  referenced.", so a refusal never says whether an entity the account
  cannot see exists. Rejected: a `Choice` filled at build, which needs
  services in a static class and cannot say "any id the handler
  accepts" past the limit.
- **The threshold, `surface.settings:reference_list_limit`.**
  (*Renamed 2026-10-24* from `reference_enum_limit`, beside
  `site_list_limit`, a post-update moving the stored value: a list of
  candidates is a `oneOf`, never an `enum`; [The surface was counted
  and cut](#the-surface-was-counted-and-cut).) Up to it (50 as shipped) the key lists its candidates: a select, a `oneOf`
  with labels in both documents, an enum in the tool listing, narrowed
  by a client locally. Past it the key is an id: core's
  `entity_autocomplete` on the form, `x-surface.widget: autocomplete`
  and the constraint under `checkedOnServer` in both documents, and a
  sentence in the tool listing naming the lookup. Why a setting and not
  the enumeration cap: the cap counts conditionals a document can
  afford; the limit counts what a person scans and what a tool listing
  should carry, which a site decides. Field references
  (`ReferenceableOptions`) read the same limit, where they read the cap.
  Its sibling, `site_list_limit`, counts the variants and the keys a
  site lists ([A budget for what the site decides](#a-budget-for-what-the-site-decides)).
  The list's cacheability carries the settings' tag; an answer past the
  limit carries nothing and is the wider one, so a cached document is
  never stricter than the site.
- **The lookup is a custom read.** `GET
  /surface-api/{resource}[/{id}]:lookup?key=/venue&q=riv`, every
  resource path's, answered for the operation `GET` serves there, gated
  as it is, through the same handler, ids and labels. Why a custom
  method and not an operation each resource declares: it is the same for
  every resource and needs nothing of one; a resource that declares an
  operation named `lookup` keeps it. The tool listing names it in the
  key's description, since an agent reads the listing first ("Too many to
  list (more than 50): send the id of one; GET ...:lookup?key=/contact&q={text}
  looks one up by its label."); the React app's autocomplete calls it.
- **A marker on the wire.** `x-surface.reference: {entityType,
  bundles?}` on every reference key in both documents, listed or not, and
  `{by: "entity_type"}` on a bare key's id: the reference keyword the
  Portable Schema notes argued for, as an `x-surface` keyword, so a
  client that does not read it reads an id of the key's type.

*Open:* a multi-value reference, a list of
references (`addList()` of an entity type); the entity side's two
shapes, a reference field's `target_id` item (`SurfaceReferenceable`,
no marker) beside a reference key; a lookup tool for an agent with no
HTTP; a custom method's own lookup; a stored id the account may no
longer reference, which core's field validator keeps and this refuses.
(`References`, `EntityReferenceStructure`, `ValidReferenceConstraint`,
`ReferenceableTypeConstraint`, `ReferenceOptions`,
`ReferenceableTypeOptions`, `AutocompleteWidget`,
`ResourceRegistry::defineReference()`, `ServedPaths::LOOKUP`,
`ResourceApiController::lookup()`, `InputDefinitions::withLookups()`,
`OpenApiDocument::lookup()`, `SponsorshipResource`, `ReferenceKeysTest`,
`ReferenceKeysFormTest`)

## Examples

### What the examples are made of

Each example of `surface_examples` keeps its settings in a config
object of its own and names a subclass of `ConfigObjectStorage`
that says which; an example's resource repeats the previous example's
keys rather than extending its class, so each example reads alone on
screen. The machine names keep the word step (`registration_step2`,
`RegistrationStep2Resource`, the `surface_examples.step2` route):
they were never shown to anybody, and renaming them would churn the
catalog and every test for no reader's benefit. A
ticket price is a `float` with a `Range` minimum of 0.01: core's typed
data has no decimal type and no `Positive` constraint plugin. The event
title is stored as a `string`, not a translatable `label`, because a
`label` would make the classic twin's `#config_target` demand a
language code the data surface side never needs. (`RegistrationStep3Resource`,
`PaidTicketStructure`, `surface_examples.schema.yml`)

### The examples' README is held, not generated

The README quotes each example's class from its first attribute down, and
`ExamplesReadmeTest` fails when a quoted block and its file differ, as
`ExamplesToolTest` fails when the three tool answers it quotes change.
Generating it would need a kernel for the answers and a generator for
prose written for a viewer; a drift test keeps the prose free and the
code true.

### An example names its files

Each example lists the files it is made of once, in `Series`: its
resource, storage, structures, shapes, providers, access class, alters,
components, route, config schema entries and shipped settings, each with
a line saying what it is. Its page lists them (`FilesPanel`), a file of
fewer than 80 lines of code shown whole and collapsed, because the
newcomer logs found whole files were what stopped the guessing; the
landing page reads each example's line, what it introduces and how many
files it is made of, from the same list, and the README's Files tables are held to it by `ExampleFilesTest`, which also
fails when the examples have a part no example lists. The links name a
branch from `$settings['surface_examples_branch']`, `next` by
default: asking git on every request costs a process, and a site need
not be a checkout.

## Served contract

### The emitter is the main module's, and widgets are asked for

The served contract's emitter reads any data surface and nothing else,
so it is the main module's (`surface.contract_emitter`), the
canonical contract the operation form's contract panel shows and
`/surface-api` serves. The roadmap rejects widget vocabulary in the
contract, so `x-surface.widget`, the Form API mapping said for a
renderer, and `multiple`, its qualifier, are written only when a caller
passes `widgets: TRUE`; the HTTP door does, for a client such as the
React example's app. The rest
of `x-surface` (locked, dependsOn, refined, stale, emptyOption, fixed, by,
variants and chosen, rules, within, checkedOnServer, enumerated,
refine, live) is the
data surface's own reading of a key, not a widget, and is always written.
The hints are stripped after the schema is built rather than threaded
through it, so the widget a key gets is still read in the same pass as
its shape. (`Contract\ContractEmitter::emit()`)

### No OpenAPI discriminator for variants

Variants are an `allOf` of `if`/`then` on their parent, and carry no
OpenAPI `discriminator` beside them, because there is no place where
OpenAPI's meaning would be true. A discriminator sits on a schema whose
`oneOf`/`anyOf` (or `allOf` inheritance) lists whole alternatives of
that same object, and names a property inside it, mapped by its string
value to a named or referenced schema. The variants' deciding key is a
sibling: at the variants' key (`ticket`) the property does not exist,
and on the parent the alternatives are not whole objects but
conditionals that each constrain only that key, so a reader would
resolve `free` and `paid` as component schema names, which they are
not. A parent with variants at two keys, chosen by two siblings, would
need two discriminators on one object. Restating the parent as a
`oneOf` of whole objects would make it legal and multiply the parent
per variant, per key with variants. The `if`/`then` is
the JSON Schema statement, and `x-surface.by` names the key for a
reader that wants it. (`ContractEmitter::variants()`)

### Two documents, never stricter than the runtime

The emitter writes two documents and does not merge them. The schema as
it stands (`emit()`) is for the present moment: refined for the values
it was built for, with what is true right now under `x-surface` and
`values` and `stale` beside it. The full schema (`emitFull()`) is for
every moment: it reads no value, describes each key as declared, and
states every dependency that can be listed as conditionals, always.
Merged, the first would make a drawing client evaluate JSON Schema
conditionals to learn what a select offers, which it should not need an
evaluator for; kept alone, it is valid only for the current values, and
a schema that is only true of one set of values is not a contract. Both
come from one code path, a frame described for the values it is handed,
which for the full schema are the locked keys the operation knows and
nothing else.

One rule holds for both: the schema is never stricter than the runtime.
What the pipeline accepts, the schema accepts; refinement is a runtime
guard and has the last word. The rule is about the **accepted
document**: what `accept()` makes of the input, after coercion, not the
raw input a caller sent. The Tool API is the precedent: it coerces a
tool's inputs by a documented table first and validates after, and its
matrix tests (`AdvertisedSchemaAcceptanceTest`, `InputCoercionMatrixTest`)
compare the advertised schema with what a tool accepts post-coercion.
So a form's `"50"` for an integer, an empty string, a key left out on a
partial update, and a value posted for a locked key are notations of a
document the schema describes, and the schema need not admit them; the
document `accept()` makes of them, it must. A null on an optional key is
in that document as a value (it clears the key,
[below](#absent-keeps-null-clears)), so the schema allows it. Where a rule cannot be stated (a constraint
with no keyword, named in `checkedOnServer`) or a dependency cannot be
listed or would be too large (free text, a number, a key an alter
mounted, more than the cap), the schema stays at the declared width and
marks the key `refine`, never an approximation that is narrower; where
a refiner is declared runtime, the full schema never runs it and marks
the key `live` ([Static by default, runtime by
declaration](#static-by-default-runtime-by-declaration)).
Exactness is pursued wherever it is possible, which is what enumeration
is for, and is the goal there, not the guarantee. For the schema as it
stands the rule is about the values it was built for and no others.
Audited on 2026-10-18 (`docs/audit-never-stricter.md`, with what each
case became), and held by `AdvertisedSchemaAcceptanceTest`: for each
example and fixture, a table of accepted and refused documents, each
asserted against the pipeline and both documents with a standard
validator, `format` not asserted and `default` not filled. Where the
schema is exact the two agree both ways; where it is `refine` or
`live` it may only be wider. One exception stands: a stale value is
storage's, not an answer, and the full schema states the list as it is,
so a stored value a static list no longer offers is kept by the
pipeline and refused by the full schema, which reads no storage.
(`ContractEmitter::emitFull()`, `ContractEmitter::frame()`)

### The wall is the reference boundary

Each child is written once, as a def under the root's `$defs`, and
referred to from every place it is used, in both documents. The line is
drawn where the module already draws one: a parent cannot refine into a
child, a child cannot watch a parent's key, and a child keeps its own
refiners and alters. Nothing outside a child can change what the child
declares, so the child's declaration can stand alone, and it is the
only unit that can: a plain map is the parent's own and is refined by
the parent's refiners, so it stays inline. One def per id, so
the same class attached twice is one def.

The def is the declaration and the site is the instance. The def is the
child's frame for no values with nothing locked, which is what the
child says about itself whatever context it sits in; a context's lock
and the values of one document are the site's. A label is the parent's
too: the parent names the key, the child does not know where it is
attached.

Narrowing is `allOf` composition. Where the child at a site says more
than its def, the site is `allOf` of the reference and only what
differs. That is "refinement only narrows" in the schema's own grammar:
a validator applies both, and since the narrowing never widens, both
together accept exactly what the child at that site accepts. It also
means the def must be the widest reading of the child, which is why it
is described for no values and unlocked, never from defaults or a
context. Two children claiming one id whose declarations differ (only
a hand-sealed data surface, or two contexts giving one class different
rules, can do it) are two defs, the second `<id>@2`, because neither
would be a narrowing of the other.

Local references only, for now: `#/$defs/<id>`, every def inside the
document that uses it. Serving each resource's or structure's schema at
an address of its own, so a def is fetched once and shared between
documents, is later; it needs a route per id and a decision on what context a
def at its own address is described in.
(`ContractEmitter::define()`, `PropertySchema::reference()`,
`Contract\SchemaResolver`)

### Enumeration is capped

Where a key's refiners have nothing to refine against yet, the emitter
states what they would answer for every combination of their watched
siblings' values, as `if`/`then` conditionals, when every such sibling
offers a list of values and there are at most 64 combinations per key
(`ContractEmitter::ENUMERATION_CAP`). The cap is per key, over the
product of the lists, and bounds two costs at once: the document, which
a person reads in the contract panel and a client downloads on every
GET, and the emission, which refines the whole data surface once per
combination. 64 holds every finite domain the shipped resources and
structures have
(the largest is the example block's field, entity types times bundles) with
room for a real site's bundles, and is small enough that a
country-sized list (some 250) is refused outright rather than written.
Above it the key is `refine`. A key watching free text, a number, a
boolean or a key an alter mounted stays `refine` whatever the count:
its domain is not a list, so there is nothing to enumerate, and
inventing one (sampling numbers, guessing at a license) would state a
rule the refiners never made. Such a key keeps `dependsOn`, and a client
asks the server (`POST .../schema`) when a watched key changes, as before.
A list the site decides is counted otherwise, by its entries, against a
setting ([A budget for what the site decides](#a-budget-for-what-the-site-decides)).
(`Conditionals::enumerate()`)

### A budget for what the site decides

Decided 2026-10-24, with examples 12 and 13 on the development site,
whose themes provide 312 components. A list the site decides is spelled
out in the full schema and the tool listing while it has at most
`surface.settings:site_list_limit` entries, 20 as shipped: one
setting beside `reference_list_limit` (so named since 2026-10-24, one
pattern for the two), with the same schema and a post-update of its
own. Two kinds of key take their list from the site:
variants from the site (`attachVariants(from:)`, and the variants inside
a structure read from a schema, a component in a slot) and keys from the
site (`addEach()`, `attachEach()`). Each key is counted at its own
level: a component in a slot counts the components the slot offers.

- **Past the limit, open and `deferred`.** In the full schema a key with
  variants is an open object marked `x-surface.deferred`, with
  `x-surface.by` naming its deciding key and no def and no conditional
  per variant. A map of keys from the site is an open object marked
  `deferred`, its entries under `x-surface.deferred.entries` by id and
  label alone. Each `deferred` carries `fetch`, the full schema with one choice
  stated ([the next entry](#a-list-left-out-for-size-is-deferred-not-refined)).
  The tool listing states both as an open map, and its description
  names both ways to learn the keys: "Too many to list: choose
  component, then fetch GET
  /surface-api/event.spotlight/schema?choose=/props={component} once per
  component, or call surface:event.spotlight:configure:schema with
  your values, to get this key's schema." *Changed 2026-10-24:* the
  marker was `refine` and the listing named the narrowing tool alone.
  The schema for these answers, built with the
  deciding key's value known, states the chosen variant exactly: its def
  and its one conditional, and no other variant's, since the narrowing
  is what a client asks for one variant; with every variant in it, the
  spotlight's narrowing answered 3.5 MB. With nothing chosen it is open,
  as in the full schema. It states every key from the site, which has no
  deciding key.
- **Why a count and not a smaller depth cap.** The size is the number of
  variants at each level, not the depth: three deep, 312 components were
  2,465 defs, and one level deep they would still be 312 defs and 624
  conditionals. A lower `ComponentSlots::DEPTH` would cut what a slot may
  hold on every site, the one with four components included, where
  everything fits. A count keeps the depth, spells out what a reader can
  scan, and moves only the listing of every variant to a request, so a
  site with few components keeps its exact documents.
- **Why `deferred` and not `refine` or `live`.** The rule is static:
  the provider's list is cacheable and the same for every account, and
  which variant a value chooses is a pure function of the value. Nothing
  about it is unknown ahead of time; it is too long to write. `live`
  says a rule no schema can state, which would tell a client the answer
  may change between two requests with the same values. *Changed
  2026-10-24:* this said `refine`, "static, not captured here: ask
  `POST .../schema` with values", which a client then asked on every
  change; the marker is `deferred` now, for the reasons in [the next
  entry](#a-list-left-out-for-size-is-deferred-not-refined).
- **Why the chooser's options are always listed.** The deciding key's
  `oneOf` is one id and one label per variant: 312 of them are 18 KB. A
  client cannot ask for a variant's keys without a value to send, and an
  agent cannot choose a value it was not shown.
- **Why a setting.** As the reference limit: the count is what a person
  scans and a tool listing should carry, which the site decides. Whether
  a key is spelled out is the setting's, so a document with a list the
  site decides carries the settings' cache tag, and the tool listing is
  derived again when the setting changes.
- **The narrowing tool.** Where an operation's declared listing has such
  a key, at any depth (`SiteListBudget::anyOver()`), the tool door
  derives `surface:<resource>:<operation>:schema` beside the write
  tool: the Read kind, so read only and idempotent, its inputs the
  operation's parameters and an optional open `values`, its outputs
  `schema` and `values`, what `POST .../schema` answers for the same
  values and account, with no widget hints. A resource whose lists are
  all spelled out adds no tool. Its definition is cached for every
  account, as every tool's is; its answer is built for the account that
  calls it. Why a tool and not a sentence naming the HTTP path, as the
  lookup's does: an agent over MCP may have no HTTP client, and the
  narrowing needs the operation's parameters and access, which a tool
  call carries.
- **The catalog.** `drush surface:catalog --resource=<id>` and its page
  list the values of variants past the limit and do not walk their keys:
  one walk per place a structure is used grows with the product of the
  lists, which exhausted 3 GB for the placed block here. Each variant's
  structure is still listed among those read from a schema.
- **Read back.** The reader reads an open `deferred` key with a deciding
  key and no variant as an object like any other, since no variant is in
  the document (`SchemaReader::variantsAt()`), and notes the marker; a
  full schema past the limit does not read back to itself, and
  `ResourceTestBase` reports it by its kind, `site list`.

On the development site, 312 components, measured 2026-10-24 in one
process each:

| What | Before | After |
| --- | --- | --- |
| Example 12's full schema | 3,063,070 bytes, 2,465 defs | 17,818 bytes, no def |
| Its schema for these answers, the card chosen | 3,541,592 bytes, 2,465 defs | 21,426 bytes, 3 defs |
| Its tool definition, inputs and outputs | 1,635,361 bytes | 12,409 bytes |
| The placed block's full schema, create | 3,201,610 bytes, 2,506 defs | 19,400 bytes, 6 defs |
| Its tool definition, create | 27,225 bytes | 10,298 bytes |
| `drush surface:catalog --resource=block.placement` | memory exhausted at 3 GB | 232 MB peak, 3.9 s |
| `/admin/config/development/surfaces/block.placement` | memory exhausted at 3 GB | 232 MB peak |
| `/admin/config/development/surfaces` | 242 MB peak | 230 MB peak |

The build is unchanged: it still reads every component's structure,
since the form and the pipeline hold the chosen one to its keys.

*Open:* a key `refine` for another reason (a watched number, too many
combinations) has no narrowing tool; variants a class declares are not
counted; the build of a resource still reads every variant. *Closed
2026-10-24:* a field type's settings, derived until then and not
counted, come from a provider and are counted
([Derived variants](#derived-variants)). (`Contract\SiteListBudget`, `ContractEmitter::variants()`,
`InputDefinitions::leftOut()`, `OperationInputs::narrows()`,
`ResourceOperationSchemaTool`, `SiteCatalog::keys()`,
`SiteListBudgetTest`, `CatalogMemoryTest`,
`AdvertisedSchemaAcceptanceTest::testListsPastTheSiteListLimit()`)

*Amended 2026-10-27:* a site may also offer fewer components than it
has: `surface.settings:components_offered` lists component ids and
providers, empty for every one, and `ComponentProps` and
`ComponentSlots` read it in one place (`ComponentsOffered`), both lists
carrying its cache tag. The two settings answer different questions
and both stay: the count moves a long list out of the documents and
keeps every entry on offer; the allowlist shortens the list itself,
for the person choosing and for every document. A stored component the
allowlist leaves out stays stored and readable: the chooser stands for
it on its empty option and a save that leaves it keeps it, as any
stored value the list no longer offers; its props are not drawn until
it is offered again. (`ComponentsOfferedTest`)

*Amended 2026-10-28, the owner's decision:* the tool door leaves out
the variants the site lists whatever `site_list_limit` says. The limit
governs the two schema documents, the full schema and the schema for
these answers, and nothing else of variants. There is no new setting.

- **The rule.** In a tool's listing, a key whose variants the site lists
  (`attachVariants(from:)`, a field type's settings, the variants inside
  a structure read from a schema, a component in a slot) holds the keys
  of the variant the stored values choose, each saying "Only when" that
  value, as a class's variants say it, and no other. Where nothing
  stored chooses one (the cached definition, a create, an operation with
  parameters before they arrive) it holds no key. The deciding key keeps
  every value. The description names the two ways the past-limit path
  named: the full schema fetched with the choice, once per value, and
  the narrowing tool, which every such operation now has. Variants the
  build chose, their deciding key locked, are one shape and stated
  whole, as before. Variants a class declares keep the one map. A map
  of keys from the site still follows the limit at the tool door, since
  it is stated exactly key by key.
- **Why.** A tool's definition is sent to the model in every request
  that lists it. Measured on the development site for
  `surface:event.spotlight:configure` with 312 components: left
  out, its normalized input schema is 8 KB; spelled out with the limit
  raised to 1000, 1.6 MB. Twenty spelled-out components would be about
  100 KB per turn. And the Tool API cannot state a shape chosen by a
  sibling key, so under the limit the tool showed every variant's keys
  as one map with none required: large and loose at once. The HTTP
  document is the opposite case: fetched once, cacheable by the site's
  tags, stating the conditional exactly, so a limit there is a real
  trade.
- **One call for the stored choice.** The listing of an operation that
  needs nothing to start from and changes what exists reads what is
  stored, for an account the operation is open to, and the refined
  `values` of an operation with parameters reads it once they arrive. So
  changing a prop of the stored component is one call; choosing another
  is two, the fetch or the narrowing, then the write. Only the stored
  choice is built: no other entry's schema is read
  (`InputDefinitions::deferred()`, `InputDefinitions::anyDeferred()`).
- **What moved on the development site.** The tool documents alone, no
  schema document: example 12's spotlight now states the stored card's
  props and slots (8,075 bytes before, 9,221 after; 1,620,895 bytes
  before with the limit at 1000, 9,221 after); example 14's mailer
  states the stored source's credential keys and gains a narrowing tool
  (1,664 to 1,645 bytes); the placed block's visibility conditions, five
  plugins, are left out (create 5,696 to 4,668 bytes); and the field
  tools state no field type's settings until a field is named, each
  gaining a narrowing tool (`field.instance:create` 11,870 to 3,845
  bytes). Example 9's ticket types are keys from the site, not variants,
  and did not move (2,094 bytes).

*Closed 2026-10-28:* the fetch a create with parameters named omitted
them (`GET /surface-api/field.instance/schema?choose=...` answered 400
until the entity type and the bundle were added to the query). The
fetch now carries every parameter, the identity in the path and any
other in the query,
`GET /surface-api/field.instance/{entity_type}/{bundle}/schema?choose=/settings={field_type}`
([A parameter is identity the caller
knows](#a-parameter-is-identity-the-caller-knows)).
(`InputDefinitions::deferred()`, `InputDefinitions::leftOut()`,
`OperationInputs::listedValues()`, `ResourceOperationTool::mayListStored()`,
`SiteListBudget::anyKey()`, `SiteListToolDoorTest`)

### A list left out for size is deferred, not refined

Decided 2026-10-24, from the owner's finding: the budget marked a list
left out for its size `refine`, which tells an invoker "ask with values
each time". The rule is better than that: static, cacheable and
fetchable on its own; it was only too long to write out. So it has a
marker of its own, and a fetch per choice. This entry amends [A budget
for what the site decides](#a-budget-for-what-the-site-decides) in place
(its marker and its tool text); the budget itself, the limit and how a
key is counted, is unchanged.

- **The marker.** `x-surface.deferred` is on a key with variants, or a
  map of keys from the site, past `site_list_limit`, at its own level as
  before. The key keeps `by`, the chooser keeps its options, and a map
  names its entries. *Changed 2026-10-24, with
  [contract 4](#contract-4-fewer-keywords-nothing-new-on-the-wire):* the
  marker is an object holding `fetch` and, on a map, `entries`; it was
  `deferred: true` with the two beside it. `refine` means one thing again: a static
  rule that cannot be written out (a coded refiner over a number, too
  many combinations); `live` stays a rule only the runtime knows.
- **Why a marker of its own.** An invoker treats each kind differently:
  `refine` is asked again with every set of values, `live` is never
  cached, and a list left out for size is fetched once per choice and
  cached until the site's list changes. A cacheable fetch must not be
  described as ask-every-time: a client that believes it pays one round
  trip per change for a schema it could have held, and an agent reads
  "call with your values" where "fetch once per component" is true.
- **The fetch.** In the marker, `x-surface.deferred.fetch` is an RFC 6570
  template: the full schema's URL with `choose=<pointer>={value}` for
  the key, and a `choose` for each variant on the way to it, so a key
  inside a chosen variant names that choice too
  (`.../block.placement/schema?choose=/settings=surface_component&choose=/settings/props={value}`).
  A client fills `{value}` with the deciding key's value, or an entry's
  id. In a document no route serves (a baseline, the contract panel, the
  tool's answer) the template is its query alone, read against the full
  schema's URL.
- **What the fetch answers.** `GET .../schema?choose=<pointer>=<value>`,
  once or more, answers the full schema with each choice stated as the
  full schema under the limit states it: a variant's def in `$defs` and
  its one conditional, an entry's key in the map; nothing for the other
  choices, and the rest as the plain full schema says it. The key stays
  open and `deferred`, with `variants` naming the defs stated, so the
  answer is never stricter than the pipeline for a value not chosen. A
  pointer below a key with variants passes through the variant a choice
  at that key names, in any order in the query; a choice at a key whose
  variants are spelled out only says which variant to pass through. It
  is built for the account as the full schema is, carries the same
  cacheability (the provider's tags and the settings' among it), and an
  `ETag` of its bytes, which `If-None-Match` answers 304. A choice that
  names no key, no list the site decides, or no value of the list is a
  404 problem naming the pointer, with the list's values when there are
  no more than the limit.
- **Why choices on the schema's own URL.** It is the full schema, with
  more stated: a client that holds one holds the other's meaning, the
  `$id` names what it states, and caching by URL is HTTP's own. A path
  per variant (`.../schema/props/card`) would be a second address for
  part of one document, and could not name two choices at once.
- **Both ways stay.** The narrowing, `POST .../schema` and the `:schema`
  tool, answers as before for an invoker that prefers to ask with
  values. The tool listing and the narrowing tool's description name
  both: the fetch, once per value, and the tool, with the values.
- **React.** A change a `deferred` key depends on fetches the full
  schema for the value chosen, the choices of every deferred key the
  change reaches joined into one URL, keeps each answer in memory for
  the page's life, and narrows from it; a `refine` key is still asked of
  the narrowing. A fetch that fails is asked of the narrowing too.

*Open:* a structure reused at two places in one document (a slot's
component structure in two slots) has one def, so a choice at either
place states the variant at both, and its `fetch` names the first place
it was written from; the page leaves out the declared rules a slot's def
carries in the narrowing's answer, as it does from a full schema that
lists every component. (`Contract\Choices`, `ContractEmitter::fetch()`,
`ContractEmitter::variants()`, `ResourceApiController::schema()`,
`SchemaTagSubscriber`, `InputDefinitions::leftOut()`,
`OperationInputs::schemaDescription()`, `SiteListBudgetTest`)

### A served contract is never stored

The contract response carries the data surface's, the option lists' and
the access answer's cacheability as HTTP cache metadata, and max-age 0:
its values are what storage loaded, and storage does not say how long
that holds, so a cached contract would serve values from before a save.
(`ResourceApiController::respond()`)

### Stale paths travel, stored values do not

A stale value is served as `null`, its path listed under `stale`; a
client sends the paths back, and an empty answer at one of them stands
for the stored value again. It is the operation form's stale marker,
said in JSON, and it keeps a stored value off the wire whether or not a
person may still choose it. Without its path a null clears an
optional key ([Absent keeps, null clears](#absent-keeps-null-clears)),
which is why the path travels. (`ServedOperations::keepStale()`)

### Required means must hold a value

A key the resource requires is in the schema's `required`, whatever its
default: the resource's meaning, which a form's required marker shows.
As in the Tool API, `required` means present and not null and says
nothing about size: an empty list or map is a value, and "not empty" is
`NotBlank` or `Count`. A secret is the one exception, never in
`required` ([A secret is optional, never nullable](#a-secret-is-optional-never-nullable)).
The Tool API bridge copies the same flag for an operation that creates,
and says it in words on one that changes what exists ([Required is the
resource's, and an update tool requires nothing at its
door](#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door)); the served contract's
`values` always carry every key, so presence is never the question
there. The pipeline holds a required list to the same: core's `NotNull`
reads an empty list as null, so it is not asked of `[]`, which every
checkbox unchecked or every row removed sends (until 2026-10-19 a
required list refused `[]` as "This value should not be null.", which
this entry and [Declaring a resource](declaring-a-resource.md#required)
said it did not). A required plain map sent `[]` is held the same
(decided 2026-10-19, by consistency with a child sent null, which is
each of its keys sent null): the empty map, or a map whose keys hold
nothing, is a value, so the map is not refused as missing, and a key
inside it that is required is refused in its own words at its path;
until then core's `NotNull` read it as null and refused the map itself.
(`PropertySchema::required()`, `Validating::validateLevel()`,
`AdvertisedSchemaAcceptanceTest::testRequiredMapSentEmpty()`)

### Absent keeps, null clears

A key can be sent three things, and on an optional key they mean three
things: left out, what it holds stands (what is stored, on an operation
that changes what exists; the declared default, on one that creates),
so a partial update keeps every key it leaves out; `null`, or the empty
string a form sends for nothing, clears it, so "- None -" on an
optional select, an emptied text box and a tool's `null` empty a key;
a value is that value. A required key refuses `null` and `''` as
missing, and a key [its storage always holds](#a-key-its-storage-always-holds)
refuses them as a value it cannot be cleared to: null clears where
clearing is possible. A list keeps what it holds when sent nothing and is emptied
with `[]`; a secret keeps what it holds when sent nothing and is
emptied with its clear marker. The schema says it: an optional key's
type allows `null` (`["integer", "null"]`, and an optional choice's
`oneOf` carries an untitled `{const: null}`), a required key's does
not, nor one its storage always holds, and a secret's does not either,
since `null` does not clear it.

This parts from the Tool API, where a caller's null for an optional
input means the default. A Tool API default is static, so a null and an
absent key there say the same thing; an operation that edits stored
values defaults to what is stored, so if null meant the default a
caller could never clear a key (the base field override storage once
skipped NULL, and an override could not be undone). The
Tool API itself keeps an absent key and a null apart where the
difference is a fact, for outputs (`omit_when_null`). Decided
2026-10-18, after a first reading that collapsed null into "keep" was
tried and reverted. A child sent `null` (an attached structure, or a
chosen variant) is each of its keys sent `null`: its optional
keys clear and a required one is refused in its own words, so the
accepted document is a map its def describes; until 2026-10-18 the
pipeline took `contact: null` whole, which the schema refused.
(`Accepting::acceptKey()`, `Accepting::acceptChild()`,
`PropertySchema::nullable()`)

**Writing back what was read changes nothing** (decided 2026-10-20, from
the deterministic callers and AI agents reviews, B12). A read then a
write of what it returned, or a form saved as it was drawn, is a fixed
point: storage is left byte for byte as it was, nothing is saved, and
the result says so. Three rules
make it one:

- **The stored representation is kept where the accepted forms are
  equal.** A value sent whose accepted form is the accepted form of what
  storage holds is written back as storage holds it: the `takes()`
  comparison a locked key already made, for every key. So the empty
  string a read returned, sent back, stays the empty string, and so does
  `null` sent for a key that holds `''`: both mean nothing, and clearing
  what holds nothing changes nothing. A key that holds a value is still
  cleared by `null` or `''`, as above.
- **Why this and not a read that answers `null`.** The read answers
  what storage holds, as it holds it, which is what every door projects
  ([Every door projects what the read tool
  projects](#every-door-projects-what-the-read-tool-projects)); and on a
  fully validatable schema ([A key its storage always
  holds](#a-key-its-storage-always-holds)) a key that is not `nullable`
  may hold `''` and can never hold `null`, so a read answering `null`
  there would advertise a value the storage refuses, and a write turning
  `''` into `null` would make the object invalid. Keeping the stored
  representation is right under both the three-way rule and that one.
- **A default storage never held is not written.** On a thing that
  exists, a key storage holds nothing for reads as its declared default,
  which the run validates against; left out, or sent as that default (a
  form posts every element it drew, the compliance module's empty
  license and one steward among them), it is what it was, and nothing is
  written for it, a property the default leaves out read as `null`. The
  compliance module's keys, enabled after step 3 was saved, stay
  unstored until a caller sends another value. A thing being created,
  which storage holds nothing of, is written whole, its defaults
  included: storage says whether the operation creates
  (`Pipeline\CreatingStorageInterface`), and a chosen variant that
  is not the one stored starts whole. A plugin reads its
  configuration array whole, so `PluginConfigurationStorage` fills a
  declared default back into what it hands the plugin.
- **A write that changes nothing is not made.** `SurfaceResult`
  carries `changes`, the dotted path of each stored value the write
  changes against what storage held (a map by its own keys, a list
  whole), on a dry run too, which is then a plan; with none, on a thing
  that exists, the pipeline does not call `commit()`, and
  `ConfigObjectStorage::commit()` saves nothing when the data is as
  stored, so no config save event fires and an export does not differ.
  The result is still `committed` (the call went through, and storage
  holds what was accepted); `changed()` says whether anything moved.
  Every door answers `changes`: the write tool as a declared output, the
  React `/validate` and `/submit` beside `notes`.

(`SurfacePipeline::kept()`, `SurfacePipeline::heldAlike()`,
`SurfacePipeline::isDefault()`,
`SurfacePipeline::changes()`, `SurfaceResult::$changes`,
`ConfigObjectStorage::commit()`, `ReadWriteRoundTripTest`)

### A key its storage always holds

Null clears where clearing is possible; where it is not, null is
refused with a message naming the default. Decided 2026-10-19, from the
adopters' gate ("Optional grows an empty choice"): an optional select
offered "- None -" for a key the original form never offered an empty
choice for, because its config always holds a value, and requiring the
key instead would make a tool caller send every one of pathauto's
thirty-three punctuation rows.

Whether a key may hold nothing is a fact of where it is stored, which a
resource does not know, so storage says it, and only where it honestly
can. A storage implementing `Resource\KnowsClearableKeysInterface`
answers `mayClear()` per key, and the build step marks each optional
scalar or list key it answers FALSE for (`DefinitionMetadata::setAlwaysHeld()`),
in the resource and in every part its storage stores under the part's
key. `ConfigObjectStorage` answers by core's own rule, read from the
config schema with what is stored: a value may be NULL unless the
object's schema is fully validatable (the `FullyValidatable` constraint
at its root) and the key does not say `nullable: true`, which is where
core's schema refuses NULL. A schema that is not fully validatable lets
any key hold NULL, so its keys may be cleared, as before; a storage that
does not say, or a key no schema describes, may be cleared too. Opting a
config object into this is its schema's `FullyValidatable`, which also
makes its keys required keys, with `nullable: true` on each that may
hold nothing. A schema written from the resource is fully validatable,
and holds every single value that declares a default
([A config object is described by its
resource](#a-config-object-is-described-by-its-resource)).

On a key so marked:

- **The pipeline** refuses null and the empty string a form sends for
  nothing: "On public holidays cannot be cleared: it always holds a
  value, Closed (closed) by default. Leave it out to keep what it holds."
  Left out, it keeps; a value is that value. Before, the storage's
  schema refused the same null with core's "This value should not be
  null.", after the form had offered it. A list sent null keeps what it
  holds, as any list does; `[]` empties it.
- **Both schemas** leave null out of its type and its choices, as for a
  required key, and keep it out of `required`: absent still keeps.
- **A select or radios** offer no empty choice once a valid one is
  chosen ([the empty option rule](#the-empty-option-rule)); while none
  is, a select shows "- Select -" standing for nothing, as a required
  one does.

A required key is not marked: it holds a value already. A secret is
not either: sent nothing it keeps what it holds, whatever its storage.
(`DefinitionMetadata::holdsValue()`, `Binding::markAlwaysHeld()`,
`ConfigObjectStorage::mayClear()`, `Validating::cannotBeCleared()`,
`PropertySchema::nullable()`, `StockWidgetsTest::testKeyItsStorageAlwaysHolds()`,
`AdvertisedSchemaAcceptanceTest::testOpeningHours()`)

### A secret is optional, never nullable

A secret is advertised optional in both documents and the tool's
`values`, whatever it requires, because left out or `null` it keeps
what is stored (the codec's keep-on-empty); its type is the declared
one, not nullable, `writeOnly`, with no default; the values a document
carries for it are `null`, as the masking rule has always said, which a
reader takes as the key left out; an accepted document leaves a secret
that holds nothing out (`SurfacePipelineInterface::document()`,
2026-10-23, found by example 14's webhook, whose key ships empty). A
required secret never set is still refused by the pipeline as missing.
Before 2026-10-18 a required secret
was in `required` and its type refused the `null` its own `values`
carried. (`PropertySchema::required()`, `InputDefinitions::fromDefinition()`)

### One coercion table, the Tool API's

The pipeline coerces input by the Tool API's table
(`InputTypeCoercionSubscriber`): an empty string as no value on an
optional non-string, JSON strings decoded for a map or a list, a single
item wrapped for a list, booleans from their word and number spellings,
booleans to `0`/`1` for an integer, bare numbers to their spelling for
a string, integral floats and numeric strings to numbers, datetime
strings re-emitted in the declared format, a case or whitespace near
miss snapped onto a declared choice; only when unambiguous, the rest
left for validation. It replaces the module's own casting table. The
Tool API offers the table only as a final event subscriber on a tool's
inputs, and the main module does not depend on the Tool API, so it is
mirrored once, in `Pipeline\Coercion`, and the form door and every PHP
caller reach it through `accept()`; the tool door runs the Tool API's
subscriber and then the same `accept()`, and every rule is idempotent.
Where the Tool API's code goes against its own principle the mirror
keeps the principle: a fractional numeric string for an integer is left
for validation, not truncated. `InputCoercionMatrixTest` holds both
doors to the same accepted value, row by row. The audit's "a boolean
stored for an integer" is an alignment now: `TRUE` is `1`.

Coercion comes first for a second reason: core's typed data throws on
malformed input instead of reporting it. `Map::setValue()` throws
`InvalidArgumentException` for a value that is not an array ("Values
must be represented as an associative array"), `ItemList::setValue()`
for a list that is not an array and `ItemList::set()` for a delta that
is not a number, and `TypedDataManager::getPropertyInstance()` for a key
the map does not declare ("Property ... is unknown"). Each is thrown
while the value is typed, before any constraint runs, so a caller would
get an exception where it should get a violation at the key's path.
`accept()` walks the raw input against the definitions first: it
applies the table, refuses an array for a scalar and a scalar for a map
or a list (`TypeMismatchException`) and a key no definition declares
(`UnknownKeysException`), and turns each refusal into a violation at
its path. Only values that passed are typed and validated, so the
pipeline never reaches those exceptions. Another
contributed module in the same space lets core throw and then parses
the exception's stack trace to learn which key was malformed; coercion
first makes that unnecessary.
(`Pipeline\Coercion`, `Accepting::coerce()`, `Accepting::acceptValue()`)

### The pipeline is read by verb

`Pipeline\SurfacePipeline` held every stage from raw values to stored
values in 2,874 lines. The three verbs before a write are now final
internal classes in `src/Pipeline/`, which the pipeline's constructor
builds from the services it already has:

- **`Accepting`** turns raw values into typed values: defaults, stored
  values and input merged, the coercion table, the three-way rule (a key
  left out keeps what it holds, one sent nothing is cleared, one sent a
  value takes it), locked keys, secrets, computed keys, and the
  violations a refusal of `accept()` becomes.
- **`Validating`** holds the accepted values to the definition refined
  for them: each refusal filed under its key with the path below it, the
  keys it depends on, the values allowed, a key that does not apply, and
  the stale rule.
- **`Conforming`** holds outputs to their declared shape and coerces
  nothing.

`SurfacePipeline` stays the one service, and
`SurfacePipelineInterface`, the constructor and the public constants
are unchanged. It keeps the write path and the document: `kept()`,
`changes()`, `prepare()`, `commit()`, `submit()`, `commitDryRun()`, a
delete, `outbound()` and `document()`. Its `accept()`, `validate()` and
`conformOutput()` call the verbs.

A reader who asks why a key left out kept its value, or why an empty
secret box kept the secret, reads `Accepting`, which applies the
three-way rule and its exceptions. A reader who asks why a refusal names
`tags.2.target_id`, or what it says is allowed, reads `Validating`, which
holds the frame each path and label is read from. `Validating` calls
`Accepting` for a computed key's value and sources and for the sentence
about a key of another variant, and calls the pipeline for `document()`;
`Accepting` reads only the static `Validating::definitionAt()`. None of
the three holds a door-specific service. Nothing changed in what is
accepted, refused or written: the method bodies moved as they were, no
test changed, and the examples' documents and tool definitions are byte
for byte what they were. (`SurfacePipeline`, `Pipeline\Accepting`,
`Pipeline\Validating`, `Pipeline\Conforming`)

### A pattern both engines read alike

A Regex is PCRE and a `pattern` is ECMA-262 (opis, in the suite, reads
it with PCRE again). Its body is carried only when it stays inside a
subset both read alike: literals, character classes with ranges, never
negated; `\d` `\w` `\s`, inside a class or out, and `\b`, only without
the `u` modifier; the quantifiers `*` `+` `?` `{n,m}` (lazy or not),
alternation, capturing, named and non-capturing groups, `^`, and `$`
only with the `D` modifier; the modifiers `u` and `D` are carried, since
ECMA's `$` is already the very end, which is what `D` says. Anything
else (`\A` `\z` `\Z` `(?i)` `(?P<` `(?>` `a++` `[[:alpha:]]` `\x{…}`
`\Q…\E` `\h` `\R`, `.`, a negated class or class escape, look-around,
the `x` `s` `m` modifiers, a `$` without `D`, an escaped delimiter that
is no ECMA syntax character) leaves `pattern` out, which is wider, and
names `Regex` under `checkedOnServer`; `x-surface.patternMessage` still
carries the explanation. Never approximated. Every shipped pattern ends
in `$`, so each gained `D`: a license or a machine name with a trailing
newline was never meant. One consequence outside this module: the Tool
API's own schema leaves a pattern with a modifier out, so the derived
tools no longer advertise the license's. (`Contract\PatternTranslation`)

Decided 2026-10-20, from the JSON Schema tooling review (B20): the
subset carried `\d` `\w` `\s` `\b` under `u` and negated classes, and
the schema refused what PHP accepts in four cases: `/^\d{4}$/uD` with
`٢٠٤٨`, `/^\w+$/uD` with `café`, `/^\bé/uD` with `é`, and `/^[^\s]+$/D`
with a no-break space between two letters. PHP's `u` also sets Unicode
properties (PCRE2_UCP), and ajv reads a pattern with the `u` flag. An
enumeration of every code point, PHP 8.4 with PCRE 10.46 against Node
24, found: under `u`, PCRE's `\d` holds 750 code points ECMA's lacks
(every decimal digit), its `\w` 144,906 (letters, numbers and marks),
so its `\b` sees boundaries ECMA does not, and its `\s` holds U+0085
and U+180E, which ECMA's lacks (ECMA's holds U+FEFF, which PCRE's does
not); without `u`, PCRE reads bytes, and its `\d` and `\w` are exactly
ECMA's ASCII classes (with the `u` flag, without it, or with `v`), under
the C locale and under the C.UTF-8 one Drupal sets alike, its `\s` is
the six ASCII white space characters, all in ECMA's, and none of the
three matches a byte of a character outside ASCII. So without `u` the
four are carried as written, not translated, only because both engines
read `\d` `\w` `\b` as the same ASCII class and ECMA's `\s` is wider;
with `u` none of them is. A negated class is never carried: without `u`
PCRE's matches one byte of a character outside ASCII (`^[^a]{2}$`
accepts `é`, which ECMA refuses), and ECMA's `[^\s]` leaves out the
wider white space. No shipped pattern changed. The rows are
`tests/fixtures/pattern-engines.json`, held to PHP by
`PatternUnderUnicodeTest` and to an ECMA engine by the React app's
Vitest (`src/test/patternEngines.test.ts`): a carried pattern accepts
every value PHP accepts.

### `format` is an annotation

The emitter writes `format` (`idn-email`, `uri`, `date-time`) as JSON
Schema 2020-12 defines it by default: an annotation, which a client may
opt into asserting. Drupal's checks are not the formats' (a URI with a
space, a date with no time), so a client that asserts formats may
refuse what the pipeline accepts; the suite's validator does not assert
them, as a standard one does not. An address is `idn-email` (RFC 6531)
since [contract 2](#contract-2), which admits a non-ASCII local part
as Drupal's validator does; it was `email`, which refused one.
(`PropertySchema::type()`)

### A composite is its parts

Core's two composite constraints are stated through their parts:
`Sequentially` as an `allOf` of its parts' translations, in order, and
`AtLeastOneOf` as an `anyOf`, each part translated as the constraint
alone would be (a `Regex` with `match: FALSE` is `not: {pattern}`).
Decided 2026-10-19, from the adopters' gate, whose honeypot element name
holds three Regex rules in one `Sequentially`, since core keeps one
constraint per name; the emitter named it under `checkedOnServer` only.
When any part has no keyword (a constraint checked on the server alone,
a Regex no pattern carries, a list of values the options resolver never
reads inside a composite), the whole composite stays under
`checkedOnServer`: an `anyOf` short of a part would refuse what that
part allows, and an `allOf` short of one would state a rule that is not
the one checked. Each part's message is the server's to say; only a
key's own Regex fills `x-surface.patternMessage`. Symfony's `All`
applies its constraints to each item of a collection, not all of them
to one value, and core registers no `All` plugin, so it is not read as
an `allOf`. (`PropertySchema::parts()`,
`AdvertisedSchemaAcceptanceTest::testCompositesAreTheirParts()`)

### A machine document carries plain text

A label or a description is written for the form, which renders it as
markup: a placeholder in it is escaped ("Double quotation marks
(&quot;)") and its text may hold tags. Both schemas, the React page that
reads them and the tool listing carry its plain text instead: a line
break as a new line, every other tag stripped, entities decoded; an
option's label and description the same. Decided 2026-10-19, from the
adopters' gate, which found escaped placeholders in pathauto's labels
and honeypot's `<br /><strong>` in every machine document, and a
description escaped by the "Depends on" sentence the tool listing
appends ("you&#039;d"). A client that renders nothing reads the text a
person reads on the form; one that renders HTML is handed no markup it
did not ask for. The form renders the markup as before. A tool listing's
label stays translatable, read as plain text when it is read.

A tool listing's labels and descriptions stay lazy objects, translated
when they are read (decided 2026-10-20, from the multilingual review,
B6 and B7): the tool's label is the operation's own markup, the
operation's label rides in the tool's description as an object, and a
description a sentence is appended to ("Depends on", "Required") is
joined as markup, never rendered first. And `PlainTextMarkup` decorates
the original markup rather than rebuilding it from its source: it
renders the original in the language that reads it and reduces that to
plain text, so a plural keeps its count and picks one form. Until this,
the listing was cached in the language that discovered it, so an agent
read the tool in one language and its keys in another, and a plural
description read as both of its forms with a literal `@count`.
(`Contract\PlainText`, `PlainTextMarkup`, `PlainTextDocumentsTest`,
`ToolListingLanguageTest`)

### A refused submit is an answer

*Superseded 2026-10-22 by [contract 3](#contract-3-the-operation-is-the-method):
a refusal is a 422, problem details whose `errors` are the violations,
with `notes` and `stale` beside them; the entries a client branches on
are unchanged.*

`POST .../submit` answers a refusal with 200 and `committed: false`,
the violations as data, never 422 or 400: a value the pipeline refuses
is the expected outcome of asking, exactly as `/validate` reports it and
as the operation form shows it beside the field, and a client renders
both the same way from the same keys. A status code is kept for what is
not an answer about the values: 403 when access refuses the request
before it runs, 400 when the body cannot be read. Access refused inside
the pipeline (a forbidden answer the route did not already give) is
still filed as a violation of the whole call, its path empty and its
code `access`, the pipeline's own rule ([One result at every
door](#one-result-at-every-door)). (`ResourceApiController::submit()`)

### An empty identity key names nothing

Where a created thing now lives is read from the outputs and the
identity the write accepted, and checked against the operation those
build; an identity key the write left empty is not compared, since it
named nothing: a content entity's create knows its id as empty, and its
`id` output says what storage made it. Before, every accepted identity
key was compared, and a create whose id storage gives could never say
where its thing lives. (`ServedOperations::created()`)

### A fingerprint is opt-in

*Since [contract 3](#contract-3-the-operation-is-the-method) the
fingerprint is the read's `ETag`, sent back in `If-Match`, and a
mismatch is a 412; still opt-in.*

The contract carries a fingerprint of the stored values it was built
from, and a submit that sends it back is refused, nothing written, when
storage no longer matches. A submit that sends none is not checked, and
the last write wins, which is what the operation form, the tool and
every PHP caller of the pipeline do. Opt-in, because making it required
would refuse every client that never asked for a contract first (a
script, an agent calling the tool's twin) and would put a rule on the
API the form does not have. The React app opts in; a page can turn it
off with `sendFingerprint: false`. A check, not a lock: it narrows the
window to the moment between the comparison and the commit.
(`ServedOperations::fingerprint()`)

### The page resolves what the schema states

The React page narrows a change itself when the full schema states the
answer, and asks the server for the rest (`POST .../schema`, `/refine`
until contract 3). On a change it collects every
key that depends on the changed one, transitively, through `dependsOn`;
when none is marked `refine` or `live`, nor holds one inside it, it
reads each from the full schema's conditionals for the new values and
sends nothing. When one is, or the resolver meets anything it cannot
read (a stale value it was handed as `null`, a reference left
unresolved, a conditional naming a key outside its frame), it posts to
the values to `.../schema` as before. A dry run and a write always ask: the pipeline has
the last word.

The full schema is fetched once, beside the contract, and never again
in a session. It reads no value: `emitFull()` is handed only the locked
keys the operation knows, so it is the same whatever is stored or
typed, and its cacheability is the option lists', the refined data
surfaces' and the access answer's, never storage's. A write changes
what is stored, which the full schema never read, and no answer carries
a signal that the schema itself moved, so there is nothing to refetch
on. A site that changes an option list during a session (a bundle
added) is answered by the server where it matters, on validate and
submit. The page reads one contract version: a document or an answer of
another, by its `Contract-Version` header or its `x-surface.contract`,
is refused, not read ([Contract 3](#contract-3-the-operation-is-the-method)).

The page's settling mirrors the server's exactly, orphans included,
because a local answer is a stand-in for the server's narrowing and must be the
same answer. An orphan is what makes a save judge the right thing: the
stored room under a moved venue is shown on the empty option and its
path travels back stale, so the pipeline reads it as the stored value
and refuses it, as the form's Save does. A page that kept the orphaned
room, or kept a capacity typed under it, would draw a form the server
never draws and send values the server would have dropped. So the page
applies the same discard rule and the same fixed point, down to
re-settling every orphan on every change (a capacity typed under an
orphaned room falls back to what is stored when the pricing changes),
and a Vitest test holds it to recorded narrowing answers for examples 2
and 3, keyword for keyword. (`narrow()` in `app/src/contract.ts`,
`ResourceApiController::schema()`)

### Contract 2

*Superseded 2026-10-22 by [contract 3](#contract-3-the-operation-is-the-method),
which kept both documents as contract 2 writes them and changed the HTTP
door: the five endpoints of an operation answer 410 now, naming the
method and path that replace each, and every path is a JSON Pointer.*

Decided 2026-10-20, from the DX reviews (group E of
`docs/reviews-2026-10-20/synthesis.md`: section 1, B21 to B24; section
2a, the wire rows; section 3.7). Both documents are written in a version
of the wire format, the contract's own, distinct from a resource's
version: `x-surface.contract`, at the top of both, first under
`x-surface`, and the `Contract-Version` header every route of
the HTTP door answers with, but for an error. Contract 1 is what `main` serves and
what every document said before it had a number; `next` serves 2. Every
wire change the reviews asked for moved together, once, so a client
moves once. The changes, each with why ([the table](served-contract.md#contract-2)):

- **A version of the contract itself.** A resource's version counts the
  breaking changes to its stored values; nothing counted the changes to
  how every document is spelled, so a client could not tell a document
  it does not know from one it does. The React app reads contract 2
  only and refuses another, by the header or by the document. The
  contract diff compares only documents of one contract version: a
  baseline of another is not read keyword by keyword (every keyword may
  be spelled anew) but reported once, "contract version 1 to 2", as a
  change class of its own that fails until `--write` re-pins it, with no
  reason asked, since no resource broke its promise. Each baseline
  records the version it was written in, `contract`.
  ([Evolving a resource](evolution.md#the-contracts-own-version))
- **`resource` and `operation`, not `surface` and `situation`.** The
  document's two keys said the retired words, deferred by
  [the product keeps its name](#the-product-keeps-its-name) to this
  version (ai-agents, smithy, naming, json-schema-tooling). So do
  `created`, the React page's settings and a baseline's own key. The
  `x-surface` keyword, the `surface[...]` form key and the URL paths
  keep the product's word.
- **`$id` on both documents.** Every reference is local, so a tool that
  embeds a document (an OpenAPI bundler) resolved them against the wrong
  root, and two documents could not share one validator
  (json-schema-tooling 2.2). A document served at a route says that
  route's URL, with the operation's parameters; one no route serves (the
  contract panel, a baseline) has none. The top `description`, the
  operation's, is unchanged.
- **No `x-surface` below an applicator** (B21, json-schema-tooling 2.1).
  RJSF merges `allOf` with json-schema-merge-allof, which has no resolver
  for two differing `x-surface` objects, so it fell back to the unmerged
  schema: every room offered, the paid ticket empty. Each marker moved
  to the object owning the applicator: a declared rule's conditional is
  listed by its index in the frame's `x-surface.rules` (the app evaluates
  a rule whatever its keys hold, so it must still tell one apart); what
  a site's narrowing says of a key inside its child is said on the site,
  in `x-surface.within`, by JSON pointer; and a variant's conditional
  says nothing beside its reference, since the key's own
  `x-surface.variants` now maps each value to its def.
  `SchemaResolver::inline()` and the app's `inlineSchema()` read each
  marker back into place before they compose.
- **The declared type beside a keyword one type reads** (B22). A
  narrowing, a `then` or an `if` that states `maximum`, `properties`,
  `required` or a pattern without `type` was refused by ajv's
  `strictTypes` (six of twelve live full schemas) and read by type
  generators as an object. Each now states the declared type, which
  cannot narrow; a narrowing's `required` lists only what it adds to its
  def's, which `strictRequired` also asked. Every live document and every
  baseline compiles under ajv's strict mode.
- **`title` on every def, its id**, so generated type names are stable
  and language-free (json-schema-tooling 2.5). A shape's def titled
  itself by the shape's label before; the label is each key's, beside
  the reference. An inline reading lays no def's title over a site.
  *Amended 2026-10-31:* a def is titled by its structure's or shape's
  label where it has one, and by its id only where it has none; the key
  is the id either way
  ([A resource and a structure may say what they are called and what they are for](#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for)).
- **The union of a key's variants** (json-schema-tooling 2.4). The key's
  property is `anyOf` of a reference to each variant's def, and null
  where it may be cleared, with no `type` beside it (the union says it,
  and a generator would only intersect the two), so a type generator
  writes `Free | Paid | null` where it wrote `{} | null`. It is never
  narrower than the conditional that applies, which still states each
  variant exactly; the lint reads a change to it as a note. The widget
  hint for such a key is `variants`, not `slot`
  ([Variants, not slots](#variants-not-slots)).
- **`refine` and `live`, not `dynamic` and `runtime`** (section 3.7,
  ai-agents change 5). `dynamic` read as "computed"; what it means is
  "ask `/refine` with values". `runtime` sat beside `access: "runtime"`,
  a different fact. A key a runtime refiner narrows is `live`, and an
  access answer only the subject settles is `access: "live"`.
  `enumerated` and `declared` stay: the app evaluates them differently,
  an enumerated conditional only once every key it watches holds a
  value, a rule whatever they hold. One `narrowing` list (ai-agents) and
  a root `exact: false` (json-schema-tooling) were weighed and left for
  later.
- **`checkedOnServer` says what each constraint checks** (ai-agents
  change 5). A plugin id tells a model nothing; each entry is
  `{constraint, message}`, the constraint's own message where it was
  given one, else its plugin's label.
- **No `x-surface.operation.readOnly`** (naming, json-schema-tooling).
  `kind: "read"` says it, and JSON Schema's `readOnly` at the same top
  means locked whole. The attribute keeps it, and the tool reads it; the
  lint now compares `kind`.
- **`idn-email`, not `email`** (B23). Drupal's validator admits a
  non-ASCII local part, which RFC 6531 does and `email` does not, so a
  client asserting `format` refused `jörg@example.com`, which the
  pipeline accepts. `format` stays an annotation
  ([`format` is an annotation](#format-is-an-annotation)).
- **A bound is a number.** Core's timestamp field holds its Range as
  strings, which were emitted as strings and made twenty-eight live documents
  invalid against the 2020-12 metaschema; they are numbers now.

B24, the served contract page saying `runtime` was never in the full
schema, is closed by the page's rewrite. Not changed: `locked: false`
and `dependsOn: []` are still written on every property (a cut
json-schema-tooling proposed), and no `x-surface` metaschema is
published yet. (`ContractEmitter::CONTRACT`, `DocumentHead::settled()`,
`SchemaResolver::restored()`, `ContractDiff::CONTRACT`,
`ContractChange::CONTRACT`, `ContractBaselines::compare()`,
`ResourceApiController::VERSION_HEADER`, `CONTRACT` and
`assertContract()` in `app/src/contract.ts`)

### Contract 3: the operation is the method

*Superseded 2026-10-24 by [contract 4](#contract-4-fewer-keywords-nothing-new-on-the-wire),
which kept every path, method and status below and cut the `x-surface`
keywords that said nothing or said it twice; nothing answers 410 for it.*

Decided 2026-10-22, from the owner's finding: on HTTP each named
operation had grown five endpoints (`.../configure`, `/schema`,
`/refine`, `/validate`, `/submit`), so the operation did not act like
one, and `configure/submit` was a verb on a verb. The tool door already
had it right: one tool per operation, with a `dry_run` flag. Contract 3
rebuilds the HTTP door on the standard shape: the path is the resource,
the method is the operation. `ContractEmitter::CONTRACT` is 3, the
`Contract-Version` header says `3`, and the baselines were re-pinned
(`ContractBaselineTest` with `SURFACE_CONTRACT_WRITE=1`, the site the
suite builds; `drush surface:contract-diff --write` on the demo site
would pin that site's own components as variants). Both documents are written exactly as contract 2 wrote them
but for `x-surface.contract`; what changed is the door, and how every
door spells a path. The endpoint table is in
[the served contract](served-contract.md#the-api).

- **The path is the resource.** A singleton (no identity keys) is
  `/surface-api/{resource}`; one thing with identity is
  `/surface-api/{resource}/{id}`, its identity as path segments in
  declaration order, joined by `/` when there is more than one (up to
  four). Why: a client reads a resource and writes it at one URL, as
  HTTP means it, and an operation's name is the method's, not a path
  segment. The identity segments are the parameters of the operation
  that addresses the thing, which are the resource's identity keys where
  the operation is asked by them (`node.type`'s `type`) and the thing's
  own id where it is asked by that (a content entity's `id`, a field's
  `field`, `node.article.field_x`): `field.instance` declares four
  identity keys, and its update takes none of them by name, so a path made
  of the identity keys could not be mapped to the call. An operation
  addresses one thing when its resource has identity, it does not
  create, and it needs a parameter; any other is addressed at the
  collection, `/surface-api/{resource}`, its parameters in the query
  string (`field.instance`'s create, `?entity_type_id=node&bundle=article`).
  (`ServedPaths::placement()`) *Amended 2026-10-28:* the path holds an
  operation's parameters named for identity keys, in identity order,
  and any other parameter is in the query; the field instance's
  operations take the field by its parts, so its create is `POST
  /surface-api/field.instance/node/article` and its edit `PATCH
  /surface-api/field.instance/node/article/field_x`
  ([A parameter is identity the caller
  knows](#a-parameter-is-identity-the-caller-knows); `ServedPaths::depth()`).
- **The method is the operation.** At each path, `GET` reads, `PATCH` is
  the default update and `POST` on the collection the default create.
  The default of a kind is the operation whose id is the kind's standard
  one (`create`, `update`, `read` or `list`, `delete`), or `configure`
  for an update on a singleton; else the only operation of that kind at
  the path; else none, two of a kind with neither named so leaving the
  kind with no default, and every operation of it is a custom method.
  *Changed 2026-10-23:* the names were Drupal's words (`add`;
  `configure` or `edit`; `remove`), and are the standard five now,
  Drupal's words kept in the labels ([The ids are the standard
  five](#the-ids-are-the-standard-five-the-labels-are-drupals)). A read
  is a default only where no update is: `GET` on a path answers what is
  stored, and with an update there, it is the update's envelope, the
  values a `PATCH` changes; a read-only operation beside it (example
  10's `check`, which takes values and checks them) is a call of its
  own. `GET` on a collection with no update or read answers the default
  create's envelope, its declared defaults and the schema a `POST`
  takes: there is no listing, since a listing would carry stored values
  a client caches ([Required is the
  resource's](#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door)).
  *Changed 2026-10-23:* where a resource with identity declares a read
  only operation that names no thing, `GET` on its collection answers
  that listing's outputs, its query string the values; else the default
  create's envelope as before. The collection of a resource with
  identity has no default update, and a custom method of an update is
  run with `PATCH` ([A listing is a read-only operation with
  outputs](#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection)).
  *Added 2026-10-23:* `DELETE` on a path is its default delete, the
  operation named `delete`, or else the only delete there: it takes no
  body, answers 200 with the result, and leaves no envelope or `ETag`,
  the thing being gone; a delete that is not a default is a custom
  method, `POST .../{x}:{operation}`, as any other ([A placed block is a
  resource](#a-placed-block-is-a-resource)). (`ServedPaths::defaults()`,
  `ServedPaths::writes()`)
- **A custom method for the rest.** Any other operation, of any kind, is
  `:{operation}` after the last segment of the path it addresses:
  `GET /surface-api/event.program:check` its envelope, `POST` a run of
  it, `.../event.program:check/schema` its schema; `field.instance:reuse`
  on the collection. The custom-method convention of resource-oriented
  API design, because it keeps the resource as the path and names the
  verb once, where a client looks for it. A default operation may be
  named this way too. A resource id that carries a colon itself
  (`entity.content:node.page`) is read whole first. Drupal's URL
  generator encodes the colon; the module writes it back as a colon,
  which RFC 3986 allows in a segment, and reads either.
- **The read is the envelope.** `GET` answers `resource`, `operation`,
  `label`, `schema` (the schema for these answers), `values`, `stale`,
  `outputs` when declared, and `notes`, one entry per stale path, code
  `stale`, as a write's result says a stale value it keeps. The
  fingerprint left the body: it is the `ETag`, strong, since it names
  the stored values whatever the bytes of the document, and `Link:
  <.../schema>; rel="describedby"` names the full schema. The response
  is `Cache-Control: no-store, private`: never stored, as before, and
  saying so keeps the `ETag`, which core strips from a response it
  marks uncacheable itself.
- **The schema is a resource of its own.** `GET .../schema` answers the
  full schema itself, `application/schema+json`, not an envelope around
  it: a JSON Schema document at its own URL, the URL its `$id` and the
  envelope's `Link` name. `POST .../schema` with a values document,
  partial or whole, answers the envelope narrowed for those values: the
  old `/refine`, renamed and re-homed as "the schema, given these
  values". It answers the envelope, not the schema alone, because a
  schema for values is read with the values it was narrowed for: an
  orphan is shown `null` and stale, and `discarded` names the answers
  the new choices dropped. A query: `x-surface-kind: read` in the OpenAPI
  document, nothing written. Being a POST it still takes the session's
  CSRF token, as core asks of every unsafe method on the route.
- **A write's body is the values.** `PATCH` and `POST` take the values
  document directly, no `values` wrapper, `PATCH` as
  `application/merge-patch+json` (RFC 7396, whose rule is the module's
  own, [absent keeps, null clears](#absent-keeps-null-clears)) or
  `application/json`; another media type is a 415. The operation's
  parameters come from the path and the query string; the body's
  `parameters` are gone. The stale paths stay, in the `X-Surface-Stale`
  request header, JSON Pointers separated by commas: leaving a stale key
  out keeps its stored value at the top of a document, but an item of a
  collection is sent whole, and only the path can say "keep what is
  stored" inside it, as the form's marker does. The React app sends the
  header.
- **`If-Match`, not a body fingerprint.** A write sending `If-Match` is
  refused with 412, nothing written, when the stored values changed since
  that `ETag` was served; without it the last write wins, opt-in as
  before ([A fingerprint is opt-in](#a-fingerprint-is-opt-in)). `*`
  matches whatever is stored. A tag is compared by its opaque value, weak
  or strong, which RFC 9110's strong comparison does not do: a
  compressing proxy weakens the tag it passes on (the local ddev router
  does), and the tag names the stored values, which compression does not
  change, so refusing the weakened tag would refuse every client behind
  such a proxy.
- **A dry run is a query parameter.** `?dryRun=true` replaces
  `/validate`: the write runs through prepare and stops before commit,
  and answers as a successful write would, the result and its `outputs`,
  `contract` and `created` (empty), with `x-surface-dry-run: true` added.
  Always 200, never 201 and never a `Location`: nothing was created.
  `If-Match` is checked on a dry run too, so a rehearsal fails where the
  write would.
- **A create answers 201.** A written create answers 201 with
  `Location`, the created thing's read
  (`/surface-api/node.type/recipe`), from where
  [it now lives](served-contract.md#where-a-created-thing-lives); with
  no operation that addresses it (a field added to a bundle), 201 with
  no `Location`. The body keeps `created`, which the React page moves
  to. This closes the point the OpenAPI entry left open.
- **A refusal is problem details.** RFC 9457, `application/problem+json`,
  for every error of the API: `type`, `title`, `status`, `detail`, and
  for a refusal (422) `errors`, the result's violations, with `notes` and
  `stale` as extensions; a 412 carries its one `conflict` error. A
  refusal is no longer a 200 ([A refused submit is an
  answer](#a-refused-submit-is-an-answer) is superseded): the status says
  what happened before the body is read, which HTTP tooling (a generated
  client, Swagger UI, a retry policy) acts on, while the body keeps the
  one result's entries, so a program branches on `errors[].code` as it
  did on `violations[].code`. A refusal the pipeline makes of the whole
  call by access or permission is a 403 and one of a subject that names
  nothing a 404, with the same members; a resource locked whole is a 422,
  code `locked`, since the caller was let in and the values are what is
  refused. A 403 the route's access check gives, a 404 or 405 of the
  path, a 400 of the body and a 415 are problem details too, never
  Drupal's HTML page (`ProblemDetailsSubscriber`). Three types are the
  module's own, a URI under this module's served contract page:
  `#refused` (422), `#conflict` (412) and `#gone` (410); every other
  status is `about:blank`, its title the reason phrase.
- **Every path is a JSON Pointer.** A path in a result, at every door,
  is a JSON Pointer (RFC 6901): `/contact/email`, the whole call the
  empty pointer, `~` and `/` in a key escaped. The write tool's result
  and its text lines, `drush tool:run --json`, the HTTP results and
  problem details, the envelope's `stale` and `notes`, the narrowing's
  `discarded` and the `X-Surface-Stale` header. Why: one spelling a
  client can resolve against the document without knowing the module's
  dotted convention, the one JSON Schema itself uses (`within`, every
  `$ref`). Not changed: a message still names a key it depends on the
  way its renderer does, and the form names an element as the form does;
  inside the pipeline a violation keeps its dotted path, and
  `ResultDocument::pointer()` writes it at the door
  ([One result at every door](#one-result-at-every-door)). The write
  tool's output definitions say "JSON Pointer" where they said "dotted
  path", the one change to the Tool API and MCP door's definitions.
- **Contract 2's paths are gone, not redirected.** Every contract 2 path
  (`.../{operation}`, `/schema`, `/refine`, `/validate`, `/submit`)
  answers 410 with problem details naming the method and path that
  replace it, `moved: {method, href}`, the old query's parameters moved
  into the path (`GET /surface-api/node.type/edit?type=article` names
  `GET /surface-api/node.type/article`). A redirect would turn a POST
  into a GET or resend a body to a URL that reads it differently; a 410
  tells a client its call changed shape. The price: a second segment
  that names one of the resource's operations is read as a contract 2
  path, so a thing whose id is an operation's id (a content type named
  `update`) cannot be addressed by its path, and neither can one whose
  id is `schema`. *Changed 2026-10-23:* contract 2's paths carried the
  ids of then, `add`, `edit` and `remove`, so those three are read as
  the operation renamed from them (`.../node.type/edit` as `update`'s)
  and stay gone, not 404 (`ServedPaths::CONTRACT_2_IDS`); a thing
  named `edit` is not addressable by its path either.
- **The OpenAPI document follows.** One path item per resource path,
  the methods on it, custom methods as path items of their own, each
  with a `/schema` path item; an operation that addresses one thing is
  now described, its identity as path parameters and its schema built
  knowing no subject, as its tool's listing is, which closes the point
  [An OpenAPI document is one more door](#an-openapi-document-is-one-more-door)
  left open.
- **Swagger UI and Redoc.** `GET /surface-api/docs` renders Swagger UI
  and `GET /surface-api/redoc` Redoc over the OpenAPI document, each from
  a CDN as a Drupal library, the version pinned and every file held to
  its integrity hash. Swagger UI's "try it out" calls the API with the
  logged-in session: a write carries the token `/session/token` answers
  and, as `If-Match`, the last `ETag` an answer for the same path
  carried. The JSON stays open, as before; the two pages are under a
  permission of their own, `access surface api docs`, because each
  runs a CDN's script on the site's origin, which a site grants to the
  roles it chooses, and `access content` belongs to node, which the
  submodule does not need.
  The code blocks the module renders are highlighted the same way:
  Prism (`surface/prism`, attached by the `surface_code`
  element) from cdnjs, the version pinned, each file held to its
  integrity hash, its core and only the languages the blocks are in, so
  no language is fetched at run time. It takes no permission of its own:
  it is attached only beside a code block, on pages already behind the
  permissions of the example or the operation they show.

The tool and HTTP doors now agree: one operation, one call, a dry run as
a flag. (`ServedPaths`, `ServedTarget`, `ResourceApiController`,
`ProblemDetails`, `ProblemException`, `ProblemDetailsSubscriber`,
`ServedOperationAccessCheck`, `ResultDocument::pointer()`,
`OpenApiDocument`, `OpenApiController::docs()`,
`OpenApiController::reference()`, `ServedPathsTest`,
`ServedContractEndpointsTest`, `ServedSubmitEndpointTest`,
`ResultPointersTest`, `OpenApiDocumentTest`, `OpenApiEndpointTest`)

### Contract 4: fewer keywords, nothing new on the wire

Decided 2026-10-24, by the owner, from group D of [the API consolidation review](reviews-2026-10-24/api-consolidation.md) (section
2.5, D1 to D5), held when groups A to C were built
([The surface was counted and cut](#the-surface-was-counted-and-cut)).
`ContractEmitter::CONTRACT` is 4, the `Contract-Version` header says
`4`, the OpenAPI document's `info.version` is `4`, and the baselines
were re-pinned (`ContractBaselineTest` with
`SURFACE_CONTRACT_WRITE=1`). No path, method or status changed:
contract 3's door stands, so nothing new answers 410; the number is the
signal. Every change removes or moves a keyword; none adds one.

- **D1. No `declared` on a key.** The frame's `x-surface.rules` lists
  every conditional that states a declared rule, and its `then` names
  the key it narrows, so the key's own `declared: true` said it twice:
  the two appeared in exactly the same documents. A client finds a
  key's rules by filtering the conditionals `rules` lists by the key
  their `then` names, which the React app now does for every key.
  `SchemaResolver::restored()` and the app's `restored()` still mark
  each listed conditional `declared` in the inline reading, a mark no
  document carries.
- **D2. `fetch` and `entries` inside `deferred`.** Neither appeared
  without `deferred: true`: one state, three keywords. `deferred` is
  now `{fetch, entries}`, `entries` only on a map of keys from the
  site, and `deferred: true` is written nowhere. `by` stays beside it,
  since a key with variants says `by` under the limit too.
- **D3. No `locked`, and nothing written that says nothing.** Every
  locked key was `readOnly: true` already, with `const` where it holds
  a value; the one `readOnly` key that is not locked is computed, which
  `x-surface.computed` says. In the 33 baselines `locked` said `false`
  381 times of 385. A resource locked whole is `readOnly: true` at the
  top, as before, without `x-surface.locked`. The module's own rule for
  requiredness, appear only when it says something, now holds for its
  keywords: `dependsOn` only where it names a key (297 of 385 were
  `[]`), `refined` and `stale` only when `true`, `chosen` only once a
  variant is chosen, `variants` only where one is declared, `rules`,
  `within` and `checkedOnServer` only when not empty (as they already
  were), and no `x-surface` at all where nothing is left to say. A
  site's narrowing that differed from its def only by `locked` or a
  key's `declared` is no longer written, nor an enumerated conditional
  that carried only such a narrowing; `readOnly` and `const` still say
  the lock. The envelope's `stale` and `notes` stay, always present:
  they are members of an answer every door shares, not keywords.
  Every door that read `locked` reads `readOnly`: the React widgets and
  panel, `NeverStricterTrait`, the matrix and `StaticAccessAgreementTrait`.
- **D4. `collection`, not `list`.** The collection's marker is the
  glossary's word; `list` stays the widget hint for rows of free values,
  which it also named.
- **D5. `live` for a runtime access answer too.** `access` had one
  value, `"live"`, beside the runtime refiner's `live`, and both told a
  client the same: the key's final state is decided per request and the
  schema for these answers settles it. A key under a runtime access
  answer is `live` now, and the React page treats the one marker one
  way: what the server last settled for the key stands, a change it
  depends on is asked of the narrowing, and nothing is cached. A person
  still reads the difference: the tool listing's words for runtime
  access stay on the key's description
  ([Access is static or runtime](#access-is-static-or-runtime-read-from-its-cacheability)).
  The round trip tells the two apart by the declaration: a document
  whose `live` keys come only from access is read back, and reported as
  an access answer (`SchemaRoundTripTrait::readBack()`).
- **The rule for keywords.** A keyword added within a contract is
  additive: a client must ignore an `x-surface` keyword it does not
  know, and reads an absent one as its empty value. A keyword removed or
  moved breaks a client that reads it, so it is what makes a new
  contract, and the new number is how a client tells: a client of
  contract 3 refuses a contract 4 document rather than reading a key
  with no `locked` as open. The contract diff reports a baseline of
  contract 3 once, "contract version 3 to 4", as before. *Added
  2026-10-26:* `uniqueItems: true`, the stock `UniqueItems` constraint's
  keyword, is a JSON Schema keyword added within contract 4, so no
  version bump: it appears only where the same rule was named under
  `checkedOnServer` before, so a client refuses nothing the server
  accepted, and a client that does not check it is as wide as it was.

The examples' documents differ in these changes and the number alone;
their tool input schemas are byte for byte as before, since the
listing is built from the data surface, not from the document, but for
the description of a map of keys from the site past the limit, which
names `x-surface.deferred.entries`. (`PropertySchema::extension()`,
`ContractEmitter::frame()`, `ContractEmitter::variants()`,
`PropertySchema::withoutWidgets()`, `Conditionals::withoutPresent()`,
`SchemaReader::droppedExtension()`, `CONTRACT` and `compacted()` in
`app/src/contract.ts`, `ContractBaselineTest`, `SiteListBudgetTest`,
`StaticRuntimeAccessTest`, `SchemaRoundTripTest`)

### The emitter is one walk and three writers

`ContractEmitter` had grown to 4,300 lines. It is now the walk, and three
final internal classes in `src/Contract/` write what the walk asks for:

- **`ContractEmitter`** walks a document's frames: each key, child,
  variant, collection item and mount, and the defs each child is filed
  under. Its public API, its service and its constructor are unchanged.
- **`PropertySchema`** describes one key as a JSON Schema property with
  its `x-surface`: the type, the keywords its constraints state, the
  allowed values with their labels, the widget hint, what only the
  server checks, a shape's def, and a child's site as a reference with
  only what differs from its def.
- **`Conditionals`** writes what a key's refiners answer as `if`/`then`:
  a declared rule as written, and the static refiners refined for every
  combination of the values they watch. Where neither can be stated, it
  marks the key `refine` or `live` and drops it from `required`. It
  remembers what it refined during one document.
- **`DocumentHead`** writes what sits above the properties: the heading,
  how the operation behaves, the versions, `readOnly` for a resource
  locked whole, the outputs, and the placement rules that move each
  marker to the object that owns it.

The seam is the responsibility, not the document. `emit()` and
`emitFull()` share the walk, and differ only in what `Emission::full()`
answers at each step. One class per document would copy the walk into
both, and the two documents could then drift apart
([Two documents, never stricter than the runtime](#two-documents-never-stricter-than-the-runtime)).
Calls run one way: the walk calls the three writers, and `Conditionals`
and `DocumentHead` call `PropertySchema`; no writer calls the walk, and
the two static helpers they share, `pointer()` and `foreign()`, stay
public on the emitter.
`ContractEmitter`'s constructor builds each writer from the services it
already has, so there is no new container service and none is
door-specific. Nothing changed in what is emitted: the examples'
documents and tool definitions are byte for byte what they were, and
the contract baselines did not move. (`ContractEmitter`,
`Contract\PropertySchema`, `Contract\Conditionals`,
`Contract\DocumentHead`, `ContractBaselineTest`)

### An OpenAPI document is one more door

The base module serves `GET /surface-api/openapi.json` (and
`.yaml`): an OpenAPI 3.1 document of its HTTP door's API
for every operation that can be asked on its own, so any OpenAPI
client, generator or UI drives a resource without reading these pages.
Decided 2026-10-21; its paths are contract 3's since 2026-10-22
([Contract 3](#contract-3-the-operation-is-the-method)): one path item
per resource path, the methods on it, custom methods and `/schema` as
path items of their own.

- **3.1, because the schemas are JSON Schema 2020-12.** A 3.1 schema
  object is a 2020-12 schema, so each operation's full schema goes in as
  emitted, `x-surface` included. 3.0 has no `$defs`, `if`/`then`,
  `const`, type arrays or `unevaluatedProperties`. A 3.0 export would
  have to drop or rewrite the conditionals, and the documents are
  [never stricter than the runtime](#two-documents-never-stricter-than-the-runtime),
  so it could only widen: every enumerated and declared conditional
  gone, `const` as a one-value `enum`, a type array as `nullable`. That
  is a possible later export, judged by the contract diff's
  compatibility classes (a change that only widens), not this door.
- **Its own route, not the contributed `openapi` module.** Its stable
  release (8.x-2.3, for Drupal 10 and 11) writes Swagger 2.0, and its
  3.0.x branch, which writes `openapi: 3.1.0`, has no release and
  depends on core patches. A generator plugin for it is open, for when a
  3.1 release exists; the submodule would declare it optional and keep
  its own route.
- **Built for no account**, as the tool definitions are
  ([A static tool definition is no account's](#a-static-tool-definition-is-no-accounts)):
  each operation is built with `buildDeclared()` as the anonymous
  account, every key as declared, each one an access answer could lock
  or leave out marked `x-surface.live`. So the document is
  the same for every account and cached under the tags of what it was
  read from: discovery's index and entries, every build and option list,
  the site's name, the modules and the anonymous role. It drops the
  account's cache contexts, since it read none. `info.description` and
  the top `x-surface` say that the per-request GET and `/schema` answer
  for the account that asks; `info.version` is the contract's version.
  The route is open to every account: what it states is what the code
  and the site's structure declare, the shape a module's source already
  says, never a stored value; what an account may do is the per-request
  documents' to say, and they still refuse an account the operation's
  permission does not admit.
- **The full schema as components.** Each operation's full schema, no
  widget hints and no `$id`, is split: each def a component named by its
  def id (a character a component name does not allow written `_`), the
  root the operation's values component, and every def pointer, in a
  `$ref` or in `x-surface.variants`, pointed at its component. Nothing
  else in a schema changes. Two operations share a component when they
  describe the def the same way; one that differs keeps its own, its
  name suffixed and its `title` still the def's (its structure's label,
  else its id, *amended 2026-10-31*). Put back together,
  the components are the full schema byte for byte, which a test holds
  for every operation.
- **An update takes a patch.** A key an update leaves out keeps what is
  stored, so its request body takes the values component
  with every `required` dropped where dropping only widens: never inside
  an `if`, a `not`, a `oneOf` or a `contains`, never inside a list's
  `items` (an item is sent whole). A create takes the full schema: it
  requires what the resource requires, as its tool does
  ([Required is the resource's](#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door)).
  *A patch only where one is needed* (decided 2026-10-22): the request
  body's patch is always written, so an update's body is named
  `<values>.patch`. A def the patch reaches where it may loosen has a
  patch of its own only when that patch differs: when the def, or a def
  it reaches the same way, drops a `required`. Any other def is pointed
  at as it is. A def cannot simply be pointed at whole, because absent
  keeps holds at every level: the pipeline accepts an attached child
  or a chosen variant against what it holds, so `{sender: {email}}`
  keeps the stored `name`, and a def that still required `name` would
  make the body stricter than the door. The rule took the components
  from 147 to 140 on the demo site (40 patches to 26, 7 outputs
  components added).
- **Facts to HTTP.** How the operation behaves is stated beside each
  call as `x-surface-kind`, and on its run as `x-surface-idempotent`,
  `x-surface-destructive` and `x-surface-creates`; its label is the
  summary and its description the description. Since contract 3 a run
  answers 200, a written create 201 with a `Location` header, a refusal
  422 and a stale `If-Match` 412, each error `ProblemDetails`;
  `dryRun` is a query parameter and `If-Match`, `X-Surface-Stale` and
  `X-CSRF-Token` header parameters; the read answers an `ETag` and a
  `Link`. A read only operation is run with a POST too, a check that
  writes nothing, taking the patch as its body. *Closed 2026-10-22:* a
  committed create answering 201 (until then every submit answered
  200).
- **The envelope and the result as components.** `Contract` (the
  envelope: `resource`, `operation`, `label`, `schema`, `values`,
  `stale`, `outputs`, `notes`, `discarded`), `FullSchema` (the schema
  itself), `NarrowRequest`, `Result` (the one result, `{path, code,
  message}` entries in `ResultEntry`, each path a JSON Pointer),
  `SubmitResult`, `Created` and `ProblemDetails` are shared. Until
  contract 3 a refusal was a 200 `Result` with `ok: false` and a 403
  Drupal's own error page; `fingerprint` was in `Contract` and the
  refine's body was `RefineRequest`.
- **The resource in every answer** (decided 2026-10-22). Each answer is
  `allOf` its shared envelope and an object typing that envelope's
  properties for the operation, so Swagger UI and Redoc show the
  resource under every endpoint, not only in `x-surface-schema`, which
  stays. `values` (the envelope, the narrowing and a run's
  result) is `anyOf` the values component and any object: a contract
  shows a key that holds nothing as `null` (a create's required title
  before it is typed) and a refused run answers only what it accepted,
  so the component alone would be stricter than the endpoint, and a
  validating client would refuse real answers. `schema` and `outputs`
  are JSON Schema documents, typed `{type: object}` with the component
  as their `examples`, not as a `$ref` to the 2020-12 meta-schema: an
  external `$ref` is fetched from json-schema.org by a linter or a UI,
  and Redocly reads the meta-schema as a malformed schema object (a
  `struct` error). The JSON Schema `examples` keyword, since OpenAPI 3.1
  deprecates the schema object's `example`. The `/schema` answer also
  carries an OpenAPI example: the full schema built for no account, the
  values component (since contract 3 the answer is the schema itself,
  `application/schema+json`). Declared
  outputs are a component of their own, `<values>.outputs`, which the
  run's `outputs` reference the same way.
- **Permission as security.** The session cookie is an `apiKey` scheme,
  and basic auth an `http` one where `basic_auth` is on. Each operation
  requires one of them, or none when the anonymous role holds its
  permission, and names the permission as `x-drupal-permission`. The
  POSTs take `X-CSRF-Token`, required by core only with a session
  cookie.
- **Examples from the declared defaults.** A run's example is the
  defaults the operation is built with, the tool listing's source,
  accepted and validated by the pipeline with nothing stored, with no
  null, no empty map and no locked key; none when no default is declared
  or the pipeline refuses them (an update's required key with no default
  aside, which keeps what is stored).
- **An operation with a subject is a path.** Its identity is the path's
  parameters, `/surface-api/node.type/{type}`, and its schema the one
  built knowing no subject, for no account, as its tool's listing is
  (`OpenApiDocument::neutral()`). *Closed 2026-10-22:* until contract 3
  such an operation was listed under the top `x-surface.withASubject`,
  not described.

(`OpenApiDocument`, `SchemaComponents`, `OpenApiController`,
`OpenApiDocumentTest`, `SchemaComponentsTest`, `OpenApiEndpointTest`)

### A schema can be read back into a structure

Decided 2026-10-22. A JSON Schema document is read into a blueprint by
`Contract\SchemaReader`: the emitter run backwards, each keyword read
as the declaration the emitter writes it from
([Reading a schema](reading-a-schema.md) has the mapping). Two ways in:
variants a provider lists from the site, each with its schema, and a
document registered on its own, `SchemaReader::structure()`. (A third,
`$inputs->fromSchema($document)` in a class, had no caller and no test,
and was removed on 2026-10-24; [The surface was counted and cut](#the-surface-was-counted-and-cut).) Each choice, and why:

- **The emitter's own mapping, reversed.** A keyword is read as what
  the emitter writes it from, not by a vocabulary of the reader's own,
  so what the module states and what it reads are one table, and the
  full schema of a shipped declaration, read back, is that document
  again. `SchemaRoundTripTest` holds that for every declaration of the
  examples, the first resource and the test fixtures with no runtime
  refiner: 34 documents byte for byte, and each that differs listed
  with its kind (a coded refiner read back as a declared rule, a rule on
  a port of a structure emitted alone, access, an alter's mount, keys
  from the site), the coded ones held to differing in their markers
  alone. A coded refiner cannot be read back: what it computes is code,
  and the document holds its answers, which are read as declared rules,
  or its `refine` marker, which is not read.
- **Kept, not refused, not dropped.** What the vocabulary cannot say
  (`not`, `contains`, `patternProperties`, `dependentSchemas`, a type
  that is several types, a conditional outside the vocabulary or one
  that widens, a reference to another document) is the part of the
  schema left over, kept as a `JsonSchema` constraint on the key it is
  about, or on the frame for what is said of the object, and checked by
  the pipeline against that part. Refused, a document with one such
  keyword would be no structure at all, and most documents written
  outside this module have one; dropped, the structure would accept what
  its schema refuses. Kept, the pipeline holds a value to exactly what
  the schema says, and both documents name it under `checkedOnServer`,
  wider than the pipeline there and never stricter
  ([Two documents](#two-documents-never-stricter-than-the-runtime)). The
  entry carries the schema beside its keywords, so a reader of a
  document reads the same constraint back. A frame keeps its own on its
  definition map, checked after its keys, its refusal path the frame's,
  code `not_valid`, and named under the frame's own `checkedOnServer`.
  Both are additive to contract 2: no document of a declaration written
  in code changes. Refused
  outright is only what cannot be read at all, naming its pointer: no
  object at the root or a def, a key holding a dot, a reference to a
  def the document lacks, a type JSON Schema does not have.
- **opis/json-schema at runtime.** A kept part is checked with
  opis/json-schema (Apache-2.0), a runtime dependency of the module now,
  where it was the suite's alone; the README names its license. It reads
  2020-12, the dialect every document here is written in, and it is the
  validator the suite already holds both documents to, so the server
  reads a kept part as the suite reads the whole. Core's validator for
  single-directory components, justinrainbow/json-schema, reads drafts 3
  to 7. It runs as a standard client does: extensions off, so no default
  is filled before `required` is read; `format` not asserted, as an
  annotation ([`format` is an annotation](#format-is-an-annotation)).
  A reference to another document is never fetched: no loader is
  registered, and a value it would decide is refused, naming the
  reference, code `unresolved`, rather than accepted unchecked.
- **Open objects.** A structure is closed: the pipeline refuses a key
  nobody declared before it reads anything else, and such a key would
  have no type, no label, no widget and no config schema. So a frame
  read from an object that leaves `additionalProperties` out, or `true`,
  is read closed, noted at its pointer: narrower than its source for a
  document that relied on extra keys, which the note says. An object at
  a key with no properties (`{"type": "object"}`), a value whose keys
  nobody declares, is a value of type `any` held whole to its schema by
  a `JsonSchema` constraint: a map with no declared keys, stored as it
  is sent. Refusing either was turned down: single-directory components
  and most tools leave `additionalProperties` out, and a component
  closes its props at the root itself.
- **Older drafts converted first.** Single-directory components write
  draft-04 and many tools draft-07; `SchemaReader::upconvert()` turns
  each into 2020-12 (`definitions`, boolean exclusive bounds, `id`,
  `nullable`, tuple `items`, `dependencies`) before anything is read,
  so there is one reader, for the dialect the emitter writes, and each
  conversion applies whatever `$schema` says, since it reads a spelling
  2020-12 does not have.
- **Variants from a provider.** `attachVariants($key, by:, from:
  Provider::class)` names a service implementing `SiteListInterface`,
  whose `list()` answers a `SiteList`: per value of the deciding key, its
  label and the schema of the structure it chooses. Asked at every
  build, as a keys provider is
  ([Keys that come from the site](#keys-that-come-from-the-site)), and
  refused when it cannot be cached or varies by the account, for the
  same reasons. The keys provider's interface was weighed and turned
  down at first: its entry is a key, with a starting value and a lock,
  where a variant is a structure chosen by a value, so the default and
  the lock would mean nothing and the schema would have no place.
  *Changed 2026-10-24:* the two are one interface and one list type, the
  type-level split a build refusal: a variant entry with a start or a
  lock is refused, and a key entry with a schema or a structure ([The surface was counted and cut](#the-surface-was-counted-and-cut)).
  Derived variants were turned down too: a derived variant is plain definitions with no rules,
  children or variants of its own, and a schema may say all three, so
  each listed variant is a structure read from its schema, built by the
  same build step as a class. A declared `#[Variant]` wins for a value
  both name. *Added 2026-10-23:* an entry may name a structure class in
  place of a schema (`SiteList::add(..., structure:)`, `addStructure()`
  until 2026-10-24), built as declared,
  for a value whose structure the site's code declares: a block plugin
  naming its settings with `#[UsesStructure]`
  ([A placed block is a resource](#a-placed-block-is-a-resource)). The contract does not change: `anyOf` of each variant's
  def, one conditional per variant, the tool listing flattening them,
  the React app narrowing locally, exactly as for a class's variants.
- **Read structures are registered, not discovered.** Each object a
  document holds as a structure (a def, an object a map cannot hold, a
  variant) is registered by `ResourceRegistry::defineRead()` under
  `schema:<id>:<hash>`, listed by no catalog, baseline or tool, and built
  by name as any structure is. Its id is the def's key, `<owner>.<key>`
  for an inline object and `<owner>.<key>.<value>` for a variant a
  provider lists: the names the emitter gives a child with no class, so
  a document read back writes the same defs.
- **Rules at a site belong to the child.** A rule the container states
  for a child it wires a port to (an `if` on the wired key, a `then`
  reaching into the child) is moved into the child's def first, on the
  port, once however many sites state it, so one def is one structure.
- **Storage of variants from the site is open.** Which variants a site
  has is not the code's, so no config schema written once can name
  them: the generated schema types the key `ignore`, the variant the
  deciding key chooses holds the value to its own keys before anything
  is written, and the check that a resource advertises nothing its
  config schema refuses reads `ignore` as refusing nothing.

Open: a structure mixed in with its ports wired, whose wiring no
document states; what a site narrows of a child (a lock its context
made); a per-entry wiring of keys from the site; a `format` beyond
`date`, `time` and `uuid` with a constraint of its own; a schema stored
in configuration a site edits, as a provider of variants.
(`Contract\SchemaReader`, `Resource\SiteListInterface`,
`Resource\SiteList`, `Plugin\Validation\Constraint\JsonSchemaConstraint`,
`ResourceRegistry::defineRead()`, `DefinitionMap::frameConstraints()`,
`SchemaReaderTest`, `SchemaRoundTripTest`, `VariantsFromSiteTest`)

## Evolution

### A resource evolves in the open

Decided 2026-10-19, before anyone depends on a resource (the roadmap's
gate "An evolution design before anyone depends on a resource"). A
change to a resource or structure is compatible when every value
already stored still loads, every client that read the old contract
still gets what it asked for, and the full schema is still never
stricter than the pipeline, judged on the accepted document after stored
values are converted. Any other change is breaking, and a breaking
change is made in the open: the version of what owns it is raised and
its stored values are migrated, or its contract baseline records why it
was accepted. The table, change by change, is [Evolving a resource](evolution.md#compatible-and-breaking). Each choice, and why:

- **Judged by the pipeline, the authority.** What a client asked for is
  what value semantics already say: a key left out keeps what it holds,
  `null` clears, and the pipeline fills defaults. So adding a key that
  may be left out is compatible (an old document leaves it out; a create
  gives it its default or nothing, an edit keeps what is stored, and a
  value stored before it reads its default), and a default, a label or a
  description changed is a note, never a break. For the inputs, what a
  client sends, a schema that accepts less is breaking; for the outputs,
  what a client reads, one that may hand it more. A client reads a
  document by the schema served with it: the documents are closed, so a
  full schema cached before a key was added refuses a newer document,
  and a client fetches it again when the version moves.
- **A required key added with no default is judged by the operation's
  kind** (decided 2026-10-20, from the Smithy review and section 3.2 of
  the reviews' synthesis, amending "compatible, with a note", decided
  2026-10-19). The two sides each had a reason. The owner's, from the
  entity case: no value stored and no document sent before held the
  key, so nothing said before changes meaning; a thing stored before has
  nothing there, its next write must supply the key, the pipeline asks
  for it in the key's own words, and an update tool lets the call
  through to the pipeline, since it requires nothing at its door
  ([Required is the resource's, and an update tool requires nothing at
  its door](#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door)).
  Smithy's (`AddedRequiredMember`, an error without `@default`):
  compatibility is defined by requests, every client that read the old
  contract still gets what it asked for, and an old client never sends
  the key, so its call is refused, the same reason an optional key made
  required is breaking. Both hold, each for one kind of call, and the
  kind settles it. On an update or a read, which act on what is stored,
  a key left out keeps what is there: a client that edits a thing
  holding the key is not refused, and one stored before is asked once,
  so the lint notes it, "a write must now supply <key> where nothing is
  stored". On a create nothing is stored to keep, so every old create
  is refused: breaking, "a create that leaves <key> out is refused",
  answered by a default or an allowance with its reason, since the
  version path is closed until migrations exist. A structure described
  alone states no kind, so the lint cannot tell whether a container
  creates it and judges it as a create; each container's document
  judges the same key in its own kind. The lint reports per document,
  so one declaration can be a note on `configure` and breaking on
  `create`. With a default, which a create gives and a value stored before
  reads, it asks nothing of any kind and stays a note. An optional key
  made required stays breaking for every kind: the nothing a stored
  value holds may be a `null` a client sent on purpose, which cleared
  it, and that `null` is refused from then on. Where the new key's value
  can be derived from what is stored, the version is raised and a
  migration writes it, so no write is asked for it.
  (`ContractDiff::properties()`, `ContractDiffTest::testRequiredKeyAddedIsJudgedByTheKind()`,
  `ContractBaselineTest::testRequiredKeyAddedIsJudgedPerOperation()`)
- **A version on the declaration, from 1.** `#[Resource(version:)]` and
  `#[Structure(version:)]` count the breaking changes made to stored
  values; a compatible change never moves the number. Both documents
  stamp it, `x-surface.version`, at the top always and on a def only
  above 1, so a client compares the number it asked for without knowing
  a default, and a def that has said nothing yet adds nothing. A named
  scalar shape has none: its values are its users' keys, so a breaking
  change to a shape is one to each resource and structure that uses it.
  A version above 1 is refused at discovery until stored-value
  migrations are built: it promises that values stored at an older
  version are converted before anything reads them, and a promise
  nothing keeps is refused, as storage that would write nothing is.
- **A structure versions itself.** It is stored by every container that
  holds it, so a breaking change to it changes every container's stored
  layout (the roadmap's ports item said so), and only its own
  declaration knows its keys. Its version and its migrations are its
  own, run wherever it sits: at an attached key, in each item of a
  collection, at a mount, and as a mixin's members in the owner's map. A
  container's version never answers for a structure it holds.
- **Migrations are pure up-steps on the declaration; the ledger is the
  storage's.** One static step per version, `migrate(array $stored, int
  $from): array` on `Resource\StoredValueMigrationInterface`, over the
  class's own frame in input layout, pure as a static refiner is. The
  pipeline runs the steps on what `load()` hands it, before `accept()`
  reads it as what is stored, so every door reads converted values;
  storage stamps the declared versions at commit, since it owns the
  layout and so where the stamp lives (`ConfigObjectStorage` beside
  `_core`, as `_surface.versions`). The stamp travels with the data,
  so an import of an older export is converted again on read and
  environments converge in recorded order. Only up-steps: going back is
  a restore. A stored version above the declared one is refused at load.
  A layout change that leaves the accepted document alone is the
  storage's own, read both and write the new one, as
  `EncryptedStorageLayout` reads plaintext. A content entity's storage
  will answer the same two questions, where its ledger lives and how it
  stamps, with no change to the steps or the runner. Designed, not
  built: ROADMAP.md, Phase D item 13.
- **Callers are not migrated.** A step converts stored values, never a
  document a caller sends: a document written for the old version is
  held to the new one and refused by name. A breaking change is breaking
  for clients whatever its migration; the version is how they learn.
- **The contract diff is a test any module runs.** Each resource's and
  structure's full schema, for the site's first account, is committed
  as a baseline in `tests/fixtures/contracts/<id>.json` of the module
  that keeps it, and `Contract\ContractDiff` classifies every difference
  from it by the table: narrows, widens, adds a key that may be left
  out, replaces, or notes. A breaking one passes when the version of its
  owner was raised (the document's id, or the structure whose def the
  path is inside) or when the baseline allows its path with a reason,
  and fails otherwise. A keyword it does not read is breaking, so the
  lint is never more lenient than it can show. `ContractBaselineTest`
  pins the examples and `surface_examples_first`, and `drush
  surface:contract-diff` prints the verdicts and, with `--write`,
  pins a change once it is accepted. In the main module, because the
  contract and its emitter are. The lint fails closed (decided
  2026-10-20, B25 of the reviews): a declaration with no baseline fails,
  unpinned, so a mistyped directory cannot pass as "0 breaking"; each
  baseline records its module, and a run compares, writes and removes
  only the named modules', so modules may share a directory; the account
  the documents are emitted for is named (`--uid`) and printed; and the
  verdicts print as JSON for a program (`--format=json`). It compares
  only documents of one contract version (decided 2026-10-20,
  [Contract 2](#contract-2)): each baseline records the version it was
  written in, `contract`, and one written in another is not read
  keyword by keyword but reported once, "contract version 1 to 2", a
  change of its own class (`contract`) that fails until `--write`
  re-pins it, with no reason asked, since no resource broke its
  promise. A change to a key's union of its variants, to `$id`, `rules`
  or `within` is a note; `checkedOnServer` is compared by constraint.
- **What is promised.** `main` promises nothing today. From the first
  tagged release, every resource and structure of the examples and
  `surface_examples_first` keeps a baseline (as will any the main module
  ships), a breaking change to one is versioned or allowed with its
  reason and named in the release notes, and values stored by a tagged
  release are converted forward once the migrations exist
  ([Evolving a resource](evolution.md#what-is-promised)).

What we read: Smithy's evolution guide, member optionality rules and
smithy-diff evaluators; core's update and post-update hooks,
`ConfigEntityUpdater` and its post-update with `preSave()` idiom; JSON
Schema under closed content models. Smithy's compatible and breaking
lists are this table's, but its clients fill defaults, so a default is
breaking there and a note here, where the pipeline is the authoritative
server Smithy's `@input` rule trusts. smithy-diff accepts a difference by a
suppression with a reason, as the allowance does. Core records its
updates in the site's database, which no export carries, hence a ledger
in the stored value. A closed schema gains a key backward compatibly,
never forward, hence a document read by its own version's schema.

Declined from the 2026-10-20 reviews, each in a line:

- **Post-update hooks as the migration path** (drupal-architecture):
  a post-update runs once per site and is recorded in that site's
  database, which no export carries, so an older export imported after
  it is never converted again; the steps stay on the declaration with
  the ledger in the stored value, and a post-update is only one way to
  call the eager pass.
- **`kind: put` for a create whose caller names the thing** (Smithy):
  `#[Operation(idempotent:)]` already says the one fact `put` would add,
  and a fourth kind would hand every client that reads `kind` a value
  it does not know, a wire change for one flag.

Open: a version for an alter's mount, keyed under its module in the
ledger, since an alter's keys are its module's to migrate; migrating a
document a caller sends, by a version it names; a ledger for State and
a plugin's configuration; the eager pass (a command, and a service a
post-update calls) for code that reads its storage directly; deprecation
before removal (ROADMAP.md, Phase D item 15); a field a site builder
removes from a derived resource, a breaking change no code made; and
baselines for operations that need a subject, which the lint leaves out.
(`Resource\Attribute\Resource::$version`, `Structure::$version`,
`ResourceRegistry::versionOf()`, `SurfaceInterface::version()`,
`DocumentHead::versioned()`, `Resource\StoredValueMigrationInterface`,
`Contract\ContractDiff`, `Contract\ContractBaselines`,
`Drush\Commands\ContractDiffCommand`, `ContractBaselineTest`,
`ContractDiffTest`)

## Testing

### An adopter's tests hold the invariants

Decided 2026-10-24. The invariants are what make the pattern safe to
adopt: a schema a caller reads is never stricter than the pipeline,
every door gives one answer, a static refiner and a static access
answer are what their kind claims, and a resource changes in the open.
This module held them for its own fixtures only, so a module adopting
the pattern had the promise without the proof. `ResourceTestBase`, a
kernel test base in `tests/src/Kernel/`, holds them for any module's
resources, found the way core's test base classes are (every module's
`tests/src` autoloaded under `Drupal\Tests\<module>\` when tests
run). Each choice, and why:

- **One base class, three things to write.** An adopter lists its
  modules, its resource ids and, if it wants, rows of values; the
  documents every check runs over are generated from the declaration
  (the declared defaults, each key sent null, each key sent a value of
  another type), so a resource is held the day it is written. Each check
  is a test with one data set per resource, so a failure names the
  check and the resource. The checks are this module's own, moved into
  traits its tests use too (`NeverStricterTrait`,
  `SchemaRoundTripTrait`, `StaticRefinerAgreementTrait`,
  `StaticAccessAgreementTrait`, and since the same day the doors',
  `DoorAgreementTrait`), so the two cannot drift.
- **Skipped by kind, not failed.** A full schema that cannot read back
  to itself for a known reason (a coded refiner, an access answer, an
  alter's mount, keys from the site, a computed key, a constraint only
  the server checks, a port, a runtime refiner) is how the pattern
  works, not an adopter's mistake: it is reported skipped, naming the
  kind and why. The kind is read from the document and the declaration
  (`SchemaRoundTripTrait::kindOf()`), and `SchemaRoundTripTest` holds
  it to the kind it lists by hand for each of the module's 51 such
  documents, so the report an adopter reads is the one this module
  checked. Any other difference fails.
- **Exact unless the schema says otherwise.** A generated document the
  pipeline refuses must be refused by both schemas, unless a refused
  key carries a mark that the schema is wider there (`refine`, `live`,
  `checkedOnServer`, a `format`, a secret's required, a stale value):
  the marks are the contract's own statement of where it is not exact,
  so the check reads them rather than a list an adopter would keep.
- **The Tool API's own refusal is held to its shape.** Where the Tool
  API refuses a value at its input, before the tool runs, it checks the
  shape alone (types, the keys a create requires) while the pipeline
  stops at the first value of another type, so the two may name other
  keys: the check holds that both refuse and that the Tool API names
  keys by pointer. The tool now reads the position the Tool API puts in
  its message ("(property room) ...", until #3583065 moves it onto the
  path) back off the message's arguments, and names a primitive type
  refusal `type_mismatch`, as the pipeline does.
- **Baselines are theirs.** The contract baseline check reads and
  writes the directory the adopter names, `SURFACE_BASELINES_WRITE=1`
  pinning today's, so a breaking change fails their build the way it
  fails ours; nothing of this module's is compared.

Open: an operation that needs a subject is built with none and left out
of the checks that need values. Closed 2026-10-24: a resource whose keys
are typed `uri` failed the door check when an array reached core's
`PrimitiveTypeConstraintValidator` through the Tool API's input
validation, which calls `parse_url()` on it (a `TypeError` the Tool API
answered as a runtime failure, found by this base class over example
14); the tool's shape check now refuses a map or a list sent for a
scalar key first ([A refusal names what it depends
on](#a-refusal-names-what-it-depends-on)), and
`ExamplesSecretsInvariantsTest` holds example 14's mailer and webhook to
every check.

(`Tests\surface\Kernel\ResourceTestBase`,
`FirstResourceInvariantsTest`, `ExamplesSecretsInvariantsTest`,
`Traits\NeverStricterTrait`,
`Traits\SchemaRoundTripTrait`, `SchemaRoundTripTest`,
`ResourceOperationTool::execute()`, [Testing a resource](testing-a-resource.md))
