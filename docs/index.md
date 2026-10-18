# Data Surface

A resource is a thing with identity and storage that operations read and
write: a site's registration settings, a content type, a field, a basic
page. Its form, its tool
and its schema are derived from it, so a person, a route, a tool and an
agent ask it the same questions before anything is saved (what it will
accept right now, on this site, for this thing) and get the same answer.

| | Shape | Values |
| --- | --- | --- |
| **Owner**, a `#[Resource]` or `#[Structure]` class in `src/Resource/` or `src/Structure/`, or a plugin that is its own structure | `static defineInputs($inputs)`, optionally `static defineOutputs($outputs)` | static `#[RefinesInput('key')]` methods |
| **Another module**, an `#[Alters]` class in `src/Alter/` | `alterInputs($inputs)`, optionally `alterOutputs($outputs)` | `#[RefinesInput('key')]` methods |

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

A *structure* is a shape with members, and nothing more: no identity and
no storage of its own. It is only ever held by something else. It is
attached to a parent, or fills a slot as a variant, or is the
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
| **Resource class** | A thing with identity and storage, in one class: its identity keys, storage and access class on the attribute, its shape in static `defineInputs()` (and `defineOutputs()`), one static `#[RefinesInput]` method per rule that reads another key, and its operations. It may live in a config object, a config entity, State, a plugin's settings or a content entity; only its storage knows which. Never instantiated: no constructor, no service. A resource with no identity keys, such as a settings object, is a singleton resource. | `#[Resource(id, identity:, storage:, access:)]` on a class implementing `Resource\ResourceInterface`, in `src/Resource/` or `src/Structure/` |
| **Structure class** | A shape with members and no identity or storage, held by something else: a parent, a slot, or a plugin's host. Static shape and refiners, as a resource's; it may still declare operations for its container to choose between. | `#[Structure(id, access:)]` on a class implementing `Resource\StructureInterface`, in either directory; or a plugin that is its own structure, `#[UsesStructure]` with no argument |
| **Data surface** | What the build step seals from a resource or structure and a context: an immutable group of data definitions, the map of which key depends on which, and the refiners that narrow them. What the pipeline, the form, the widgets and the emitter read. | `DataSurfaceInterface`, built by `ResourceBuild\ResourcesInterface` (`data_surface.resources`) |
| **Definition map** | Everything a data surface advertises, as one ordered, validated collection: one entry per key, in declaration order. | `DefinitionMap` of `Entry` |
| **Definition** | One core `DataDefinitionInterface`: type, label, description, constraints, required, plus the interim default and example metadata. | core, plus `DefinitionMetadata` |
| **Refinement** | A narrower definition for one key, given the values of the siblings it watches. Checked narrower on every run. | `#[RefinesInput('key')]` methods |
| **Operation** | One way a resource is asked for (add, edit, reuse), returning a context that says what is already known. The context's id is the operation's. Routes and tools are generated from them. | `#[Operation]` static methods, `Resource\OperationContext` |
| **Alter** | Another module's class that adds keys to a resource or structure, rewords a label, offers more values on a fixed list, and narrows with its own refinements. Never removes. | `#[Alters]` on a class in `src/Alter/` |
| **Attached structure** | A child at a key, its own class with its own shape: `attach()` for a fixed child, `attachBy()` for a slot a sibling key chooses. A child may be a resource with storage of its own (a field's storage). | `Resource\BlueprintInterface`, `Attachment`, `Slot` |
| **Collection** | A list of child instances at a key, `attachList()`: the list and its Count the parent's, each item the child's. | `Resource\BlueprintInterface`, `Collection` |
| **Variant** | A child that fills a slot for one value of its deciding key. | `#[Variant(of:, key:, value:)]` |
| **Derived variants** | Variants for the values no variant class fills, read from a description that already exists, such as a field type's config schema. | `ResourceBuild\DerivedVariantsInterface`, tagged `data_surface.derived_variants` |
| **Derived resource** | A resource or structure whose shape is the site's, made at discovery by a deriver from facts a class cannot know: a resource per content entity bundle, a structure per field. It has no class; it is `<base>:<derivative>`. | `#[DerivesResources]` on a `Resource\ResourceDeriverInterface` in `src/Resource/`, returning `Resource\DerivedResource`; [Content entities](entities.md) |
| **Output definitions** | The other half of the contract: what a host's execution emits, in the same vocabulary and the same collection type as the inputs, with an absent-rather-than-NULL rule. Never refined. | `defineOutputs()`, `DataSurfaceInterface::getOutputDefinitions()`, `Pipeline\Omitted` |
| **Pipeline** | Access, accept, validate, prepare, commit: the one road from raw input to storage. | `Pipeline\DataSurfacePipelineInterface` |
| **Storage** | Where a resource's accepted values are written (a config object, a config entity, State, a plugin's settings, a content entity), and the translation between the resource's layout and storage's own. The one part of a resource that knows where it lives. | `#[Resource(storage:)]` naming a `Resource\ResourceStorageInterface` in `src/Storage/`; `Pipeline\DataSurfaceStorageInterface` |
| **Access class** | What may refuse once the operation's permission allows: the part of access that depends on the thing itself. At a child, whether its keys are open, locked or omitted. | `access:` on `#[Resource]` or `#[Structure]`, naming a `Resource\OperationAccessInterface` in `src/Access/` |
| **Host** | The plugin whose configuration a structure is: a block, a formatter, a condition, an action, a field type, any configurable plugin. It supplies the context and the storage, because only it holds the instance. | `#[UsesStructure]` on the plugin, naming a structure or, with no argument, being one; the `Plugin/*Base` classes, `Form\*` traits |
| **Operation form** | The generic form that serves a resource in one of its operations from a route, with no form class of its own. | `Form\DataSurfaceOperationForm`, `Form\DataSurfaceFormCosmeticsInterface` |
| **Widget** | Maps one definition to a form element and back. A plugin type. | `Widget\DataSurfaceWidgetInterface`, `Plugin/DataSurfaceWidget/*` |
| **Options resolver** | Reads one validation constraint as the list of values it allows, with labels and cacheability. A plugin type. | `Options\DataSurfaceOptionsResolverInterface`, `Plugin/DataSurfaceOptionsResolver/*` |
| **Catalogue** | Every discovered resource and structure, with its kind, read from the static layer without building anything. | `data_surface.resource_catalogue`, [catalogue.md](catalogue.md) |

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

- **Learning the pattern**: [The pattern](pattern.md), then [How it
  fits](how-it-fits.md); [Decisions](decisions.md) for the points it
  left open and how they were settled.
- **Writing a resource or structure**: [Declaring a
  resource](declaring-a-resource.md) for the class, its keys, defaults,
  locks and secrets; then [Resources and structures as
  classes](resources.md) for operations, alters, children, access,
  storage and the tools generated from them.
- **Adopting a structure on a plugin you own**: [Declaring a
  resource](declaring-a-resource.md#on-a-plugin), then [Generated
  forms](forms.md).
- **Understanding what a value will become**: [The
  pipeline](pipeline.md) for the stages, [Value
  semantics](semantics.md) for the rules each stage applies.
- **Saying what your host emits, not just what it takes**:
  [Outputs](outputs.md).
- **Extending somebody else's resource or structure**: [Refinement and
  contributions](refinement.md).
- **Writing a widget or an options resolver**: [Widgets](widgets.md),
  [Options and resolvers](options.md).
- **Storing the values somewhere new**: [Storage](storage.md).
- **Serving a resource to a client with no PHP**: [The served
  contract](served-contract.md).
- **Reading this as a core proposal**: [Adoption
  catalogue](adoption.md) and [Core gaps](core-gaps.md).

The module ships no resources of its own on a production site. It
provides the model, the pipeline, the two plugin types and the host
adoption layer; resources and structures come from the modules that
declare them. Ten experimental submodules demonstrate the model and
double as the fixtures the tests run against: see
[Installation](installation.md).
