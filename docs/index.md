# Data Surface

A *surface* is the description of a group of values a piece of software
accepts: what each value is called, what it means, what type it is, what
it may contain, what it defaults to, and which of its values depend on
which others. Drupal core already has a vocabulary for all of that in its
typed data definitions. What core has never had is a place to keep a
group of them, hand that group to one caller after another, and have
every caller agree about what was accepted.

This module is that place. A surface is built once, sealed, and then only
read. A generated form renders it, a pipeline accepts and validates
values against it, and a target writes the accepted values to config, to
a config entity, to base field overrides, or to state. The same surface
answers a form submit, a Drush command, a config action, an agent call
and a test, without any of them owning a second copy of the rules.

When a value depends on another — a bundle list that only makes sense
once an entity type is chosen — a refiner narrows the definition, and the
generated form rebuilds that part of itself over AJAX rather than the
host re-implementing the dependency. The rule the module holds itself to
is that refinement may only *narrow*: whatever a surface advertises when
it is sealed stays true of everything it accepts afterwards, so a caller
that read the surface once, or a machine-readable contract emitted from
it, is never contradicted later.

That narrowing rule is the property that makes a surface worth having
over a form array, and it is the property the module exists to prove out
for [the core issue behind
it](https://www.drupal.org/project/drupal/issues/3622144).

## The pieces, and where they live

| Concept | What it is | In the code |
| --- | --- | --- |
| **Surface** | An immutable group of data definitions, the map of which key depends on which, and the refiners that narrow them. | `DataSurfaceInterface`, built by `DataSurfaceBuilderInterface` and sealed by `DataSurfaceFactoryInterface` |
| **Definition map** | Everything a surface advertises, as one ordered, validated collection: one entry per key, in declaration order. | `DefinitionMap` of `SurfaceEntry` |
| **Definition** | One core `DataDefinitionInterface`: type, label, description, constraints, required, plus the interim default and example metadata. | core, plus `DefinitionMetadata` |
| **Output definitions** | The other half of the contract: what a host's execution emits, in the same vocabulary and the same collection type as the inputs, with an absent-rather-than-NULL rule. | `DataSurfaceInterface::getOutputDefinitions()`, `Pipeline\Omitted` |
| **Pipeline** | Accept, validate, prepare, commit — the one road from raw input to storage. | `Pipeline\DataSurfacePipelineInterface` |
| **Target** | Where accepted values are written, and the translation between surface shape and storage shape. | `Pipeline\DataSurfaceTargetInterface`, `Target\*` |
| **Widget** | Maps one definition to a form element and back. A plugin type. | `Widget\DataSurfaceWidgetInterface`, `Plugin/DataSurfaceWidget/*` |
| **Options resolver** | Reads one validation constraint as the list of values it allows, with labels and cacheability. A plugin type. | `Options\DataSurfaceOptionsResolverInterface`, `Plugin/DataSurfaceOptionsResolver/*` |
| **Refiner** | Returns a narrower definition for one key, given what its dependencies hold. | `DataSurfaceRefinerInterface`, and `DataSurfaceFilterInterface` for policy filters |
| **Host** | The thing whose values a surface describes: a block, a formatter, a condition, an action, a field type, a standalone form. | `Form\*` traits and the `Plugin/*Base` classes |
| **Provider** | A class that answers with a surface, and with the target it writes to, for a given operation. | `DataSurfaceProviderInterface`, `Form\FieldSurfaceProviderInterface` |
| **Declaration** | The one home for what a class's surface holds: a static method handed a builder, readable without an instance, which is what the static host defaults protocols need. | `DataSurfaceDeclarationInterface::declareDataSurface()` |

Read the architecture as three layers that never reach into each other.
A surface is pure data: it holds definitions and refiners, reaches no
service, and can therefore ride along in a cached form or be read by a
caller with no container. The pipeline is the only thing that needs
services, because running constraints needs the typed data manager. The
form layer is one more consumer of the pipeline, not a value path of its
own — the widgets collect a raw tree and `accept()` does the rest, which
is what keeps a generated form and a JSON payload from drifting apart.

## Contracts are objects, payloads are arrays

One rule settles what is typed and what is not.

**What a surface says is objects.** The surface is a `DefinitionMap` of
`SurfaceEntry` value objects, one per key, each carrying its definition,
who introduced it, whether it is locked, what it refines against and
whose values it offers. What a run refused is a `ViolationSet` of
`SurfaceViolation` objects. A resolved list of allowed values is an
`OptionSet`. These are read, never assembled by a caller, and the type
is what stops two parts of the module disagreeing about a shape.

**What flows through the pipeline is arrays.** The values that reach
`accept()`, `validate()`, `prepare()` and `commit()` are plain PHP
arrays, and they stay that way: a form submission, a JSON payload, a
config action and a stored settings array are all the same kind of
thing, and wrapping them would buy nothing and cost every caller a
conversion.

**Authoring syntax is arrays too.** A declaration hands the builder
plain arrays — a constraint's options, a choice list with its labels, the
keys a refinement edge names. `DefinitionMap::fromArrays()` is the
boundary where they become the collection, so declaring a surface never
means constructing an object graph by hand.

## Where to start

- **Adopting a surface on a plugin you own**: [Declaring a
  surface](declaring-a-surface.md), then [Generated forms](forms.md).
- **Understanding what a value will become**: [The
  pipeline](pipeline.md) for the stages, [Value
  semantics](semantics.md) for the rules each stage applies.
- **Saying what your host emits, not just what it takes**:
  [Outputs](outputs.md).
- **Extending somebody else's surface**: [Refinement and
  contributions](refinement.md).
- **Writing a widget or an options resolver**: [Widgets](widgets.md),
  [Options and resolvers](options.md).
- **Storing the values somewhere new**: [Targets](targets.md).
- **Reading this as a core proposal**: [Adoption
  catalogue](adoption.md) and [Core gaps](core-gaps.md).

The module ships no surfaces of its own on a production site. It provides
the surface model, the pipeline, the two plugin types and the host
adoption layer; surfaces come from the modules that declare them. Five
experimental submodules demonstrate the model and double as the fixtures
the tests run against — see [Installation](installation.md).
