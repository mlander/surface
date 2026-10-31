# Glossary

Every term the module uses, in one sense each. The first part follows
the order of [the introduction](introduction/index.md), which defines
each term on its own page; the second part holds the terms the reference
pages use. Each entry gives the plain definition first, then the precise
one, then where to read more. The "Precisely" wording is the reference
definition and uses the reference's words; the plain sentence is enough
to read the introduction.

## Terms in the introduction's order

### Resource

A resource is a thing your site keeps values for, that can be asked for
on its own: a content type, a placed block, a module's settings.

Precisely: a thing with identity and storage that operations read and
write; the form, the tool and the schema are derived from it. Declared
as a class carrying `#[Resource(id, identity:, storage:, access:)]`,
and optionally `label:` and `description:`, what it is called and what
it is for, beside the id. A resource with no identity keys is a
singleton. It may live in a config
object, a config entity, State, a plugin's settings or a content entity;
only its storage knows which. Never instantiated: no constructor, no
service. The class implements `Resource\ResourceInterface`, in
`src/Resource/` or `src/Structure/`.

Introduced in [Resources](introduction/02-resources.md). Reference:
[Declaring a resource or structure](declaring-a-resource.md),
[Resources and structures as classes](resources.md).

### Structure

A structure is a group of keys with no identity, storage or operations
of its own, only ever held by something else: a block plugin's
settings, held by the placed block, which is the resource.

Precisely: a group of keys with no identity or storage of its own, only
ever held by something else: attached to a parent, one of the variants
at a key, or configuring a plugin, whose host supplies the operation,
its permission and the storage. It has no permission of its own; it may
answer access for its keys. Declared as a class carrying
`#[Structure(id, access:)]`, and optionally `label:` and `description:`,
its def's title and description (with none, it takes those of the one
plugin that holds it), implementing `Resource\StructureInterface`;
or a plugin that is its own structure, `#[UsesStructure]` with no
argument. The six ways one is made of others are side by side in
[Which way to compose](resources.md#which-way-to-compose).

Introduced in [Structures](introduction/03-structures.md). Reference:
[On a plugin](declaring-a-resource.md#on-a-plugin),
[Children](resources.md#children).

### Shape

A shape is one value declared once, as a class (its type, label,
description, what it allows and an example), and used at many keys, so
it reads the same everywhere and tightening it tightens every key.

Precisely: a named type, in Smithy's sense, of which there are three
kinds: a resource, a structure, and a named scalar shape, one value
(its type, label, description, constraints, examples) declared once
and used at many keys. The third is the one the attribute names: a
class in `src/Shape/` carrying `#[Shape(id)]`, whose static `define()`
returns a plain data definition, used in the type position of `add()`;
each key may only narrow it, an alter of it tightens it at every key,
and the served contract writes it once, as a def. Never the blueprint
or the layout.

Introduced in [Shapes](introduction/04-shapes.md). Reference:
[Named shapes](declaring-a-resource.md#named-shapes).

### Key

A key is one value a resource or structure holds, with a type, a label
and a default.

Precisely: one entry of a resource's or structure's declaration, added
with `$inputs->add($key, $type, $label, default:)` in its static
`defineInputs()`. What the key accepts is its definition.

Introduced in [Keys](introduction/05-keys.md). Reference:
[The class](declaring-a-resource.md#the-class),
[Key names](declaring-a-resource.md#key-names).

### Declare

To declare a key is to write it once in the class of the resource or
structure that holds it, so that every caller reads it from there.

Precisely: a resource or structure declares its keys into a blueprint,
in static `defineInputs()` (and `defineOutputs()` for what it emits).
The declaration is static, asked of the class, so the class is never
constructed.

Introduced in [Keys](introduction/05-keys.md). Reference:
[Declaring a resource or structure](declaring-a-resource.md).

### Rule

A rule says what a key allows: required, a length, a range, one of a
list.

Precisely: a core validation constraint on the key's definition, added
with `addConstraint()`, or the key's required flag, set with
`setRequired()`. A list of allowed values is a constraint too, which an
options resolver reads as the list it offers.

Introduced in [Rules on a key](introduction/06-rules-on-a-key.md).
Reference: [Required](declaring-a-resource.md#required),
[Which constraint to write](options.md#which-constraint-to-write).

### Rule between keys

A rule between keys says that what one key allows depends on another
key's value.

Precisely: a narrowing of one key's definition given the values of the
siblings it watches, written as a declared rule or a refiner. It may
only narrow what the key allows, never widen it, and the answer is
checked narrower on every run.

Introduced in [Rules between keys](introduction/07-rules-between-keys.md).
Reference: [Refinement](refinement.md).

### Declared rule

A declared rule is a rule between keys whose answer is known ahead of
time from the values alone, written as data beside the keys.

Precisely: a rule between keys whose condition and effect a schema can
state, written in the blueprint beside the keys it is about,
`$inputs->narrow($key)->when(Condition::...)` and its effects. A static
refiner by construction that only tightens, stated by the full schema
as written.

Introduced in [Rules between keys](introduction/07-rules-between-keys.md).
Reference: [Declaring a rule](refinement.md#declaring-a-rule).

### Refiner

A refiner is a method that works out what one key allows from the
values of other keys, for a rule a schema cannot state or one that needs
the site.

Precisely: a `#[RefinesInput]` method that returns a narrower
definition for one key given the values of the siblings it watches. A
refiner may restrict what a definition allows; it may never widen it.
It is static unless declared runtime: a static refiner reads only the
values, a runtime one may read the site and is asked when the request
comes in.

Introduced in [Rules between keys](introduction/07-rules-between-keys.md).
Reference: [Refining](refinement.md#refining),
[Static and runtime refiners](refinement.md#static-and-runtime-refiners).

### Identity

Identity is the keys that say which one of a resource's many things a
request is about, such as a content type's machine name.

Precisely: the keys `#[Resource(identity:)]` names: a content type's
machine name, a field's entity type, bundle and name. A context says
which of them it knows; an identity key the context knows is locked to
that value, and one it does not know stays open.

Introduced in [Which one: identity](introduction/08-identity.md).
Reference: [Operations and identity](declaring-a-resource.md#operations-and-identity).

### Singleton

A singleton is a resource that exists once, such as a module's
settings.

Precisely: a resource with no identity keys, such as a settings object.

Introduced in [Which one: identity](introduction/08-identity.md).
Reference: [A simple config object](storage.md#a-simple-config-object).

### Operation

An operation is one way of asking for a resource: create one, update
one, delete one, configure the settings. Each has a permission, and
each is what a route and a tool are made from.

Precisely: one way a resource is asked for: create, update, reuse.
`#[Operation]` states its id, label, description, the permission that
gates it and whether it creates. On the class when asking needs nothing
more (a settings object's `configure`); on a static method returning a
context when the context says more, such as which identity keys are
already known (and locked). A structure may declare operations too, for
what holds it to choose between. Operations are what routes and tools
are generated from: a route naming a resource class and an operation is
a working form with no form class behind it, through
`Form\SurfaceOperationForm`, and the tool door derives one tool
per operation that can be asked on its own. Who may submit is the
operation's permission and then the resource's access class, and the
pipeline consults that answer before it reads or writes anything, so a
form, a tool and the agent or Drush command calling it resolve one gate
rather than several.

Introduced in [Operations](introduction/09-operations.md). Reference:
[Operations: create, update, and the rest](resources.md#operations-create-update-and-the-rest).

### Storage

Storage is where the values are saved: a config object, a config
entity, a content entity. The resource does not change when its storage
does.

Precisely: where accepted values are written, and the place that knows
the distance between how a resource asks for its values and how the
store lays them out (a layout). A resource names its storage class, a
`ResourceStorageInterface` with `load()`, `prepare()` (rehearse the
write, no side effects) and `commit()`; a plugin's host supplies one of
the engine's (plugin configuration, field settings) for what it holds.
Storage composes along the tree of attached children.

Introduced in [Storage](introduction/10-storage.md). Reference:
[Storage](storage.md), [What storage is](storage.md#what-storage-is).

### Attach

To attach is to hold a structure under one key of a resource or
structure.

Precisely: an attached structure is a child at a key, its own class
with its own keys: `attach()` for a fixed child, `attachVariants()` for
variants a sibling key chooses between. A child may be a resource with
storage of its own (a field's storage). Declared on the
`Resource\BlueprintInterface`; the build records it as an `Attachment`.

Introduced in [Resources made of structures](introduction/11-resources-made-of-structures.md).
Reference: [Children](resources.md#children).

### Variants

Variants are several structures at one key, of which another key's
value chooses one: a placed block's settings, chosen by its plugin.

Precisely: a tagged union whose tag is a sibling key, attached with
`attachVariants($key, by: $sibling)`; each variant is a structure for
one value of that key, `#[Variant(of:, at:, for:)]`. Always open: the
parent names no variant, and any module may declare one. Until the
deciding key holds a value, the key is an `any` placeholder; once it
does, the key is the chosen variant's map.

Introduced in [Resources made of structures](introduction/11-resources-made-of-structures.md).
Reference: [Variants](resources.md#variants).

### Door

A door is a place that asks for an operation's values: the form, a Tool
API tool, the command line (`drush tool:run`), MCP for AI agents, ECA
for automation, HTTP with an OpenAPI document, a React page. One tool
made from an operation can be run by everything that runs Tool API
tools: the command line, ECA, FlowDrop, Maestro, MCP servers and AI
agents.

Precisely: every door reads the one sealed data surface and nothing
else: the generated form, the tool, the command line, MCP, ECA, HTTP
with its OpenAPI document, and the React page. That is why they agree
on what is accepted, and why one module can change all of them at once
without touching any.

Introduced in [Doors](introduction/12-doors.md). Reference:
[The doors](catalog-command.md#the-doors),
[The served contract](served-contract.md),
[Tools](resources.md#tools).

### Alter

An alter is another module's class that adds keys to a resource it does
not own, or tightens its rules. It may tighten, never loosen, so what
the owner promised still holds.

Precisely: another module's class in its `src/Alter/`, carrying
`#[Alters]`. It adds keys to a resource or structure (mounted under
`third_party_settings.<module>`), rewords a label with `describe()`,
offers more values on a fixed list with `extendChoices()`, and narrows
with `#[RefinesInput]` methods, on the owner's keys or its own, and may
answer access after the owner (`OperationAccessInterface`, the access
class's interface), locking or leaving out and never opening. It never
removes a key or a value from the declaration, never retypes, adds no
output (what a call answers is its operation's), and attaches a
structure only at its own mount.

Introduced in [Other modules](introduction/13-other-modules.md).
Reference: [Alters](resources.md#alters),
[A whole alter](resources.md#a-whole-alter).

### Owner

The owner is the class that declares a resource or structure, in the
module that ships it.

Precisely: the `#[Resource]` or `#[Structure]` class that declares it,
or a plugin that is its own structure. The owner is the first
contributor: its keys and its `#[RefinesInput]` methods are
contribution number one, and they are held to the same rules as
everybody who arrives later.

Introduced in [Other modules](introduction/13-other-modules.md).
Reference: [The two roles](refinement.md#the-two-roles).

### Site default

A site default is a value a site sets for a key in place of the default
the class declares.

Precisely: a declared default is the module's. A site that wants a key
to start from another value sets it in configuration,
`surface.defaults`, not in an alter: an alter may not change an
owner's default. Each entry names the resource (or a plugin's
structure, by the id the catalog lists), a key path from it, and the
value as a form posts it.

Introduced in [The site and the page](introduction/14-site-and-page.md).
Reference: [A site's own defaults](declaring-a-resource.md#a-sites-own-defaults).

### Seed

A seed is a value a route starts or fixes for the page it serves.

Precisely: a value a route puts into the operation's context: a fixed
value or a default. A prefill is a value the form's elements start from
that never enters the context. A reference is a seed whose whole value
is a name in braces, `'{name}'`, whose value is the raw value of the
route's path variable of that name.

Introduced in [The site and the page](introduction/14-site-and-page.md).
Reference: [A route that fixes or fills keys](forms.md#a-route-that-fixes-or-fills-keys).

### Access

Access decides which keys an account may change or see, beyond the
operation's permission.

Precisely, the access answer: what the operation's permission, and then
the access class, answer for an account, then each alter that answers
access, the stricter standing; the access class's is an `AccessAnswer`
(open, locked or omitted). Its kind is read from its cacheability and
never declared: static varies by the account's permissions or roles
alone and is stated in the full schema and the tool listing for that
account; runtime is anything else, marked there `x-surface.live`, as a
runtime refiner's key is, and settled once the thing is known.

The access class is what may refuse once the operation's permission
allows: the part of access that depends on the thing itself. At a
child, whether its keys are open, locked or omitted. Named by `access:`
on `#[Resource]` or `#[Structure]`, a `Resource\OperationAccessInterface`
in `src/Access/`.

Introduced in [Access](introduction/15-access.md). Reference:
[Access](resources.md#access),
[Static and runtime access](resources.md#static-and-runtime-access).

### Data surface

A data surface is the fixed result for one resource or structure: every
key and every rule, after the owner, other modules, the site, the page
and access have each had their say.

Precisely: what the build step seals from a resource or structure in a
context: an immutable group of core data definitions
(`DataDefinitionInterface`, held in a `DefinitionMap` that refuses
writes; [Built on typed data](on-typed-data.md)), plus the map of which
definitions depend on which others and the refiners that narrow them.
Once sealed it is only read. It has two halves: what a host
**accepts**, and what its execution **emits**, declared in the same
vocabulary (see [Outputs](outputs.md)). It is what the pipeline, the
form, the widgets and the emitter read, so the same data surface answers
a form submit, a Drush command, a config action, an agent call and a
test, without any of them owning a second copy of the rules. In the
code, `SurfaceInterface`.

Introduced in [Sealed](introduction/16-sealed.md). Reference:
[The build step](how-it-fits.md#the-build-step).

### Sealed

Sealed means fixed: once each party has had its say, the result does
not change, and every door reads that same result.

Precisely: a contribution is made under its module's own name before
the seal, and after the seal nothing widens, so no door sees anything
but the sealed result and nothing a door was told stops being true.
Refinement may only narrow: whatever a data surface advertises when it
is sealed stays true of everything it accepts afterwards, so a caller
that read it once, or a machine-readable contract emitted from it, is
never contradicted later.

Introduced in [Sealed](introduction/16-sealed.md). Reference:
[The narrowing table](refinement.md#the-narrowing-table),
[Never stricter than the runtime](served-contract.md#never-stricter-than-the-runtime).

### Build

The build is the step that makes the data surface from the class, the
alters, the site's defaults and the operation, and seals it.

Precisely: the build step, `surface.resources`
(`ResourceBuild\ResourcesInterface`), turns a resource or structure
class and a context into a sealed `SurfaceInterface`. The blueprint
is filled once at build, then sealed; the context is applied once by
the build step. It exists because core holds no group of data
definitions that several parties write in order and that is then fixed
([Built on typed data](on-typed-data.md)).

Introduced in [Sealed](introduction/16-sealed.md). Reference:
[The build step](how-it-fits.md#the-build-step).

## Terms in the reference

### Blueprint

The blueprint is the object a class writes its keys into.

Precisely: what a resource or structure declares its keys into, handed
to `defineInputs()` and `defineOutputs()` as a `BlueprintInterface`; an
alter gets the add-only `BlueprintAdditionsInterface`. Each key it holds
is a core data definition: `add()` gets a `DataDefinition` from core's
typed data manager and returns it
([Built on typed data](on-typed-data.md)). Filled once at build, then
sealed.

Not in the introduction. Reference:
[The class](declaring-a-resource.md#the-class).

### Layout

A layout says how the stored values are arranged when that differs from
how they are asked for.

Precisely: how a storage lays accepted values out where that differs
from how they are asked for, a `StorageLayoutInterface`; an alter hands
one for its mount with `AltersStorageLayoutInterface::storageLayout()`.

Not in the introduction. Reference:
[StorageLayoutInterface](storage.md#storagelayoutinterface).

### Definition

A definition describes one value: its type, its label and its rules.

Precisely: one core `DataDefinitionInterface` describing a single
value: its type, label, description, constraints, whether it is
required, and the interim default and example metadata the module
carries until core lands its own (`DefinitionMetadata`).

Not in the introduction. Reference:
[The class](declaring-a-resource.md#the-class).

### Definition map

The definition map is the list of everything a data surface states, one
entry per key.

Precisely: everything a data surface advertises, as one ordered,
validated collection: one entry per key, in declaration order. A
`DefinitionMap` of `Entry` value objects, each carrying its definition,
who introduced it, whether it is locked, what it refines against and
whose values it offers.

Not in the introduction. Reference:
[Contract and payload](#contract-and-payload).

### A key that does not apply

A key that does not apply is one that has no meaning for some values of
another key, so it is not asked for then.

Precisely: a key with no business being there for some values of
another, as a send day for a monthly bulletin; not a key narrowed to no
values. A refiner answers `DoesNotApply::here()` from a method whose
return type names it, or a declared rule says `doesNotApply()`; storage
keeps what the key holds.

Not in the introduction. Reference:
[When a key does not apply](refinement.md#when-a-key-does-not-apply).

### Resolver

A resolver turns a rule that lists allowed values into the list a form
offers, with labels.

Precisely: a `SurfaceOptionsResolver` plugin that turns a
constraint into a value/label list with its cacheability, so the list
that validates a value and the list that is offered as form options are
the same list, derived rather than repeated. The plugin type is
`Options\SurfaceOptionsResolverInterface`, its plugins in
`Plugin/SurfaceOptionsResolver/`.

Not in the introduction. Reference:
[The resolver plugin type](options.md#the-resolver-plugin-type).

### Keys and variants from the site

Keys and variants from the site are one key, or one variant, per thing
the site has, listed when the data surface is built.

Precisely: one key per thing the site has, such as a checkbox per form,
in a map at a key: `addEach($key, $type, from:)` for a value per thing,
`attachEach($key, Structure::class, from:)` for a structure per thing;
or one variant per thing, each read from its JSON Schema or a structure
class, `attachVariants($key, by: $sibling, from:)`. The provider is one
interface for both, a service implementing `SiteListInterface`, whose
`list()` answers a `SiteList`, asked at every build; a closed one,
`ClosedSiteListInterface`, names every structure it may list.

Named in [Resources made of structures](introduction/11-resources-made-of-structures.md).
Reference: [Keys that come from the site](resources.md#keys-that-come-from-the-site),
[Variants from the site](resources.md#variants-from-the-site).

### Host

A host is the plugin whose settings a structure describes.

Precisely: the plugin whose configuration a structure is, and whose
protocol it has to satisfy: a block, a field formatter, an action, a
condition, a field type, any configurable plugin. It names the class it
holds with `#[UsesStructure]`, or is the structure itself when the
attribute names none, and supplies the context and the storage,
because only it holds the instance. The `Plugin/*Base` classes and the
`Form\*` traits adopt it.

Not in the introduction. Reference:
[On a plugin](declaring-a-resource.md#on-a-plugin),
[Plugins](resources.md#plugins).

### Context

A context says where a resource or structure is being asked for: which
operation, and what is already known.

Precisely: an `OperationContext`: where a resource or structure is
being asked for. Its id (the operation id, or a host's own verb such
as `configure`), whether it creates, which identity keys are known, and
any narrowing or child contexts the operation adds. Built by an
operation (or bare, by a plugin host), applied once by the build step,
and never seen by a method that fills a blueprint or by a refiner.

Not in the introduction. Reference:
[Operations and identity](declaring-a-resource.md#operations-and-identity).

### Collection

A collection is a list of the same group of keys, repeated.

Precisely: a list of child instances at a key, declared with
`attachList($key, Child::class, min:, max:)`: the list and its Count
are the parent's, and each item is the child's, refined and validated
in its own frame.

Named in [Resources made of structures](introduction/11-resources-made-of-structures.md).
Reference: [Collections](resources.md#collections).

### Mixin

A mixin is a structure whose keys are added to another's own keys, not
held under a key of their own.

Precisely: a structure whose members are merged into the owner's own
keys, `$inputs->mixIn(Structure::class, reads: [...])`: stored flat,
refined in the owner's frame, and emitted as `allOf` of its def and the
owner's properties. Nest for a thing of its own, mix in for shared
members that are part of this thing.

Named in [Resources made of structures](introduction/11-resources-made-of-structures.md).
Reference: [Mixins](resources.md#mixins).

### Port

A port is a value a structure reads from what holds it, without
storing it.

Precisely: a value a structure reads from its container, declared under
a name of its own with `$inputs->reads($port, $type)` and wired by
whatever attaches it, `attach($key, Child::class, reads: [$port =>
$key])`. A live sibling value its rules watch, never stored or drawn by
the structure.

Named in [Resources made of structures](introduction/11-resources-made-of-structures.md).
Reference: [Ports](resources.md#ports).

### Derived resource

A derived resource is one the module makes from what the site has, such
as one per content type, with no class of its own.

Precisely: a resource or structure whose keys are the site's, made at
discovery by a deriver from facts a class cannot know: a resource per
content entity bundle, a structure per field. It has no class; it is
`<base>:<derivative>`. Made by a class carrying `#[DerivesResources]`,
a `Resource\ResourceDeriverInterface` in `src/Resource/`, returning
`Resource\DerivedResource`.

Not in the introduction. Reference:
[Derived resources](resources.md#derived-resources),
[Content entities](entities.md).

### Outputs

Outputs are the values a call answers with, declared in the same way as
the values it accepts.

Precisely: the other half of the contract: what a host's execution
emits, in the same vocabulary and the same collection type as the
inputs, with an absent-rather-than-NULL rule. Never refined. Declared
with `defineOutputs()`, read with
`SurfaceInterface::getOutputDefinitions()`; a value not emitted is
`Pipeline\NotEmitted`.

Not in the introduction. Reference: [Outputs](outputs.md).

### Pipeline

The pipeline is the one path every value takes from a door to storage.

Precisely: access, accept, validate, prepare, commit: the one road from
raw input to storage, `Pipeline\SurfacePipelineInterface`. The
architecture is three layers that never reach into each other. A data
surface is pure data: it holds definitions and refiners, reaches no
service, and can therefore ride along in a cached form or be read by a
caller with no container. The pipeline is the only thing that needs
services, because running constraints needs the typed data manager. The
form layer is one more consumer of the pipeline, not a value path of its
own: the widgets collect a raw tree and `accept()` does the rest, which
is what keeps a generated form and a JSON payload from drifting apart.

Named in [A value sent in](introduction/17-a-value-sent-in.md).
Reference: [The pipeline](pipeline.md).

### Contract and payload

The contract is what a data surface says it accepts; a payload is the
values sent to it.

Precisely: one rule settles what is typed and what is not.

**What a data surface says is objects.** It is a `DefinitionMap` of
`Entry` value objects, one per key, each carrying its definition,
who introduced it, whether it is locked, what it refines against and
whose values it offers. What a run refused is a `ViolationSet` of
`Violation` objects. A resolved list of allowed values is an
`OptionSet`. These are read, never assembled by a caller, and the type
is what stops two parts of the module disagreeing about a shape.

**What flows through the pipeline is arrays.** The values that reach
`accept()`, `validate()`, `prepare()` and `commit()` are plain PHP
arrays, and they stay that way: a form submission, a JSON payload, a
config action and a stored settings array are all the same kind of
thing, and wrapping them would buy nothing and cost every caller a
conversion.

**Authoring syntax is arrays too.** A resource or structure class hands
core's definitions plain arrays (a constraint's options, a choice list
with its labels) and names what a refinement reads in the method's own
signature. Declaring one never means constructing an object graph by
hand.

Not in the introduction. Reference: [Outputs](outputs.md),
[Violations are objects](pipeline.md#violations-are-objects).

### Operation form

The operation form is the one generic form that serves any resource's
operation from a route, with no form class of its own.

Precisely: `Form\SurfaceOperationForm`, with
`Form\SurfaceFormCosmeticsInterface` for a form that needs a
borrowed element.

Not in the introduction. Reference:
[The operation form](forms.md#the-operation-form).

### Widget

A widget draws one key as a form element and reads the value back.

Precisely: maps one definition to a form element and back. A plugin
type, `Widget\SurfaceWidgetInterface`, its plugins in
`Plugin/SurfaceWidget/`.

Not in the introduction. Reference: [Widgets](widgets.md).

### Catalog

The catalog is the list of every resource and structure a site has,
with each operation's doors and what failed to build.

Precisely: every discovered resource and structure, with its kind, read
from the static layer without building anything; on a site, with each
operation's doors and every build refusal. The services are
`surface.resource_catalog` and `surface.site_catalog`; the
command is `drush surface:catalog`.

Not in the introduction. Reference:
[The site's catalog](catalog-command.md), [Catalog](catalog.md).

### Version

A resource's version counts the changes that broke the values already
stored for it.

Precisely: `#[Resource(version:)]` or `#[Structure(version:)]`, from 1,
counting the breaking changes made to the values stored for it, so a
compatible change never moves it. Stamped `x-surface.version` in both
documents, and refused above 1 until stored-value migrations are built.
Not the contract's own version, `x-surface.contract`, the version of
the wire format both documents are written in.

Not in the introduction. Reference:
[The version](evolution.md#the-version),
[The contract's own version](evolution.md#the-contracts-own-version).
