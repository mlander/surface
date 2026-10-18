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
**structure** is a shape with members and no identity or storage of its
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

The project keeps its name, Data Surface, and the engine keeps the name
of what it seals (`DataSurfaceInterface`, a *data surface*); the served
contract keeps its `surface` and `situation` keys and its `x-surface`
keyword, and the URL paths their `{surface}` and `{situation}`
parameters, because clients already read them; tool ids are unchanged.
(`ResourceRegistry::assertResourceClass()`, `ResourceRegistry::discover()`,
`ResourceCollectorPass`, `DerivedResource::kind()`)

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
`#[Shape]` class reused at many keys, will be, and the two must never
share a name. How accepted values are laid out in storage, where that
differs from how they are asked for, is a **layout**:
`SettingsLayoutInterface`, and `HasStorageLayoutInterface::storageLayout()`
for an alter's mount. "Shape" stays in prose for the general idea of what
a thing takes, a resource's shape or the shape of a value, and in code
is reserved for named scalar shapes. (`ResourceBlueprint`,
`ResourceBlueprintAdditions`, `BlueprintAdapterBase`)

## Authoring

### A shape is static

`DefinesInputsInterface::defineInputs()` and `HasOutputsInterface::defineOutputs()`
are `public static`, and the build step calls them on the class, never
on an instance; a resource's or structure's `#[RefinesInput]` methods
are static too, and one that is not is refused when it is built. Shape is a
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
resource's or structure's shape, refiners and operations, an alter's
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
that state is, a shape says with `addCacheableDependency()`, for a
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

### Cacheability on the shape

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
else filled from storage. A PHPStan rule is left for later. The module
has no custom rules to add one beside (`phpstan.neon` configures only
the level, paths and ignores), and with the structural layer in place
the rule left to write would flag `\Drupal::` and static state inside a
static refiner, which the guard already catches whenever a test runs the
refiner; it would add reach to refiners no test exercises, which is
worth a rule once there are third-party refiners to hold to it.
(`RefinesInput`, `Resources::bindRefiners()`, `RuntimeRefiner`,
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
resources too: two shapes with a map key of one name shared its
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

## Alters

### Where an alter's keys live

An alter's key is mounted at `third_party_settings.<module>.<key>`
(outputs: `third_party_outputs.<module>.<key>`), not beside the
owner's. The owner's storage and schema then never have to know a
contributor's keys, which is how core keeps third-party settings.
(`ResourceBlueprintAdditions`)

### An alter cannot attach

`attach()` on an alter is refused. An alter's keys are mounted under
its module, and an attached structure inside that mount is not built
yet.
(`ResourceBlueprintAdditions::attach()`)

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
variant is a shape with no class, so no alter, refiner, storage or access
class of its own, and a declared variant always wins.
(`Resources::buildResource()`)

### A parent watching a child

A parent's refiner that watches the whole key a child is attached at is
refused, as is one that refines it. The pattern says a parent cannot
refine into a
child and a child cannot read a parent's value; refusing the watch too
makes the wall hold both ways. (`Resources::assertWalled()`)

### An empty variant is an empty map

A slot variant may declare no keys, the spelling of "this choice has
nothing more to say". It renders as nothing (an empty container on the
form, nothing on the React page), its value is an empty map, and both
contracts and the tool state an object with no properties; a value left
by another variant does not fit it and goes, as between any two
variants. The map widget serves any complex definition and answers an
empty one with `[]`: a map that declares nothing can hold nothing, and
before this the form threw for want of a widget. A key that does not
apply at all, rather than applying with nothing to say, is ROADMAP item
2. (`MapWidget`, `Field.tsx`)

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
context knowing its `delta`. Built once because a shape cannot depend
on a value, and how many items there are is a value; the frames differ
only in the values they are handed and their position. The entry
carries a `Collection` rather than an attachment, so the key is the
parent's own and every door reads it as a list: an array of references
to the child's def in both contracts, a list input of the child's maps
in the tool, a details of item details on the form. Each item's
context is carried (`Collection::contextFor()`), and nothing reads an
item's delta yet: a child that names storage of its own is not routed
per item, and the parent stores the list. *Open:* routing an item to
its child's storage, which needs an identity key on the child to say
which stored item it is. (`Resources::buildResource()`, `Collection`)

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
`user.permissions` when the answer read permissions.
(`AccessAnswer`, `Resources::childAccess()`)

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

*Open.* A whole resource whose access answers locked, asked on its own,
should render read-only with no save, refuse its write tool and allow
its read tool. Every caller of `ResourcesInterface::access()` (the route,
the form's floor, the tools, the React controllers) would have to tell
locked from allowed, which is more than the child case, so for now a
locked answer at the top is a refusal, the reading that writes nothing.
(`Resources::atTheTop()`)

### A variant is open or not offered

A slot's variant invoked through an operation whose access is not open is
dropped from the slot, so its deciding value can no longer be chosen,
and no derived variant is put in its place. The engine has no lock per
variant, and offering a value whose shape could not be changed is the
same as not offering it. *Open:* a locked variant shown read-only.
(`Resources::buildResource()`)

### A chosen variant may refuse

A slot whose deciding key is locked has its variant chosen by what the
parent is, as a field's settings are chosen by its type, and the parent
cannot be written without that variant. Its access class, asked in the
context it is invoked in however it was invoked, may refuse the whole
parent, never allow. The build leaves a variant that is not open out of
the slot ([A variant is open or not
offered](#a-variant-is-open-or-not-offered)), so it is looked up among
the slot's declared variants. A field type's settings refuse the field
they belong to this way, and nothing below a parent can open what its
own access closed. Every other child's access decided its own keys when
it was built ([One invocation model for both
levels](#one-invocation-model-for-both-levels)) and refuses nothing
above it: a field its access hides is left out of its entity, which is
not a reason to refuse the entity. This replaces an earlier rule under
which any child invoking no operation of its own could refuse its
parent; it was asked nowhere else, and now that its access answers at
build, refusing the parent as well would turn a hidden field into a
refused form. (`Resources::access()`, `Resources::childAnswers()`)

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
description says that a key left out keeps its current value and names
the read tool that returns the current values.

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
scalar for every identity key. The context is the truth and cannot be
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
whichever variant holds. (`InputDefinitions::secrets()`,
`OperationInputs::stored()`)

### A static tool definition is one account's

*Open.* A derived tool's static definition builds the resource once, at
discovery, for whichever account is current then, so a child whose
access depends on the account is as that account saw it. The `values`
input is refined for the caller on every call, which is what the
pipeline holds a payload to, so only the advertised static definition
can be off. (`OperationInputs::declaredValues()`)

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
derived tool's JSON Schema. (`DataSurfaceOperationForm::surfacePanel()`)

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
the stash model under them is unchanged:

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
   shown: the shape's author chose the default.
3. On first entry a required select comes up on the empty option, and
   a save leaving it there is refused with the key's own required
   message ("@label is required."), set as the element's
   `#required_error` so core's check, which answers first, says it in
   the pipeline's words. A required select standing for a stale value is
   not `#required` to core, since empty there means keep; its label
   keeps the marker.
4. An optional select keeps its empty option always. Choosing it on a
   select with nothing stale behind it clears the key; on a stale one it
   is what was already selected, so it keeps.

The placeholder option this replaced ("Previous value X is no longer
available", posting a sentinel from the select) told the person what
was missing, but put an option in the list that was no value of the
key. Pipeline callers are unaffected: a payload that leaves a key out
keeps it, one that sends the stored stale value back keeps it with a
stale entry, as before. (`OptionsWidget::singleSelect()`,
`DataSurfaceWidgetBase::unstash()`, `DataSurfaceHostTrait::surfaceFormValues()`)

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
structure is its own structure in turn, inheriting the static shape, so an
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
by that same plugin, and an alter targets it by the plugin class. The
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

A resource or structure whose shape is the site's (one per bundle, one
per field) is made by a deriver at discovery, `#[DerivesResources]` on a class
implementing `ResourceDeriverInterface`, and cached with the rest of
discovery under the cache tags of what it was read from. Not a context
handed to `defineInputs()`: the shape takes nothing but the blueprint, so
that it is the same wherever it is asked for and a static tool
definition, a catalogue and the full schema can describe it before
anyone calls; a shape that read a bundle from its context would be a
different shape in every context, with one id. And not one class per
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
which is what a derived shape is for. (`ResourceDefinition::name()`,
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
parameter, `/surface-react/{surface}`, takes a colon as it is. An alter names one derivative by its id or
every derivative of a deriver by its base, `#[Alters('entity.content')]`.
A string that names no resource, structure or base is skipped, as a class from a
module that is off is skipped, so a mistyped id passes silently where a
mistyped class does not. (`ResourceRegistry::discover()`)

### Derived, then layered

A field item's structure is derived from the item's typed data, since a
field type already says what its item holds, and layered with what
typed data does not say: the field's label and description, required
on the main property, the literal default, the constraints a shape
can state (`Length`, `Range`, `Regex`, `Choice`) and what the settings
narrow (a list's allowed values, a text field's formats, a reference's
targets, a date's form). What typed data says that a shape cannot
(`LinkType`, `ValidReference`) is left to the entity's own validation
at prepare, whose violations land on the key. A field type whose
derivation is thin is written by hand, `#[FieldItemStructure(type:)]`, and
replaces the shape and its refiners only: the field's label, operations
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
engine's storage classes: what it reads and writes is the field item
derivation's shape (a child per field, a key per property), which the
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
(`defaultSettings()`, `defaultFieldSettings()`) gets the owner's shape
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
rule the shape never made. Such a key keeps `dependsOn`, and a client
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
there. (`ContractEmitter::required()`)

### Absent keeps, null clears

A key can be sent three things, and on an optional key they mean three
things: left out, what it holds stands (what is stored, on an operation
that changes what exists; the declared default, on one that creates),
so a partial update keeps every key it leaves out; `null`, or the empty
string a form sends for nothing, clears it, so "- None -" on an
optional select, an emptied text box and a tool's `null` empty a key;
a value is that value. A required key refuses `null` and `''` as
missing. A list keeps what it holds when sent nothing and is emptied
with `[]`; a secret keeps what it holds when sent nothing and is
emptied with its clear marker. The schema says it: an optional key's
type allows `null` (`["integer", "null"]`, and an optional choice's
`oneOf` carries an untitled `{const: null}`), a required key's does
not, and a secret's does not either, since `null` does not clear it.

This parts from the Tool API, where a caller's null for an optional
input means the default. A Tool API default is static, so a null and an
absent key there say the same thing; an operation that edits stored
values defaults to what is stored, so if null meant the default a
caller could never clear a key (the base field override storage once
skipped NULL, and an override could not be undone). The
Tool API itself keeps an absent key and a null apart where the
difference is a fact, for outputs (`omit_when_null`). Decided
2026-10-18, after a first reading that collapsed null into "keep" was
tried and reverted. (`DataSurfacePipeline::acceptKey()`,
`ContractEmitter::nullable()`)

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
