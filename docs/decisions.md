# Decisions

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
own: it is attached to a parent, fills a slot as a variant, or is the
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
`edit` or `restock`); asked on its own, it is the container that is
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

The product is Data Surface. A name that is the product's, or that is
on the wire, keeps the word surface; a name for a concept uses the
concept's word. Renaming a product name tells a reader nothing the docs
do not, and breaks every site, client and tool that reads it. Renaming
a concept name costs only this repository, and an old concept word left
in code teaches the wrong model.

These keep the word, as product names or on-the-wire identifiers:

- the module and submodule machine names and PHP namespaces
  (`data_surface`, `Drupal\data_surface\...`), the test modules'
  `data_surface_` prefix, the project name, the `data_surface.` service
  prefix, the logger channel and cache ids;
- route names, the route keys (`_data_surface_resource`,
  `_data_surface_operation`, `_data_surface_operation_access`,
  `_data_surface_panel`, `_data_surface_cosmetics`,
  `_data_surface_react_access`) and URL paths (`/surface-api/...`,
  `/surface-react/...`, `/surface-examples/...`);
- config names and State keys;
- the served contract's `x-surface` keyword, its `surface` and
  `situation` keys (the resource's id and the operation's id, in the
  document and in `created`), and the React page's `drupalSettings`
  keys, because clients already read them;
- the `surface[...]` form key;
- the React app's identifiers (`SurfaceForm`, `surface` in its
  settings);
- the engine: what the build step seals is a *data surface*
  (`DataSurfaceInterface`), and the classes around it keep the name
  (`DataSurfaceBuilder`, `DataSurfacePipeline`, `DataSurfaceFormBuilder`,
  `DataSurfaceOperationForm`, the `DataSurface*` host bases and plugin
  types), as do the hosts' `surface*()` methods (`surfaceAccess()`,
  `buildSurfaceForm()`), named so beside core's methods of the same
  verb;
- tool ids, `data_surface:<id>:<operation>`;
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
  `$surface` (the tool bridge, the catalogue and its script), as is the
  tool base's `$resourceRegistry`, and a translatable placeholder
  `@surface` is `@resource` (or `@child` for a collection's item);
- `BlueprintAdapterBase::reserveSubsurface()` is `reserveChild()`, and
  `ResourceCollectorPass::SHAPES` is `DECLARATIONS`;
- the test modules `data_surface_target_test` and
  `data_surface_surface_test` are `data_surface_storage_test` and
  `data_surface_resource_test`, their ids `resource_test.*`; the
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

Deferred:

- The contract's `surface` and `situation` keys: renaming them is a
  wire change, so it waits for a version of the contract.
- The resource test module's `/surface-test/...` paths and the entity
  test module's "bypass surface entity test field access" permission:
  only tests read them, and URL paths and permission names follow the
  product rule above.
- Dated records (`REWORK.md`, `PLAN.md`, `ADOPTION.md`, `HARDENING.md`,
  the fresh-eyes log, the never-stricter audit) keep the words of their
  day.

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

What `defineInputs()`, `defineOutputs()`, `alterInputs()` and
`alterOutputs()` are handed is a **blueprint**: `BlueprintInterface` for
the owner, `BlueprintAdditionsInterface`, add-only, for an alter. It is
the thing a resource or structure declares its members into, as a
migration declares a table's columns into the table's blueprint. It was
called a shape, but in Smithy a shape is a named type, and the builder
its members are declared into is not one; a named scalar shape, a
`#[Shape]` class reused at many keys, is
([A shape is a named definition](#a-shape-is-a-named-definition)), and
the two never share a name. How accepted values are laid out in storage, where that
differs from how they are asked for, is a **layout**:
`SettingsLayoutInterface`, and `HasStorageLayoutInterface::storageLayout()`
for an alter's mount. "Shape" is the named type, of three kinds, and in
code names the third, `#[Shape]`. (`ResourceBlueprint`,
`ResourceBlueprintAdditions`, `BlueprintAdapterBase`)

### A shape is a named definition

A scalar asked for at many keys (a licence, a stock code) was written
inline at each: its type, its pattern and the words that explain it,
its description, its examples. A **shape** names it once: a class in
`src/Shape/` carrying `#[Shape('event.licence')]` and implementing
`ShapeInterface`, whose static `define()` returns a plain core
`DataDefinition`, used in the type position of `add()`:
`$inputs->add('licence', Licence::class, t('Event licence'))`. Resources,
structures and these named scalars are the three kinds of shape, in
Smithy's sense of a named type. The compliance licence is the first
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
container cannot yet opt out of a structure's; the catalogue listing
shapes and who uses them. (`Resource\Attribute\Shape`,
`ShapeInterface`, `ResourceBuild\Shapes`, `ShapeBlueprint`,
`Resources::assertShapesNarrowed()`, `Resources::unshaped()`,
`DefinitionMetadata::shapeOf()`, `ContractEmitter::named()`, `isPart()`
in `app/src/contract.ts`)

## Authoring

### A resource or structure is static

`DefinesInputsInterface::defineInputs()` and `HasOutputsInterface::defineOutputs()`
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
(`Resources::buildResource()`, `Resources::assertStaticOnResource()`,
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

## Context

### A build is not cached

Every `Resources::build()` builds afresh; the pattern's "cache by
(id, context)" is not done. A build describes live site
state, and a cache keyed by context alone would serve a stale one; what
that state is, a blueprint says with `addCacheableDependency()`, for a
caller that caches. *Not from a code comment; the build step's
docblock states it.*

### Child context

A child that invokes no operation of its own sees the context its
parent's hands it with `withChild()`; otherwise it sees the parent's
context id, `creates` and known identity, and not the parent's
constraints, starting values or child contexts. Those three name the
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
else the child's default (`#[Operation(default: TRUE)]`, at most one);
else none, and the child inherits as it always did. The parent's
context id is only a hint, because the parent's verbs are the parent's:
a field's `reuse` means nothing to its storage, and a child may have no
operation for a verb at all, so a match is used when there is one and
never required; a match, or the default, that needs identity the parent
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

However the child was invoked (named, matched, the default, inherited,
or handed whole), its access class answers, open, locked or omitted, in
the context it was invoked in, and what that answer read reaches the
parent's cacheability. The permission of an operation is asked only where
the build resolved that operation (named, matched or the default).
Permission and access are two questions. A permission is about entering
an operation, and a parent that built its child's context itself took
responsibility for that entry, so the child's permission is not asked
again: a field's reuse and edit build its storage through
`FieldStorageResource::edit()`, and the field's own permission is the
gate. Access is about what may be done with what is there, and only
access composes down the tree. A module like field_permissions hides a
single field through access, so a context handed whole must not be a
way around it: were the handed path to skip the child's access class,
any parent could open a field its access closed by building the field's
context itself. An inherited child with no access class is asked
nothing and stays open.
(`Resources::invokeChild()`, `Resources::childOperationContext()`,
`Resources::childAccess()`, `OperationContext::withChild()`)

### A context's id is the operation id

An operation whose context carries an id other than its own is
refused when the operation is asked. The pattern says the context's id
*is* the operation id; enforcing it is what lets alters (`operations:`),
permissions and catalogues trust it. An operation built on another calls
`withId()`. (`Resources::operation()`)

### Constraints and starting values for an attached key

A context constraint or starting value naming the key a child is
attached at is refused, and the message points at `withChild()`. A
child is narrowed and started by its own context, which is the one door
through the wall
between parent and child. (`Resources::applyContext()`)

### Starting values without creating

A context that carries starting values but does not create is refused
rather than ignored. A thing that exists begins from what its storage
loads; a silently dropped starting value would be a bug nobody sees.
(`Resources::applyContext()`)

### Starting values are defaults

A starting value becomes the key's declared default for that build
only. That is exactly what a starting value is to the engine: what the
caller sees first and may replace. (`Resources::applyContext()`)

### Known keys that are not identity

A known key that is not one of `#[Resource(identity:)]` is not locked;
it stays on the context for the storage and the access class to read.
`FieldStorageResource::edit()` knows the field storage's entity type and
field name this way, which `FieldStorageStorage` loads by, while
`field_type`, its one
identity key, is locked. (Before storage settings existed, the field
type was known the same way; it became identity when it started
choosing the settings slot.)

### An entity parameter given as an id

A route parameter or tool input for an operation parameter typed as an
entity interface is loaded by the one entity type whose class satisfies
that type; a type several entity types satisfy is taken only as an
object. A guess between entity types would load the wrong thing.
(`OperationArguments`)

## Refinement

### Gating per key

The engine runs a key's refiners once every sibling any of them watches
has a value, so a key refined by two methods watching different siblings
waits for the union. The pattern runs each method once its own siblings
have values; gating per key is what the engine's refinement edges, the
form's AJAX rebuild and the discard cascade already ride, and nothing
shipped has needed finer. Per-method gating is listed in `ROADMAP.md`, after
the rework, for when a case needs it. (`Resources::bindRefiners()`)

### A refiner that watches nothing

It runs once, at build, after the context is applied; its result is
held to the narrowing check and is what the data surface advertises. The
pattern says it "runs once" without saying when; at build is the only
time that gives every caller the same answer. (`Resources::bindRefiners()`)

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
event licence typed as `EV-2048` gives example 3's capacity the
no-licence ceiling of 100, exactly as no licence does, and a capacity
of 250 under that ceiling moves no steward minimum. A refusal upstream
is settled before anything downstream is judged: withholding a venue
widens the room, and the room is judged again against that. The values
themselves are not touched, so a form keeps showing what was typed and
the pipeline refuses it under its own key; only refinement treats it as
absent. It is the Tool API's rule, which validates the simulated
dependency values and keeps the unrefined definition on a violation,
and it lives in `DataSurface::refine()` itself, so the form, the AJAX
rebuild, the pipeline's validate, the served contract and the React
refine all get it from one place. The check is
`Refinement\WatchedValueCheck`, typed data's own validation, sealed
into every data surface the build step makes and serialized by its
service id; a data surface sealed without one, which only the engine's
tests build,
hands every configured value over. A refiner may therefore assume what
it is handed satisfies its sibling's constraints, and
`RegistrationComplianceAlter` tests only that a licence is there.
(`DataSurface::refine()`, `DataSurface::refusedWatchedValues()`,
`Resources::buildResource()`)

### Alters' refiners in the owner's chain

An alter's `#[RefinesInput]` method on an owner's key runs in the
owner's chain, after the owner's own methods. On a key the alter offered
more values on with `extendChoices()`, it is that contribution's
refiner instead, handed the alter's values only. The engine keeps one
chain per contribution, so an alter can tighten the owner's values
without ever being able to take another module's away.
(`Resources::bindRefiners()`)

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
be listed (`dynamic`). A runtime refiner promises the schema nothing: the
emitter never runs it, in either document; the key keeps the width its
static refiners give it in the full schema and carries `runtime: true`;
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
  from a runtime refiner. (`Resources::assertStaticUnlessRuntime()`)
- **Observational, under `assert()`.** In development and tests a
  static refiner's call runs with the global container replaced by a
  wrapper that throws when asked for any service or parameter but the
  typed data manager, which core's definitions ask for to read their
  default constraints. `t()` passes: it builds a lazy markup. Production
  compiles no assertion and pays nothing. It catches `\Drupal::service()`
  and every shortcut through the container, `::currentUser()` and
  `::time()` included. (`Refinement\StaticRefinerContainer`,
  `RefinesInputRefiner::invoke()`)
- **Agreement.** Under `assert()` the emitter asks each enumerated key's
  static refiners again for one combination and refuses a different
  answer, which catches a counter or a clock that reads no service; and
  a kernel test trait holds every conditional of a document to a fresh
  `refine()` for its values, run over the examples and the fixtures.
  (`ContractEmitter::answersAgain()`,
  `Tests\data_surface\Traits\StaticRefinerAgreementTrait`)

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
(`Tests\data_surface\PHPStan\StaticRefinerRule`, `StaticRefinerRuleTest`,
`RefinesInput`, `Resources::bindRefiners()`, `RuntimeRefiner`,
`ContractEmitter::frame()`, `ContractEmitter::enumerate()`)

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
`DataSurfacePipeline`, `Refinement\WatchedValueCheck`)

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
attached child's key and a slot's key are ruled out the same way; a
parent's refiner of a key holding a child may give this answer and no
other, since what the child holds is the child's to narrow.

Three spellings were weighed. A marker set on the definition the refiner
returns is what the engine does inside, but as the author's spelling it
is invisible until the refiner runs, and the full schema and the tool's
listing must know before anything runs which keys may not apply, or a
`dynamic` or `runtime` key required in them would be stricter than the
pipeline. A blueprint declaration, `add(..., appliesWhen:)`, is
declarative narrowing ([ROADMAP](../ROADMAP.md) item 3), which cannot
state a runtime rule; it has since landed as a refiner too, whose
`doesNotApply()` gives this same answer ([A rule declared as
data](#a-rule-declared-as-data)). The
sentinel in the return type is both an answer and a declaration: the
signature already declares what a refiner watches, and now whether it
may rule its key out, so the build reads it by reflection (a method that
answers it without declaring it is refused) and the enumeration states
it as it states any static answer. Each door:

- **Form.** No element at all, not disabled and not hidden; only its
  wrapper, for the AJAX rebuild that makes it apply.
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
  (`DataSurfacePipelineInterface::kept()`), so a storage that writes its
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
  as a key whose value is `null`, which clears. A `dynamic` or `runtime`
  key whose refiner may rule it out is in no `required`, since nothing
  says where it applies. The tool's listing makes it optional for the
  same reason, and the pipeline asks for it where it applies.
- **React.** The page narrows it from the full schema: a met `false`
  drops the field and what it sends, a met `required` requires it again,
  and a key that did not apply when the page was loaded, whose stored
  value the page was never handed, is asked of `/refine`.

A list narrowed to nothing is not a key that does not apply. It renders
as a select offering its empty option alone, the constraint refuses any
value, and both documents state `oneOf: [{const: null}]`, which a
required key's type refuses too. Refusing it at build was weighed and
dropped: a refiner's answer exists only for values, at refinement, and an
options resolver may find nothing on a site (no bundles yet), so a
refusal would break a page for site state. The text field it fell
through to before showed what was stored as something to edit, on a
field that could accept nothing. An empty `Choice` is now such a list
too, which is what a slot nothing fills gives its deciding key.

A slot that does not apply is drawn as nothing, as one whose deciding
key chose nothing is; its variants' conditionals are not written in the
schema for these answers, and the full schema states it `false` beside
them. An empty variant is a different answer: the slot applies and has
nothing to say, its value an empty map.

Inside a collection's item a key that does not apply is not put back
from storage, by design (decided 2026-10-19): a list is sent whole and
each item is accepted over the child's defaults, never over what is
stored at its delta, since an item may have moved and a delta names no
stored item ([Empty items are dropped](#empty-items-are-dropped)). What
a list holds is kept when the list is left out, as "absent keeps" says
of any key; once it is sent, the list written is the list sent.

A map whose every key is ruled out is an empty map, and is written as
one: `DataSurfacePipelineInterface::document()` writes accepted values
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
`DataSurface::refineAgainst()`, `DataSurfacePipeline::accept()`,
`DataSurfacePipeline::document()`, `ContractEmitter::enumerate()`,
`narrow()` in `app/src/contract.ts`, `AdvertisedSchemaAcceptanceTest`)

### A rule declared as data

A rule between keys whose condition and effect a schema can state is
declared, not coded: the blueprint's `narrow()` takes the key, `when()`
a `Refinement\Condition`, and its effects.

```php
$inputs->narrow('capacity')
  ->when(Condition::absent('licence'))
  ->constrain('Range', ['max' => 100])
  ->describe($this->t('Up to 100 without an event licence.'));
```

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
  reads the licence never holds the room's ceiling back
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
  two spellings of one rule would be documented twice.
- **Stated as written.** The full schema and every def state each rule
  as one conditional, its `then` read the way an enumerated one is (only
  what changed, `false` where it does not apply, `required` where only
  there), marked `x-surface.declared` beside its `if`, after the key's
  enumerated conditionals; the key says `x-surface.declared: true` and is
  no longer `dynamic` for a rule. Only the coded refiners are enumerated
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
coded and `dynamic` (they watch a number), so the React page still asks
on a licence change: the stewards depend on the capacity. Phase C item 9
of the roadmap (dependent effects as data) is this, but `locked`.
(`Refinement\Condition`, `Refinement\Rule`, `Refinement\DeclaredRefiner`,
`Resources::bindRules()`, `DataSurface::refinedEntry()`,
`ContractEmitter::rules()`, `holdsIf()` in `app/src/contract.ts`)

## Alters

### Where an alter's keys live

An alter's key is mounted at `third_party_settings.<module>.<key>`
(outputs: `third_party_outputs.<module>.<key>`), not beside the
owner's. The owner's storage and schema then never have to know a
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
whose access answers locked or omitted, is refused at a mount; each is
open. *Changed for ports: until then `attach()` and `attachList()` on
an alter were both refused, an attached structure inside the mount not
being built.*
(`ResourceBlueprintAdditions::attach()`, `ResourceBlueprintAdditions::attachList()`,
`Resources::mountStructure()`, `DataSurface::refineMounted()`)

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
such a method was refused.* (`Resources::bindRefiners()`,
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
widened what the owner narrowed: the compliance alter's licence, empty,
would have lifted the room's limit along with its own hundred. The
cycle check follows each path's own edges, so a capacity watching the
licence while the stewards beside it watch the capacity is no cycle.
*Changed for example 4 of the examples; until then watching any mounted
key was refused, pending dotted refinement paths, `ROADMAP.md` item 16,
of which this is the one case the third-party mount needs.*
(`Resources::assertNotWatchingForeignMount()`, `Resources::mountedWatches()`,
`DataSurface::dependencyValues()`, `DataSurfaceBuilder::edgesOf()`)

### A storage layout for a mount

An alter whose keys are asked for in one layout and stored in another
implements `HasStorageLayoutInterface`; its layout applies to its own
module's mount and nothing else. The alter does not own the storage, so
it hands the translation to the data surface, and the composed storage
applies it. (`Resources::buildResource()`, `StorageAdapter`)

### Required inside a plain map

A required property inside a plain map (a key that is neither an
attached structure nor a slot, such as the mount alters' keys live in)
that holds no value is refused with the pipeline's own "is required"

message, filed at its path, whether or not the map itself holds
anything; typed data's NotNull for the same path is dropped so it is
said once. No child judges a plain map's properties, and NotNull alone
passes an empty string and never sees a map that is absent, so a
mounted key an alter made required would otherwise never be refused.
(`DataSurfacePipeline::missingInMap()`)

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
shows. (`BlueprintAdapterBase::assertPlaceable()`,
`DataSurfaceFormBuilder::placeMounts()`)

### Adding a key twice

Declaring a key that is already declared is refused, for owner and
alter alike, and `add()` with a NULL default means "no default", not a
declared NULL. A second `add()` silently replacing the first would be
an alter changing the owner's key. (`BlueprintAdapterBase::add()`)

## Attached structures

### A slot nothing fills

A slot with no variant stays a placeholder and its deciding key gains
an empty `Choice`, so nothing can be chosen. Refusing the whole parent
would break it on every site without a variant module.
(`Resources::slotsOf()`)

### Derived variants

A value no `#[Variant]` class fills may get a variant derived
from a description that already exists, through a
`DerivedVariantsInterface` service: a field type's config schema fills
the field's settings slot and the storage's. A derived
variant is a child with no class, so no alter, refiner, storage or access
class of its own, and a declared variant always wins.
(`Resources::buildResource()`)

### A parent watching a child

A parent's refiner that watches the whole key a child is attached at is
refused, as is one that refines it, unless all it may answer is that
the key [does not apply](#a-key-that-does-not-apply), which is the
parent's to say about a key it owns. The pattern says a parent cannot
refine into a
child and a child cannot read a parent's value; refusing the watch too
makes the wall hold both ways. (`Resources::assertWalled()`)

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
  slot's variant and a collection's item are wired by nothing yet, so
  neither may require a port.
- **What depends on a port is stated at the site.** The def is the
  structure as declared, for no values, reused at every site with one
  id, and a port is no property of it: a conditional naming the port
  there would be read by a validator as a key that is always absent,
  stricter than the pipeline, and a def that knew the wired key would
  be one container's. So the def lists its ports (`x-surface.ports`), a
  key reading a port names it in its `dependsOn` and carries neither
  `enumerated` nor `dynamic`, and it is in no `required` where it may be
  ruled out. The container states the rest on its own `allOf`, an `if`
  on its key and a `then` reaching into the child at the site: coded
  refiners enumerated over the wired keys' lists (`dynamic` when they
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
  without a licence) is the one thing neither side can say alone, and
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
which apply wherever it is used; ports on a slot's variant or a
collection's item, which nothing wires yet. (`Port`, `BlueprintInterface::reads()`,
`Attachment::bound()`, `Resources::assertWiring()`, `Resources::wirePorts()`,
`DataSurface::refine()`, `ContractEmitter::site()`, `ContractEmitter::mounts()`)

### A mixin is merged flat

Composition nested only (attach, behind the wall; an alter's mount under
its module's name) until a structure could be mixed in: its members
merged into the owner's own keys, `$inputs->with(TimestampsStructure::class)`,
or with its ports wired, `$inputs->with(OwnershipStructure::class, reads:
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
  catalogue could list it without filling a blueprint; it does not list
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
  alter's `with()` is refused, naming the mount to attach at instead.
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
  structure answered.
- **The tool flattens by reading the data surface.** The tool bridge
  converts the built data surface, where the members are the owner's
  keys, so `values` is flat; the Tool API's normalizer writes from typed
  definitions and has no `allOf` to carry.

Open: listing mixins in the catalogue; an optional port a mixin's owner
leaves unwired; a mixed-in structure's alter that mounts; a mixed-in
structure holding a child; a container opting out of a structure's
alters. Found on the way and left: the React page narrows an attached
child whose def states conditionals of its own to the instance (its
answered conditionals dropped), where `/refine` inlined carries the
def's too, the same constraints written twice; the Vitest chain for the
journal leaves the child's step out for it.
(`BlueprintAdditionsInterface::with()`, `ResourceBlueprint::with()`,
`Resources::mixIn()`, `Resources::bindMixin()`,
`Resources::assertMixinsUnnamed()`, `Mixin`, `ContractEmitter::composed()`,
`SchemaResolver::mixIn()`, `mixIn()` in `app/src/contract.ts`)

### An empty variant is an empty map

A slot variant may declare no keys, the spelling of "this choice has
nothing more to say". It renders as nothing (an empty container on the
form, nothing on the React page), its value is an empty map, and both
contracts and the tool state an object with no properties; a value left
by another variant does not fit it and goes, as between any two
variants. The map widget serves any complex definition and answers an
empty one with `[]`: a map that declares nothing can hold nothing, and
before this the form threw for want of a widget. A key that does not
apply at all, rather than applying with nothing to say, is
[a key that does not apply](#a-key-that-does-not-apply).
(`MapWidget`, `Field.tsx`)

### Outputs hold no children

`attach()` and `attachBy()` on outputs are refused. The pattern lets an
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
item as a list in its place ([A collection on a
form](forms.md#a-collection-on-a-form), decided 2026-10-19). *Open:*
routing an item to its child's storage, which needs an identity key on
the child to say which stored item it is. (`Resources::buildResource()`,
`Collection`, `DataSurfaceFormBuilder::buildCollection()`)

### Access once per list

A collection's child is invoked through its operation once for the
list, and that access answers for the list: open, locked (the list key
locked, so every item and the form's add and remove controls) or
omitted (the key left out). Field access is per field, not per delta,
and an item has no subject of its own to be asked about before it
exists. (`Resources::buildResource()`)

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
list is sent whole and an item may have moved.
(`Collection::isEmptyItem()`, `DataSurfacePipeline::acceptCollection()`)

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
as optional. (`ContractEmitter::neverNull()`,
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
(`DataSurface::assertItemsUntouched()`, `Narrowing::COMPARATORS`)

## Storage

### A child learns its parent's accepted identity

A routed child is prepared and committed in its own context plus the
identity values its parent accepted that it does not already know. A
creating parent's identity exists only once accepted, and a child's
storage loads by identity. (`StorageAdapter`)

### Write order

An attached child is committed before its parent, a slot's variant
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
the same twice.
(`StorageAdapter::outputs()`)

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
(`AccessAnswer`, `Resources::childAccess()`)

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
  visibly rather than now and then (`Resources::ask()`). Under
  `assert()`: the first asking of a static answer is repeated with the
  global container guarded and must answer the same, static, having
  asked for no service that reads site state beside the account
  (`StaticRefinerContainer::guardAccess()`, `Resources::answersAgain()`).
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
  `buildStatic()`: a key a static answer locked is `readOnly` and
  `x-surface.locked`, one it left out is not there, as the schema for
  these answers always had them; a key a runtime answer may lock or
  leave out is there, as the static answers leave it, marked
  `x-surface.access: "runtime"`, and the schema for these answers, from
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
  saying that a call may find it locked or left out. The description,
  not a keyword: the Tool API's normalizer writes JSON Schema from typed
  definitions, which carry a description and no extension keyword. Why:
  a full schema and a listing are what clients cache, so they can only
  hold what the subject cannot change; an account's own permissions are
  that, and anything read from the subject would be stale once the
  subject changes. *Open:* a consumer that lists from the cached plugin
  definitions without making an instance, as the MCP bridge's config
  deriver does, sees the account that discovered them ([A static tool
  definition is one account's](#a-static-tool-definition-is-one-accounts)).
- **Narrowing by account is a runtime refiner, not access.** Access
  answers whether keys may be changed, seen, or neither; which of its
  values a key allows for an account is the key's own definition,
  narrowed by a refiner that reads the account and so says `runtime:
  TRUE` (`ExhibitResource::roomsForAccount()`: only an account that may
  use the vault is offered it). Access has three answers by design and
  cannot say "fewer values"; a refiner can, and the full schema already
  keeps such a key wide and marked `runtime`.
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
  a mark from a structure it mixes in itself is carried through. A slot
  variant whose answer is runtime is offered in the build for the
  account alone and the slot marked, since it may be withheld once the
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
  nothing and is runtime, so an entity's edit tool lists every field
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
`Resources::childAccess()`, `ContractEmitter::frame()`,
`InputDefinitions::runtimeAccessNote()`)

### A permission a child cannot name

A child operation's permission whose `%key` the child's context cannot
fill says nothing at a child, where at the top it is a refusal ([An
unknown placeholder](#an-unknown-placeholder)). At a child the parent's
own access has already gated the caller, and the one context that knows
too little on purpose is the one a tool's static definition is built in,
before any subject: there the child is described as it may be, and the
per call definition, built in the real context, applies the permission.
Adding a field invokes its storage's `add`, whose permission names the
entity type the field's context knows; a bare context knows none. For
the same reason a child operation's permission that resolves to the very
permission its parent's operation asks says nothing either: the parent's
gate asked it already, and a child narrows only what the parent allowed.
That is the storage's case when the field's context does know the
entity type, so a field is built with its storage for any account, as
it always was, and only the field's own access decides.
(`Resources::childAccess()`)

### Omitted is omitted both ways

An omitted child is not in the data surface, so it is in no contract,
no form and no tool input, and a value posted for it is an unknown key,
which the pipeline already refuses. It is also not read: the read tool
leaves it out, because a caller who may not see a thing should not
learn it through the other door. And it is not written: the storage
adapter hands what is stored under the key back to the parent's
storage, which may store its values whole and would otherwise drop what
the caller never saw. (`DataSurfaceBuilder::omit()`,
`StorageAdapter::prepare()`)

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
  held to what is stored, as `hold()` holds one, after the slots, so a
  slot is chosen by what its deciding key holds and not preselected by
  its default; the sealed data surface says why
  (`DataSurfaceInterface::lockedWhole()`). A class whose `access()` is
  declared to return core's answer alone cannot answer locked, so it is
  not asked here and nothing changes for it. Built for the account
  alone, a runtime answer is set aside, as a child's is, and nothing is
  marked: the call itself settles it.
- **The doors.** The form draws every element disabled, says why above
  them and offers no Save. The pipeline refuses a submit before storage
  is loaded, by name ("This may be seen and not changed, so nothing was
  written: ..."), under `@access`, so the form, the write tool and the
  React `/submit` and `/validate` refuse alike; the read tool answers.
  Both documents say `readOnly: true` and `x-surface.locked: true` at
  the top, beside every key `readOnly`, and the React page offers no
  Submit for such a document.

Why refused at the pipeline rather than at access: locked is the
answer that lets the caller see, so access cannot be the refusal; and a
submit written back unchanged would report a write that did nothing.
(`Resources::lockedAtTheTop()`, `Resources::atTheTop()`,
`DataSurfacePipeline::submit()`, `ContractEmitter::lockedWhole()`,
`LockedAccessTest`, `LockedAccessFormTest`)

### A variant is open, locked or not offered

A slot's variant invoked through an operation whose access omits it is
dropped from the slot, so its deciding value can no longer be chosen,
and no derived variant is put in its place. A variant its access locks
is offered read only (decided 2026-10-19, replacing "not offered"): it
is built locked whole, every key held to what is stored, so it can be
chosen and is drawn fixed. Chosen anew, it starts from its own
defaults, as any variant chosen anew does, and holds them. Its keys are
`readOnly` and `x-surface.locked` where the slot states it, the form
draws its fieldset disabled, and a value posted for it is kept as
stored. A locked variant chosen by a locked deciding key no longer
refuses its parent ([A chosen variant may
refuse](#a-chosen-variant-may-refuse)): the parent is written, the
variant's keys kept. (`Resources::buildResource()`,
`DataSurfaceFormBuilder::buildSurfaceForm()`)

### A chosen variant may refuse

A slot whose deciding key is locked has its variant chosen by what the
parent is, as a field's settings are chosen by its type, and the parent
cannot be written without that variant. Its access class, asked in the
context it is invoked in however it was invoked, may refuse the whole
parent, never allow; a locked answer is no refusal, since the variant is
then built locked and kept. The build leaves an omitted variant out of
the slot ([A variant is open, locked or not
offered](#a-variant-is-open-locked-or-not-offered)), so it is looked up
among the slot's declared variants. A field type's settings refuse the field
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
access class, not whenever anything does (decided 2026-10-19).
(`Resources::access()`, `Resources::childAnswers()`,
`Resources::childrenMayAnswer()`)

### No declared operation, no permission tier

A context whose id names no declared operation (a plugin host's
`configure`) has no permission tier: the access class alone answers, or
neutral when there is none. The host already checked the permission
that guards its own form. (`Resources::ownAccess()`)

### An unknown placeholder

A `%key` in an operation's permission that the context does not know
makes access forbidden. The permission cannot be named, and guessing
one would grant or refuse the wrong thing. (`Resources::ownAccess()`)

### Neutral is a refusal for an operation's caller

A route or tool addressing an operation reads a neutral answer as a
refusal (`DataSurfaceAccess::decisive()`). Asked on its own, an
operation has no parent to allow it, so nothing else is going to.
(`ResourceOperationTool`, `OperationAccessCheck`)

## Tools

### Identity before the parameters

A tool's static definition reads which identity keys an operation knows
off its signature: a scalar parameter supplies its own name, an entity
parameter every identity key no scalar names. The tool then refines
`values` to the real context once the parameters arrive. What an
operation knows is only on the context it returns, and a static
definition cannot call it. (`OperationInputs::inputs()`)

### A parameter named for a key

A scalar operation parameter named for one of the resource's plain keys
is described as that key: its label, meaning and allowed values. It is
that key's value, so it says what the key says.
(`OperationInputs::parameterInput()`)

### A plugin's structure is no tool

A class any plugin names with `#[UsesStructure]` gets no tool,
whatever it declares. Only the plugin's host holds the instance it
configures. (`ResourceOperationToolDeriver`, `ResourceCatalogue::standalone()`)

### A permission nothing can name is no tool

An operation whose permission has a `%key` none of its parameters can
supply is no tool: it could never be allowed. `field.storage`'s `add`
is only ever a field's child. (`ResourceOperationToolDeriver`,
`ResourceCatalogue::standalone()`)

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

- **The form.** The constraint's own message is the first clause, still
  the object it built, its full stop left off (`Pipeline\Clause`). When
  the refiners changed the refused key's constraints for these values,
  it goes on "when" and each watched key with what it holds, by label,
  an option by its label, nothing as "empty", joined by "and", the
  phrase a key that does not apply already used: "This value should be
  3 or more when Capacity is 150." When a list of allowed values made
  the refusal (a `Choice`, `LabeledChoice` or `AllowedValues`), it goes
  on to list the values the key allows as refined for these values,
  each label with the value to send in brackets, a value with no label
  of its own once: "The value you selected is not a valid choice when
  Venue is Riverside Hall; choose Main hall (riverside_main) or East room
  (riverside_east)." At most ten (`DataSurfacePipeline::CHOICES_SHOWN`),
  then "and 2 more"; an empty list says "nothing may be chosen". A key
  that does not apply keeps its sentence, "Send day does not apply when
  Frequency is Monthly." A bound and a pattern say themselves, through
  the constraint's message.
- **Why each part.** The caller who sent stewards and no capacity learns
  which sibling to change, which the key's own message cannot say, and
  what it holds, so a caller that sent nothing for it sees that. Every
  watched key is named, held or not, because a refiner that watches a
  nullable value (the licence) narrows when it holds nothing. The list
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
  or a required key left out, is still the Tool API's to refuse.
- **The listing names what a key depends on.** In a tool listing a key
  another narrows says so in its own description, once: "Depends on
  venue." Each key by its path in `values`, what a caller sends it
  under; a port by the container's key it reads, at the site; never a
  stored value. A slot's deciding key is said by the slot's own
  description, and a key that holds a child names nothing its ports
  read, which the keys inside it name. Inside a collection's item a key
  names its siblings by their keys in the item, the frame a caller sends
  them in together, on the item's own property (decided 2026-10-19;
  until then keys inside an item said nothing). The read tool's output
  says nothing about it. The listing stays small: a key that depends on
  nothing says nothing more.

(`DataSurfacePipeline::sayingWhatIsAllowed()`,
`DataSurfacePipeline::conditions()`, `DataSurfacePipeline::choices()`,
`DataSurfacePipeline::isRequired()`,
`InputDefinitions::withDependencies()`, `ToolFeedbackTest`)

### An operation states how it behaves

`#[Operation]` says whether a call changes nothing (`readOnly`) and
whether it may destroy what is there (`destructive`), both `FALSE`
unless said, and whether the same call twice leaves what the first left
(`idempotent`), derived unless said: an operation that does not create
is idempotent, the same document twice writing the same state; one that
creates is not, the second call finding what the first made; a read only
one is. Decided 2026-10-19. A read only operation that destroys, or
that is not idempotent, is refused at discovery. `OperationDefinition`
carries the three (`idempotent()`, `facts()`), and so does a derived
operation (`DerivedOperation`).

- **Why on the operation.** A client asks before it calls a tool that
  destroys and retries one that is idempotent, and MCP reads both as a
  tool's annotations. Only the author knows whether a call destroys; a
  module that writes cannot read it off a storage class. Whether an
  operation creates is the context's ([Whether an operation
  creates](#whether-an-operation-creates)), so idempotence is derived
  from it and declared only where the author knows better, such as an
  operation that appends. None of the module's own operations destroys:
  an edit changes the keys it is sent, and a key left out keeps its
  value.
- **The Tool API.** The tool deriver makes a read only operation the
  `Read` kind and every other the `Write` kind, and passes `destructive`
  to `setDestructive()`; a read tool is `Read` by what it is
  (`ResourceOperationToolDeriver::behaves()`). The MCP bridge derives
  `readOnlyHint` from a kind that does not modify, `idempotentHint` from
  the kind, and `destructiveHint` from the flag. *Gap, to file:*
  idempotence is a property of the kind in the Tool API, not of the
  tool, and every kind that modifies is not idempotent, so an edit
  cannot say it is idempotent while an add says it is not: both are
  `Write`, and both read as not idempotent. `Write` is the least wrong
  kind for an idempotent operation that changes what exists: every
  idempotent kind does not modify, and would also say the operation
  changes nothing, which a client acts on.
- **Both documents.** The schema for these answers and the full schema
  state the facts at the top, first under the top's `x-surface`, beside
  the title that is the operation's label:
  `x-surface.operation: {id, label, creates, readOnly, destructive,
  idempotent}`, so a client reading the contract directly has the
  operation's own answer, idempotence included. A document asked for in
  no operation states none.
- **No read tool beside a read only operation.** It changes nothing, so
  there is nothing to read before changing it
  (`ResourceCatalogue::reads()`).

A read only operation writes nothing at any door (decided 2026-10-19,
closing the point this entry left open): the build marks the data
surface (`DataSurfaceInterface::changesNothing()`), its pipeline
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
every tool today. (`Operation`, `OperationDefinition::facts()`,
`ContractEmitter::operation()`, `DataSurfacePipeline::commit()`,
`OperationFactsTest`, `LockedAccessTest`)

### A violation summary is plain text

A derived tool's refusal names each violation with its message rendered
as plain text before the line is handed on as a placeholder value. The
line is escaped there, so a message's own placeholder markup (Range's
`<em class="placeholder">`) would otherwise reach the caller, Drush
included, as literal tags. (`ResultReportingTrait::violationSummary()`)

### Required is the resource's, and a listing carries no stored value

A derived tool's `values` property is required exactly when the resource,
built for the operation, requires the key, whatever its default and
whether or not the operation creates, except a secret, which is always
optional ([A secret is optional, never nullable](#a-secret-is-optional-never-nullable)).
What a property defaults to in the tool's static definition, its
listing, depends on whether the operation creates. One that creates
lists the declared defaults, which are what a key left out becomes. One
that changes a thing that exists lists none, at any depth, and its
description says that an optional key left out keeps its current value
and names the read tool that returns the current values; a required key
cannot be left out, which the Tool API refuses before the tool runs.

Why (decided 2026-10-18, replacing "defaults are what is stored"): a
listing is a discovery channel that clients cache, `tools/list` in MCP
among them, so a stored value in it is as of the last discovery and goes
stale unseen, and a default on an edit says what a key left out becomes,
which is not what happens: it keeps what is stored. The read tool is
the per-request answer to "what is there". Once an operation's
parameters arrive, the refined `values`, which no client caches, still
defaults each property to what its storage loads now, and the schema
for these answers keeps current values in `values`; the full schema
keeps declared defaults. Updates stay a patch. The cost is the Tool
API's: it refuses a required map property a payload leaves out, default
or not (`TypedInputsTrait::validateInputValue()`), so an edit sends
every required key. A write through the tool no longer clears the
definition cache, since nothing in the listing changed.
(`OperationInputs::declaredValues()`, `OperationInputs::values()`,
`InputDefinitions::withoutDefaults()`, `InputDefinitions::withStored()`)

### Which operations read

An operation that can be asked on its own has a read tool when it changes
a thing that exists: one that needs nothing, by its context not
creating; one that needs a subject, by its parameters naming one thing
whole, a single entity with no scalar identity key beside it, or a
scalar for every identity key. A read only operation has none: it
changes nothing ([An operation states how it
behaves](#an-operation-states-how-it-behaves)). The context is the truth and cannot be
asked before the subject is named, and `creates` is not on
`#[Operation]` ([Whether an operation creates](#whether-an-operation-creates)), so the signature is
read, as identity is. A context that turns out to create is refused when
the read runs. *Open:* whether `#[Operation]` should say `creates`
itself, checked against the context the way its id is, which would
replace the reading. (`OperationDefinition::namesOneThing()`,
`ResourceCatalogue::reads()`)

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
value. Under a slot, a key any variant keeps secret is left out
whichever variant holds. Inside a list of maps, a collection's items
among them, only the secret is left out of each item, and the list and
the rest of every item are read (decided 2026-10-19; until then a secret
in an item hid the whole list); a list of secrets is left out whole.
(`InputDefinitions::secrets()`, `InputDefinitions::EACH`,
`OperationInputs::stored()`, `ReadToolTest`)

### A static tool definition is one account's

A derived tool's static definition builds the resource once, at
discovery, for whichever account is current then, so a child whose
access depends on the account is as that account saw it. It is built for
the account alone (`buildStatic()`), so what a runtime answer decides is
marked, never stated; and a tool instance lists `values` again for the
account that asks ([Access is static or runtime, read from its
cacheability](#access-is-static-or-runtime-read-from-its-cacheability)).
The `values` input is refined for the caller on every call, which is what
the pipeline holds a payload to. *Open:* a consumer that reads the
cached definitions without making an instance (the MCP bridge's config
deriver) still sees the discovering account's.
(`OperationInputs::declaredValues()`, `OperationInputs::listedValues()`)

### Reading asks what writing asks

A read tool's access is its write tool's: the operation's permission,
then the resource's access class, for the context its parameters build.

A caller who may not change a thing may not read it through the tool
either. *Open:* a read permission of its own.
(`ResourceOperationToolBase::checkAccess()`)

## Forms

### A panel inside the operation form

A route may name a read-only panel in `_data_surface_panel`, a service
id or class implementing `DataSurfaceFormPanelInterface`, the way it
names a cosmetic layer. The operation form places what it returns
inside the form's `surface` container, and the AJAX rebuild replaces it
along with the dependents of whatever changed, because a panel
describing the resource as it stands has to follow every change; it is
handed the values the elements are built with, not a refined data
surface, so it can tell which variant a slot shows. The
contract panel lives in `data_surface_tool`, since half of it is the
derived tool's JSON Schema. Its "Right now" column, and the React
page's, says "narrowed, and checked at runtime" for a key that is
narrowed and that a runtime rule also checks (decided 2026-10-19,
replacing "narrowed at runtime", which named the runtime rule as what
narrowed a key a static refiner had narrowed); neither knows which of
them narrowed it, so neither says. (`DataSurfaceOperationForm::surfacePanel()`,
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
marks the elements. (`DataSurfaceFormBuilder::flagSurfaceErrors()`,
`DataSurfaceFormBuilder::refreshSurface()`, `Hook\InlineErrorHooks`)

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
`ContractEmitter::keywords()`, `ContractPanel.tsx`)

### The empty option rule

A single select shows its empty option according to four points, and
the stash model under them is unchanged. "Required" in them reads "holds
a value": a required key, or one [its storage always
holds](#a-key-its-storage-always-holds), which is no less unable to be
cleared:

1. A stored value no longer among the options is shown as the empty
   option, selected. It is not put back into the list and no option
   names it; the stored value travels on the element
   (`#data_surface_stale`), and the container posts the paths of such
   selects in a hidden input (`DataSurfaceFormBuilderInterface::STALE_MARKER_KEY`).
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
   message ("@label is required."), set as the element's
   `#required_error` so core's check, which answers first, says it in
   the pipeline's words. A required select standing for a stale value is
   not `#required` to core, since empty there means keep; its label
   keeps the marker.
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
key. Pipeline callers are unaffected: a payload that leaves a key out
keeps it, one that sends the stored stale value back keeps it with a
stale entry, as before. (`OptionsWidget::singleSelect()`,
`RadiosWidget::buildElement()`, `DataSurfaceWidgetBase::unstash()`,
`DataSurfaceHostTrait::surfaceFormValues()`)

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
  demos' widgets drew: an item's own rules (a Length, a Regex, an
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
  module's own answers to its plugin ID.

The served contract's `x-surface.widget` mirrors each choice by asking
the same rule (`CheckboxesWidget::drawsList()`,
`RadiosWidget::drawsOne()`, `TimeWidget::draws()`), and is still written
only when asked for. (`DataSurfaceWidgetManager::asked()`,
`DataSurfaceWidgetBase::answersTo()`, `DataSurfaceWidgetBase::checked()`,
`ListWidget`, `StockWidgetsTest`)

## Discovery

### Whether an operation creates

The catalogue says whether an operation creates only for one that needs
nothing; one that needs a subject is listed as not knowing. `creates`
is on the context an operation returns, not on `#[Operation]`, and the
catalogue builds nothing. (`ResourceCatalogue::creates()`)

### A class that is not there

An alter, operation or variant naming a class that does not load, or
that loads and is a resource or structure but was not discovered (its
module is off: it carries `#[Resource]` or `#[Structure]`, or it is a
plugin that is its own structure), is skipped; one naming a class that
loads and is neither is refused. A disabled module must not break the
site, and a typo must not pass silently. (`ResourceRegistry`)

### `#[UsesStructure]` into the plugin definition

Core's discovery reads only a plugin type's own attribute, so one
definition alter per plugin host copies `#[UsesStructure]` into the
definition under `UsesStructure::DEFINITION_KEY`, run last so a swapped
class is the one read. That is what lets a catalogue list the plugins
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
catalogue's name for the plugin, unless the class also carries
`#[Structure]`, whose id and access then apply; a class
several plugins share (a deriver's derivatives) is one structure, named
after the first of them in sorted order. The catalogue lists it as used
by that same plugin, and an alter names it by the plugin class. The
registry's cache id hashes the plugin-own list beside the compiler
pass's, so a plugin more or less is a different entry. The compiler
pass never sees such a class, so an access class it names is
not registered as an autowired service; the class resolver builds it,
which means no constructor arguments or `ContainerInjectionInterface`.
The demo formatter is written this way and the demo block is not, so
the two spellings sit side by side.
(`ResourceRegistry::discover()`, `ResourcePluginHooks::usedStructureOf()`)

## Derived resources

### Derived definitions at discovery

A resource or structure whose keys are the site's (one per bundle, one
per field) is made by a deriver at discovery, `#[DerivesResources]` on a class
implementing `ResourceDeriverInterface`, and cached with the rest of
discovery under the cache tags of what it was read from. Not a context
handed to `defineInputs()`: it takes nothing but the blueprint, so
that what it declares is the same wherever it is asked for and a static tool
definition, a catalogue and the full schema can describe it before
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
`Resources::fillBlueprints()`, `Resources::operation()`)

### The id shape

A derived id is `<base>:<derivative>`: the base dotted like
any other id, one colon, then the derivative, dotted and holding no
colon, `entity.content:node.page`, `field.item:node.page.body`. A tool
is `data_surface:<id>:<operation>` and a read tool adds `:read`;
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
own operation, `add` or `edit` to match the parent's, so field
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

`ContentEntityStorage` is `data_surface_entity`'s, not one of the
engine's storage classes: what it reads and writes is what the field item
derivation declares (a child per field, a key per property), which the
engine does not own, and it reads the derivation to know which fields
those are. It maps an entity violation to a key by its property path,
the delta dropped, and one about the whole entity to `@entity`; it
drops one about a field the resource does not carry, or one the account
may not edit, as core's form does. (`ContentEntityStorage::prepare()`)

### Tool definitions after a field changes

*Open.* The registry forgets a derived definition when the tags it was
read from are invalidated, but the Tool API caches its plugin
definitions apart, so a field added to a bundle reaches the `add` tool's
static `values` at the next cache rebuild; the `edit` tool refines
`values` per call and sees it at once. Clearing the tool plugin cache on
those tags would couple the engine to the Tool API, or need an
invalidator in `data_surface_tool`.

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
operation" without naming one; a plugin's configuration has no add or
edit, only the instance the host holds. (`DataSurfaceHostTrait`)

### A plugin host cannot overlay a full submission

*Open.* A plugin host (a block, or any plugin using
`DataSurfaceHostFormTrait`) answers NULL from `surfaceSubmissionPath()`,
so the build a full submission is processed against is built from what
is stored, not from what was submitted. A nested subform cannot know its
position in the input at build time: Form API assigns its `#parents`
only later. So a save without JavaScript that moves a parent and its
dependent together (the demo block's entity type and bundle) is refused
by Form API's "is not allowed" select check even when the pair is valid,
because the dependent's select still offers the stored parent's choices.
With JavaScript the parent's AJAX rebuild offers the new choices first
and the case does not arise, and an invalid pair is refused either way.
The fix is a host supplying its subform's parents to
`surfaceSubmissionPath()`. `DemoBlockTest` pins today's refusal.
(`DataSurfaceHostTrait::surfaceSubmissionPath()`)

### Static defaults

A plugin protocol that asks a class for its defaults statically
(`defaultSettings()`, `defaultFieldSettings()`) gets the owner's blueprint
alone, sealed on the spot: no alter, no context. There is no instance
and no context to build with, and alters' keys are mounted where those
protocols do not look. (`ResourcesInterface::defaults()`)

### The demo's bundle-field constraint

`DemoBlockStructure` points at a bundle's fields with a constraint of the
demo's own, `DataSurfaceDemoBundleField`, and its options resolver.
The pattern fetches such a list through an options source, which is
not built yet, and no core constraint names a bundle's fields.
(`DemoBlockStructure::fieldOfBundle()`)

## Examples

### What the examples are made of

Each example of `data_surface_examples` keeps its settings in a config
object of its own and names a subclass of `ConfigObjectStorage`
that says which; an example's resource repeats the previous example's
keys rather than extending its class, so each example reads alone on
screen. The machine names keep the word step (`registration_step2`,
`RegistrationStep2Resource`, the `data_surface_examples.step2` route):
they were never shown to anybody, and renaming them would churn the
catalogue and every test for no reader's benefit. A
ticket price is a `float` with a `Range` minimum of 0.01: core's typed
data has no decimal type and no `Positive` constraint plugin. The event
title is stored as a `string`, not a translatable `label`, because a
`label` would make the classic twin's `#config_target` demand a
language code the data surface side never needs. (`RegistrationStep3Resource`,
`PaidTicketStructure`, `data_surface_examples.schema.yml`)

### The examples' README is held, not generated

The README quotes each example's class from its first attribute down, and
`ExamplesReadmeTest` fails when a quoted block and its file differ, as
`ExamplesToolTest` fails when the three tool answers it quotes change.
Generating it would need a kernel for the answers and a generator for
prose written for a viewer; a drift test keeps the prose free and the
code true.

## Served contract

### The emitter is the main module's, and widgets are asked for

The served contract's emitter reads any data surface and nothing else,
so it is the main module's (`data_surface.contract_emitter`), the
canonical contract the operation form's contract panel shows and
`/surface-api` serves. The roadmap rejects widget vocabulary in the
contract, so `x-surface.widget`, the Form API mapping said for a
renderer, and `multiple`, its qualifier, are written only when a caller
passes `widgets: TRUE`; `data_surface_react` does, for its app. The rest
of `x-surface` (locked, dependsOn, refined, stale, emptyOption, a slot's
by, variants and chosen, a variant's id, checkedOnServer, enumerated,
dynamic, runtime) is the
data surface's own reading of a key, not a widget, and is always written.
The hints are stripped after the schema is built rather than threaded
through it, so the widget a key gets is still read in the same pass as
its shape. (`Contract\ContractEmitter::emit()`)

### No OpenAPI discriminator on a slot

A slot is an `allOf` of `if`/`then` on its parent, and carries no
OpenAPI `discriminator` beside it, because there is no place where
OpenAPI's meaning would be true. A discriminator sits on a schema whose
`oneOf`/`anyOf` (or `allOf` inheritance) lists whole alternatives of
that same object, and names a property inside it, mapped by its string
value to a named or referenced schema. A slot's deciding key is a
sibling: on the slot (`ticket`) the property does not exist, and on the
parent the alternatives are not whole objects but conditionals that
each constrain only the slot, so a reader would resolve `free` and
`paid` as component schema names, which they are not. A parent with two
slots keyed by two siblings would need two discriminators on one
object. Restating the parent as a `oneOf` of whole objects would make it
legal and multiply the parent per variant, per slot. The `if`/`then` is
the JSON Schema statement, and `x-surface.by` names the key for a
reader that wants it. (`ContractEmitter::slot()`)

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
marks the key `dynamic`, never an approximation that is narrower; where
a refiner is declared runtime, the full schema never runs it and marks
the key `runtime` ([Static by default, runtime by
declaration](#static-by-default-runtime-by-declaration)).
Exactness is pursued wherever it is possible, which is what enumeration
is for, and is the goal there, not the guarantee. For the schema as it
stands the rule is about the values it was built for and no others.
Audited on 2026-10-18 (`docs/audit-never-stricter.md`, with what each
case became), and held by `AdvertisedSchemaAcceptanceTest`: for each
example and fixture, a table of accepted and refused documents, each
asserted against the pipeline and both documents with a standard
validator, `format` not asserted and `default` not filled. Where the
schema is exact the two agree both ways; where it is `dynamic` or
`runtime` it may only be wider. One exception stands: a stale value is
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
(`ContractEmitter::define()`, `ContractEmitter::reference()`,
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
(the largest is the demo block's field, entity types times bundles) with
room for a real site's bundles, and is small enough that a
country-sized list (some 250) is refused outright rather than written.
Above it the key is `dynamic`. A key watching free text, a number, a
boolean or a key an alter mounted stays `dynamic` whatever the count:
its domain is not a list, so there is nothing to enumerate, and
inventing one (sampling numbers, guessing at a licence) would state a
rule the refiners never made. Such a key keeps `dependsOn`, and a client
asks `/refine` when a watched key changes, as before.
(`ContractEmitter::enumerate()`)

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
The Tool API bridge copies the same flag ([Required is the resource's,
and a listing carries no stored value](#required-is-the-resources-and-a-listing-carries-no-stored-value)); the served contract's
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
(`ContractEmitter::required()`, `DataSurfacePipeline::validateLevel()`,
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
slot's chosen variant) is each of its keys sent `null`: its optional
keys clear and a required one is refused in its own words, so the
accepted document is a map its def describes; until 2026-10-18 the
pipeline took `contact: null` whole, which the schema refused.
(`DataSurfacePipeline::acceptKey()`, `DataSurfacePipeline::acceptChild()`,
`ContractEmitter::nullable()`)

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
hold nothing.

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
(`DefinitionMetadata::holdsValue()`, `Resources::markAlwaysHeld()`,
`ConfigObjectStorage::mayClear()`, `DataSurfacePipeline::cannotBeCleared()`,
`ContractEmitter::nullable()`, `StockWidgetsTest::testKeyItsStorageAlwaysHolds()`,
`AdvertisedSchemaAcceptanceTest::testOpeningHours()`)

### A secret is optional, never nullable

A secret is advertised optional in both documents and the tool's
`values`, whatever it requires, because left out or `null` it keeps
what is stored (the codec's keep-on-empty); its type is the declared
one, not nullable, `writeOnly`, with no default; the values a document
carries for it are `null`, as the masking rule has always said, which a
reader takes as the key left out. A required secret never set is still
refused by the pipeline as missing. Before 2026-10-18 a required secret
was in `required` and its type refused the `null` its own `values`
carried. (`ContractEmitter::required()`, `InputDefinitions::fromDefinition()`)

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
(`Pipeline\Coercion`, `DataSurfacePipeline::coerce()`)

### A pattern both engines read alike

A Regex is PCRE and a `pattern` is ECMA-262 (opis, in the suite, reads
it with PCRE again). Its body is carried only when it stays inside a
subset both read alike: literals, character classes with `\d` `\w`
`\s`, the quantifiers `*` `+` `?` `{n,m}` (lazy or not), alternation,
capturing, named and non-capturing groups, `^`, `$` only with the `D`
modifier, and `\b`; the modifiers `u` and `D` are carried, since ECMA's
`$` is already the very end, which is what `D` says. Anything else (`\A`
`\z` `\Z` `(?i)` `(?P<` `(?>` `a++` `[[:alpha:]]` `\x{…}` `\Q…\E` `\h`
`\R`, `.`, a negated class escape, look-around, the `x` `s` `m`
modifiers, a `$` without `D`, an escaped delimiter that is no ECMA
syntax character) leaves `pattern` out, which is wider, and names
`Regex` under `checkedOnServer`; `x-surface.patternMessage` still
carries the explanation. Never approximated. Every shipped pattern ends
in `$`, so each gained `D`: a licence or a machine name with a trailing
newline was never meant. One consequence outside this module: the Tool
API's own schema leaves a pattern with a modifier out, so the derived
tools no longer advertise the licence's. (`Contract\PatternTranslation`)

### `format` is an annotation

The emitter writes `format` (`email`, `uri`, `date-time`) as JSON Schema
2020-12 defines it by default: an annotation, which a client may opt
into asserting. Drupal's checks are not the formats' (an
internationalized address, a URI with a space, a date with no time), so
a client that asserts formats may refuse what the pipeline accepts; the
suite's validator does not assert them, as a standard one does not.
(`ContractEmitter::type()`)

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
an `allOf`. (`ContractEmitter::parts()`,
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
(`Contract\PlainText`, `PlainTextMarkup`, `PlainTextDocumentsTest`)

### A refused submit is an answer

`POST .../submit` answers a refusal with 200 and `committed: false`,
the violations as data, never 422 or 400: a value the pipeline refuses
is the expected outcome of asking, exactly as `/validate` reports it and
as the operation form shows it beside the field, and a client renders
both the same way from the same keys. A status code is kept for what is
not an answer about the values: 403 when access refuses the request
before it runs, 400 when the body cannot be read. Access refused inside
the pipeline (a forbidden answer the route did not already give) is
still filed as the `@access` violation, the pipeline's own rule.
(`ResourceApiController::submit()`)

### An empty identity key names nothing

Where a created thing now lives is read from the outputs and the
identity the write accepted, and checked against the operation those
build; an identity key the write left empty is not compared, since it
named nothing: a content entity's add knows its id as empty, and its
`id` output says what storage made it. Before, every accepted identity
key was compared, and an add whose id storage gives could never say
where its thing lives. (`ServedOperations::created()`)

### A fingerprint is opt-in

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
answer, and asks `/refine` for the rest. On a change it collects every
key that depends on the changed one, transitively, through `dependsOn`;
when none is marked `dynamic` or `runtime`, nor holds one inside it, it
reads each from the full schema's conditionals for the new values and
sends nothing. When one is, or the resolver meets anything it cannot
read (a stale value it was handed as `null`, a reference left
unresolved, a conditional naming a key outside its frame), it posts to
`/refine` as before. Validate and submit always ask: the pipeline has
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
submit.

The page's settling mirrors the server's exactly, orphans included,
because a local answer is a stand-in for `/refine`'s and must be the
same answer. An orphan is what makes a save judge the right thing: the
stored room under a moved venue is shown on the empty option and its
path travels back stale, so the pipeline reads it as the stored value
and refuses it, as the form's Save does. A page that kept the orphaned
room, or kept a capacity typed under it, would draw a form the server
never draws and send values the server would have dropped. So the page
applies the same discard rule and the same fixed point, down to
re-settling every orphan on every change (a capacity typed under an
orphaned room falls back to what is stored when the pricing changes),
and a Vitest test holds it to recorded `/refine` answers for examples 2
and 3, keyword for keyword. (`narrow()` in `app/src/contract.ts`,
`ResourceApiController::schema()`)
