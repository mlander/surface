# Data Surface

A data surface is what a resource accepts, settled in layers and then
sealed: the owner declares the keys and the rules between them, other
modules contribute under names of their own with rules that may narrow
the owner's keys but never widen them, declared rules and refiners
settle what applies ahead of time or per request, and access locks keys
or leaves them out for the account. Every door (the form, the tool, MCP,
ECA, HTTP and OpenAPI, the React page) reads that one sealed data
surface and nothing else, which is why they agree, and the alter
contract is why a module can change all of them at once without
touching any.

A resource is a thing with identity and storage that operations read and
write: a site's registration settings, a content type, a field, a basic
page. Its form, its tool
and its schema are derived from it, so a person, a route, a tool and an
agent ask it the same questions before anything is saved (what it will
accept right now, on this site, for this thing) and get the same answer.

| | Blueprint | Values |
| --- | --- | --- |
| **Owner**, a `#[Resource]` or `#[Structure]` class in `src/Resource/` or `src/Structure/`, or a plugin that is its own structure | `static defineInputs($inputs)`, optionally `static defineOutputs($outputs)` | rules declared with `$inputs->narrow('key')`, static `#[RefinesInput('key')]` methods |
| **Another module**, an `#[Alters]` class in `src/Alter/` | `alterInputs($inputs)`, optionally `alterOutputs($outputs)` | rules declared with `$inputs->narrow('key')`, `#[RefinesInput('key')]` methods |

**Start with [Your first resource](first-resource.md).** A settings page
needs four things: a resource class, an operation on it, a storage class
for its config object, and its keys declared with `add()`; plus the
route, the permission and the config schema any Drupal settings page
has. Nothing else on this page is needed for it. [Your second
resource](second-resource.md) adds one key that depends on another, and
[Concepts](concepts.md) lists every other idea with the question that
makes you need it.

To see them on a site, enable the examples module and visit
`/surface-examples` (its README is
`modules/data_surface_examples/README.md`). Examples 1 to 6 grow one
registration form, one idea at a time; examples 7 to 12 are a small
resource each, for a rule written as data, one part used twice, keys
that come from the site, access and how an operation behaves, the
stock widgets with what a key left out, null or a value means, and a
JSON Schema read into a structure: a component's props.

[The pattern](pattern.md) is the whole of what an author writes, and
why; [How it fits](how-it-fits.md) follows one request through the
build step. The rest of this page is the module around it.

## Resources and structures

A *resource* is a thing with identity and storage that operations read
and write. Its identity is the keys that say which one it is: a content
type's machine name, a field's entity type, bundle and name. Its storage
is where its values live, and a resource may live anywhere: in a config
object, a config entity, State, a plugin's settings or a content entity.
Only its storage knows which; nothing else about the resource changes
with it. Its operations are the ways it is asked for (add, edit, reuse),
each saying how much of its identity is already known. The form, the
tool and the schema are not written beside it; they are derived from it.

The examples' registration settings are the plainest case: one config
object, `RegistrationStep1Resource` with `RegistrationStep1Storage`, and
one operation, `configure`. It names no identity keys, because there is
only one of it: a resource with no identifiers, such as a settings
object, is a *singleton* resource. A content type is a resource with an
identity key, `type`, stored as a config entity, and asked for through
`add` and `edit`. A basic page is the same shape applied to content: the
derived resource `entity.content:node.page`, whose identity is the
entity type, the bundle and the id, and whose storage is the content
entity.

A *structure* is a group of keys, and nothing more: no identity and
no storage of its own. It is only ever held by something else. It is
attached to a parent, or is one of the variants at a key, or is the
configuration of a plugin, whose host supplies the operation and the
storage. A block's settings, a field type's settings and a contact's
details are structures.

Both describe a group of values the same way: what each value is
called, what it means, what type it is, what it may contain, what it
defaults to, and which of its values depend on which others. Drupal core
already has a vocabulary for all of that in its typed data definitions.
What core has never had is a place to keep a group of them, hand that
group to one caller after another, and have every caller agree about
what was accepted.

This module is that place. A resource or structure is built once, in a
context, into a *data surface*: sealed, and then only read. A generated
form renders it, a pipeline accepts and validates values against it, and
storage writes the accepted values where they live. The same data
surface answers a form submit, a Drush command, a config action, an
agent call and a test, without any of them owning a second copy of the
rules.

When a value depends on another (a bundle list that only makes sense
once an entity type is chosen), a refiner narrows the definition, and
the generated form rebuilds that part of itself over AJAX rather than
the host re-implementing the dependency. The rule the module holds
itself to is that refinement may only *narrow*: whatever a data surface
advertises when it is sealed stays true of everything it accepts
afterwards, so a caller that read it once, or a machine-readable
contract emitted from it, is never contradicted later.

That narrowing rule is the property that makes a data surface worth
having over a form array, and it is the property the module exists to
prove out for [the core issue behind
it](https://www.drupal.org/project/drupal/issues/3622144).

## The pieces, and where they live

| Concept | What it is | In the code |
| --- | --- | --- |
| **Resource class** | A thing with identity and storage, in one class: its identity keys, storage and access class on the attribute, its keys in static `defineInputs()` (and `defineOutputs()`), one static `#[RefinesInput]` method per rule that reads another key, and its operations. It may live in a config object, a config entity, State, a plugin's settings or a content entity; only its storage knows which. Never instantiated: no constructor, no service. A resource with no identity keys, such as a settings object, is a singleton resource. | `#[Resource(id, identity:, storage:, access:)]` on a class implementing `Resource\ResourceInterface`, in `src/Resource/` or `src/Structure/` |
| **Structure class** | A group of keys with no identity or storage, held by something else: a parent (attached, or as a variant), or a plugin's host. Static `defineInputs()` and refiners, as a resource's; it may still declare operations for its container to choose between. | `#[Structure(id, access:)]` on a class implementing `Resource\StructureInterface`, in either directory; or a plugin that is its own structure, `#[UsesStructure]` with no argument |
| **Data surface** | What the build step seals from a resource or structure and a context: an immutable group of data definitions, the map of which key depends on which, and the refiners that narrow them. What the pipeline, the form, the widgets and the emitter read. | `DataSurfaceInterface`, built by `ResourceBuild\ResourcesInterface` (`data_surface.resources`) |
| **Definition map** | Everything a data surface advertises, as one ordered, validated collection: one entry per key, in declaration order. | `DefinitionMap` of `Entry` |
| **Definition** | One core `DataDefinitionInterface`: type, label, description, constraints, required, plus the interim default and example metadata. | core, plus `DefinitionMetadata` |
| **Refinement** | A narrower definition for one key, given the values of the siblings it watches. Checked narrower on every run. | rules declared with `narrow('key')`, `#[RefinesInput('key')]` methods |
| **Operation** | One way a resource is asked for (add, edit, reuse), returning a context that says what is already known. The context's id is the operation's. Routes and tools are generated from them. | `#[Operation]` static methods, `Resource\OperationContext` |
| **Alter** | Another module's class that adds keys to a resource or structure, rewords a label, offers more values on a fixed list, and narrows with its own refinements. Never removes. | `#[Alters]` on a class in `src/Alter/` |
| **Attached structure** | A child at a key, its own class with its own keys: `attach()` for a fixed child, `attachVariants()` for variants a sibling key chooses between. A child may be a resource with storage of its own (a field's storage). | `Resource\BlueprintInterface`, `Attachment`, `Variants` |
| **Collection** | A list of child instances at a key, `attachList()`: the list and its Count the parent's, each item the child's. | `Resource\BlueprintInterface`, `Collection` |
| **Variants** | A tagged union whose tag is a sibling key: structures attached at one key, of which the sibling's value (the deciding key) chooses one. Always open: the parent names no variant, and any module may declare one. Until the deciding key holds a value, the key is an `any` placeholder; once it does, the key is the chosen variant's map. | `attachVariants($key, by: $sibling)`, `Variants` |
| **Variant** | One structure among the variants at a key, for one value of its deciding key. | `#[Variant(of:, at:, for:)]` |
| **Derived variants** | Variants for the values no variant class fills, read from a description that already exists, such as a field type's config schema. | `ResourceBuild\DerivedVariantsInterface`, tagged `data_surface.derived_variants` |
| **Derived resource** | A resource or structure whose keys are the site's, made at discovery by a deriver from facts a class cannot know: a resource per content entity bundle, a structure per field. It has no class; it is `<base>:<derivative>`. | `#[DerivesResources]` on a `Resource\ResourceDeriverInterface` in `src/Resource/`, returning `Resource\DerivedResource`; [Content entities](entities.md) |
| **Output definitions** | The other half of the contract: what a host's execution emits, in the same vocabulary and the same collection type as the inputs, with an absent-rather-than-NULL rule. Never refined. | `defineOutputs()`, `DataSurfaceInterface::getOutputDefinitions()`, `Pipeline\NotEmitted` |
| **Pipeline** | Access, accept, validate, prepare, commit: the one road from raw input to storage. | `Pipeline\DataSurfacePipelineInterface` |
| **Storage** | Where a resource's accepted values are written (a config object, a config entity, State, a plugin's settings, a content entity), and the translation between the resource's layout and storage's own. The one part of a resource that knows where it lives. | `#[Resource(storage:)]` naming a `Resource\ResourceStorageInterface` in `src/Storage/`; `Pipeline\DataSurfaceStorageInterface` |
| **Access class** | What may refuse once the operation's permission allows: the part of access that depends on the thing itself. At a child, whether its keys are open, locked or omitted. | `access:` on `#[Resource]` or `#[Structure]`, naming a `Resource\OperationAccessInterface` in `src/Access/` |
| **Host** | The plugin whose configuration a structure is: a block, a formatter, a condition, an action, a field type, any configurable plugin. It supplies the context and the storage, because only it holds the instance. | `#[UsesStructure]` on the plugin, naming a structure or, with no argument, being one; the `Plugin/*Base` classes, `Form\*` traits |
| **Operation form** | The generic form that serves a resource in one of its operations from a route, with no form class of its own. | `Form\DataSurfaceOperationForm`, `Form\DataSurfaceFormCosmeticsInterface` |
| **Widget** | Maps one definition to a form element and back. A plugin type. | `Widget\DataSurfaceWidgetInterface`, `Plugin/DataSurfaceWidget/*` |
| **Options resolver** | Reads one validation constraint as the list of values it allows, with labels and cacheability. A plugin type. | `Options\DataSurfaceOptionsResolverInterface`, `Plugin/DataSurfaceOptionsResolver/*` |
| **Catalog** | Every discovered resource and structure, with its kind, read from the static layer without building anything. | `data_surface.resource_catalog`, [catalog.md](catalog.md) |

Read the architecture as three layers that never reach into each other.
A data surface is pure data: it holds definitions and refiners, reaches
no service, and can therefore ride along in a cached form or be read by
a caller with no container. The pipeline is the only thing that needs
services, because running constraints needs the typed data manager. The
form layer is one more consumer of the pipeline, not a value path of its
own: the widgets collect a raw tree and `accept()` does the rest, which
is what keeps a generated form and a JSON payload from drifting apart.

## Contracts are objects, payloads are arrays

One rule settles what is typed and what is not.

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

## Where to start

The pages fall in the same groups as the README's documentation table.

- **Start here**: [Your first resource](first-resource.md), [Your
  second resource](second-resource.md), [Concepts](concepts.md),
  [Common settings recipes](recipes.md), and [the
  examples](../modules/data_surface_examples/README.md) at
  `/surface-examples`.
- **Writing a resource**: [Declaring a resource](declaring-a-resource.md)
  (and [on a plugin](declaring-a-resource.md#on-a-plugin)), [Resources
  and structures](resources.md), which opens with [Which way to
  compose](resources.md#which-way-to-compose), then
  [Refinement](refinement.md), [Reading a schema](reading-a-schema.md),
  [Options](options.md),
  [Storage](storage.md), [Outputs](outputs.md), [Widgets](widgets.md),
  [Generated forms](forms.md) and [Content entities](entities.md).
- **The doors**: [The served contract](served-contract.md), [The
  pipeline](pipeline.md), [Value semantics](semantics.md), [the Tool API
  bridge](../modules/data_surface_tool/README.md), [the React
  app](../modules/data_surface_react/README.md), [the Tool
  integration](installation.md#tool) for an agent, MCP and Drush, and
  [One resource, every door](every-door/index.html).
- **The engine and the record**: [The pattern](pattern.md), [How it
  fits](how-it-fits.md), [Decisions](decisions.md) (the reasons behind
  each rule, for a reader who wants the why; nothing on it is needed to
  build), the [Catalog](catalog.md), [Evolving a
  resource](evolution.md) and [Installation](installation.md).
- **Evidence**: the logs and the audit [below](#evidence).
- **The proposal**: [Core gaps](core-gaps.md), then the [Adoption
  catalog](adoption.md).

The module ships no resources of its own on a production site. It
provides the model, the pipeline, the two plugin types and the host
adoption layer; resources and structures come from the modules that
declare them. Fourteen experimental submodules demonstrate the model and
double as the fixtures the tests run against: see
[Installation](installation.md).

## Evidence

What the module was held to: by people and agents who did not write it,
each a log of what they met, kept as written, and by an audit of the
promise its two schema documents make:

- [Fresh eyes, 2026-10-18](fresh-eyes-2026-10-18.md): a newcomer builds
  a newsletter settings page from the documentation alone.
- [Fresh eyes, 2026-10-19](fresh-eyes-2026-10-19.md): a second newcomer
  builds a library's opening hours, a reused structure and an alter.
- [Fresh eyes three, 2026-10-19](fresh-eyes-2026-10-19b.md): a third
  newcomer builds a newsletter digest from the documentation alone.
- [Two adopters, 2026-10-19](adopters-2026-10-19.md): two contributed
  modules' settings forms, pathauto's and honeypot's, ported as
  resources.
- [Audit: never stricter than the runtime](audit-never-stricter.md):
  where the schema could be stricter than the pipeline, case by case,
  each verdict pinned by a test.
