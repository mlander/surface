# Data Surface

A surface is a form you can ask questions of before anything is saved:
what it will accept right now, on this site, for this thing, and the
same answer for a person, a route, a tool or an agent.

| | Shape | Values |
| --- | --- | --- |
| **Owner**, a `#[Surface]` class in `src/Surface/` | `defineInputs($inputs)`, optionally `defineOutputs($outputs)` | `#[RefinesInput('key')]` methods |
| **Another module**, an `#[AltersSurface]` class in `src/SurfaceAlter/` | `alterInputs($inputs)`, optionally `alterOutputs($outputs)` | `#[RefinesInput('key')]` methods |

[The pattern](pattern.md) is the whole of what an author writes, and
why; [How it fits](how-it-fits.md) follows one request through the
build step. The rest of this page is the module around it.

## What a surface is

A *surface* is the description of a group of values a piece of software
accepts: what each value is called, what it means, what type it is, what
it may contain, what it defaults to, and which of its values depend on
which others. Drupal core already has a vocabulary for all of that in its
typed data definitions. What core has never had is a place to keep a
group of them, hand that group to one caller after another, and have
every caller agree about what was accepted.

This module is that place. A surface is built once, sealed, and then only
read. A generated form renders it, a pipeline accepts and validates
values against it, and a target writes the accepted values where they
live: a config entity, a field's settings, base field overrides, a
plugin's configuration, or state. The same surface
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
| **Surface class** | The one home for what a surface holds: its shape in `defineInputs()` (and `defineOutputs()`), and one `#[RefinesInput]` method per rule that reads another key. No constructor, no service. | `#[Surface]` on a class in `src/Surface/`, implementing `Surface\SurfaceInterface` |
| **Surface** | What the build step seals from a surface class and a context: an immutable group of data definitions, the map of which key depends on which, and the refiners that narrow them. | `DataSurfaceInterface`, built by `SurfaceBuild\SurfacesInterface` (`data_surface.surfaces`) |
| **Definition map** | Everything a surface advertises, as one ordered, validated collection: one entry per key, in declaration order. | `DefinitionMap` of `SurfaceEntry` |
| **Definition** | One core `DataDefinitionInterface`: type, label, description, constraints, required, plus the interim default and example metadata. | core, plus `DefinitionMetadata` |
| **Refinement** | A narrower definition for one key, given the values of the siblings it watches. Checked narrower on every run. | `#[RefinesInput('key')]` methods |
| **Situation** | One way a surface is asked for — add, edit, reuse — returning a context that says what is already known. Routes and tools are generated from them. | `#[Situation]` static methods, `Surface\SurfaceContext` |
| **Alter** | Another module's class that adds keys to a surface, rewords a label, offers more values on a fixed list, and narrows with its own refinements. Never removes. | `#[AltersSurface]` on a class in `src/SurfaceAlter/` |
| **Subsurface** | A child surface at a key: `attach()` for a fixed child, `attachBy()` for a slot a sibling key chooses. | `Surface\ShapeInterface` |
| **Variant** | A child that fills a slot for one value of its deciding key. | `#[SurfaceVariant(of:, key:, value:)]` |
| **Derived variants** | Variants for the values no variant class fills, read from a description that already exists, such as a field type's config schema. | `SurfaceBuild\DerivedVariantsInterface`, tagged `data_surface.derived_variants` |
| **Output definitions** | The other half of the contract: what a host's execution emits, in the same vocabulary and the same collection type as the inputs, with an absent-rather-than-NULL rule. Never refined. | `defineOutputs()`, `DataSurfaceInterface::getOutputDefinitions()`, `Pipeline\Omitted` |
| **Pipeline** | Access, accept, validate, prepare, commit — the one road from raw input to storage. | `Pipeline\DataSurfacePipelineInterface` |
| **Target** | Where accepted values are written, and the translation between surface shape and storage shape. | `#[Surface(target:)]` naming a `Surface\SurfaceTargetInterface`; `Pipeline\DataSurfaceTargetInterface`, `Target\*` |
| **Access class** | What may refuse once the situation's permission allows: the part of access that depends on the thing itself. | `#[Surface(access:)]` naming a `Surface\SurfaceAccessInterface` |
| **Host** | The plugin whose configuration a surface is: a block, a formatter, a condition, an action, a field type, any configurable plugin. It supplies the context and the target, because only it holds the instance. | `#[UsesSurface]` on the plugin, the `Plugin/*Base` classes, `Form\*` traits |
| **Situation form** | The generic form that serves a surface in one of its situations from a route, with no form class of its own. | `Form\DataSurfaceSituationForm`, `Form\DataSurfaceFormCosmeticsInterface` |
| **Widget** | Maps one definition to a form element and back. A plugin type. | `Widget\DataSurfaceWidgetInterface`, `Plugin/DataSurfaceWidget/*` |
| **Options resolver** | Reads one validation constraint as the list of values it allows, with labels and cacheability. A plugin type. | `Options\DataSurfaceOptionsResolverInterface`, `Plugin/DataSurfaceOptionsResolver/*` |
| **Catalogue** | Every discovered surface, read from the static layer without building anything. | `data_surface.surface_catalogue`, [catalogue.md](catalogue.md) |

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

**Authoring syntax is arrays too.** A surface class hands core's
definitions plain arrays — a constraint's options, a choice list with
its labels — and names what a refinement reads in the method's own
signature. Declaring a surface never means constructing an object graph
by hand.

## Where to start

- **Learning the pattern**: [The pattern](pattern.md), then [How it
  fits](how-it-fits.md); [Decisions](decisions.md) for the points it
  left open and how they were settled.
- **Writing a surface**: [Declaring a surface](declaring-a-surface.md)
  for the class, its keys, defaults, locks and secrets; then [Surfaces
  as classes](surfaces.md) for situations, alters, subsurfaces, access,
  targets and the tools generated from them.
- **Adopting a surface on a plugin you own**: [Declaring a
  surface](declaring-a-surface.md#on-a-plugin), then [Generated
  forms](forms.md).
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
adoption layer; surfaces come from the modules that declare them. Nine
experimental submodules demonstrate the model and double as the fixtures
the tests run against — see [Installation](installation.md).
