# Decisions

[The pattern](pattern.md) says what a surface, an alter, a situation, a
subsurface, a target and an access class are. Building it meant
deciding points the pattern did not cover. Each is here, once, grouped
by topic: what was decided, in one or two sentences, and why. They are
written for the module's owner to accept or change; changing one is a
code change at the place its entry names.

A point that comes up undecided while working on the module is added
here, as an entry marked *open*, rather than as a comment in the code.

## Authoring

### A surface is static

`SurfaceInterface::defineInputs()` and `HasOutputsInterface::defineOutputs()`
are `public static`, and the build step calls them on the class, never
on an instance; a surface's `#[RefinesInput]` methods are static too,
and one that is not is refused when the surface is built. Shape is a
property of the class: it takes no values, no context and no services,
and static enforces that by the language rather than by a docblock. It
also lets a plugin class be its own surface without anything
constructing the plugin to ask (see [A plugin that is its own
surface](#a-plugin-that-is-its-own-surface)), and it keeps an object
out of every cached form: the surface's link in the refiner chain holds
the class name, where an instance carrying `StringTranslationTrait` was
measured at about 721 bytes more per cached form. `RefinesInputRefiner`
dispatches a static and an instance method alike, so **a surface's
refiner is static; an alter's is an instance method**: an alter is an
autowired service and may hold configuration its refiner reads, a
site's shipping zones, say. PHP itself refuses a class that implements
the interface with an instance `defineInputs()`, so discovery names the
one that dropped the interface to get one past the compiler.
(`Surfaces::buildSurface()`, `Surfaces::assertStaticOnSurface()`,
`SurfaceRegistry::assertSurfaceClass()`)

Translatable strings in static context use the global `t()`: a
surface's shape, refiners and situations, a plugin's static protocol
methods, an enum's labels, a value object's static helper.
`t('Label')` returns the same lazy `TranslatableMarkup` that
`new TranslatableMarkup('Label')` does, and string extraction finds
it; the coding standards' reason to prefer `$this->t()` is injection,
which does not apply where nothing can be injected, and
`DrupalPractice` does not flag `t()` in a static method.
`Drupal.Semantics.FunctionT` holds it to literal strings, so a value is
always a placeholder. `new TranslatableMarkup` stays only inside
attribute arguments (`#[Situation(label:)]`, plugin attributes), where
a function call is not allowed; a class the container builds keeps
injecting `string_translation` and calling `$this->t()`.

## Context

### A build is not cached

Every `Surfaces::build()` builds afresh; the pattern's "cache by
(surface id, context)" is not done. A surface describes live site
state, and a cache keyed by context alone would serve a stale one; what
that state is, a shape says with `addCacheableDependency()`, for a
caller that caches. *Not from a code comment; the build step's
docblock states it.*

### Child context

A child sees the context its parent's hands it with `withChild()`;
otherwise it sees the parent's operation, `creates` and known identity,
and not the parent's constraints, starting values or child contexts.
Those three name the parent's keys, so handing them down would apply
them to keys the child does not have. (`Surfaces::childContext()`)

### Operation is the situation id

A situation whose context carries an operation other than its own id is
refused when the situation is asked. The pattern says the operation *is*
the situation id; enforcing it is what lets alters (`situations:`),
permissions and catalogues trust it. A situation built on another calls
`withOperation()`. (`Surfaces::situation()`)

### Constraints and starting values for a subsurface key

A context constraint or starting value naming a subsurface key is
refused, and the message points at `withChild()`. A child is narrowed
and started by its own context, which is the one door through the wall
between parent and child. (`Surfaces::applyContext()`)

### Starting values without creating

A context that carries starting values but does not create is refused
rather than ignored. A thing that exists begins from what its target
loads; a silently dropped starting value would be a bug nobody sees.
(`Surfaces::applyContext()`)

### Starting values are defaults

A starting value becomes the key's declared default for that build
only. That is exactly what a starting value is to the engine: what the
caller sees first and may replace. (`Surfaces::applyContext()`)

### Known keys that are not identity

A known key that is not one of `#[Surface(identity:)]` is not locked;
it stays on the context for the target and the access class to read.
`FieldStorageSurface::edit()` knows the storage's entity type and field
name this way, which its target loads by, while `field_type`, its one
identity key, is locked. (Before storage settings existed, the field
type was known the same way; it became identity when it started
choosing the settings slot.)

### An entity parameter given as an id

A route parameter or tool input for a situation parameter typed as an
entity interface is loaded by the one entity type whose class satisfies
that type; a type several entity types satisfy is taken only as an
object. A guess between entity types would load the wrong thing.
(`SituationArguments`)

## Refinement

### Gating per key

The engine runs a key's refiners once every sibling any of them watches
has a value, so a key refined by two methods watching different siblings
waits for the union. The pattern runs each method once its own siblings
have values; gating per key is what the engine's refinement edges, the
form's AJAX rebuild and the discard cascade already ride, and no surface
has needed finer. Per-method gating is listed in `ROADMAP.md`, after
the rework, for when a case needs it. (`Surfaces::bindRefiners()`)

### A refiner that watches nothing

It runs once, at build, after the context is applied; its result is
held to the narrowing check and is what the surface advertises. The
pattern says it "runs once" without saying when; at build is the only
time that gives every caller the same answer. (`Surfaces::bindRefiners()`)

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
into every surface the build step makes and serialized by its service
id; a surface sealed without one, which only the engine's tests build,
hands every configured value over. A refiner may therefore assume what
it is handed satisfies its sibling's constraints, and
`RegistrationComplianceAlter` tests only that a licence is there.
(`DataSurface::refine()`, `DataSurface::refusedWatchedValues()`,
`Surfaces::buildSurface()`)

### Alters' refiners in the owner's chain

An alter's `#[RefinesInput]` method on an owner's key runs in the
owner's chain, after the owner's own methods. On a key the alter offered
more values on with `extendChoices()`, it is that contribution's
refiner instead, handed the alter's values only. The engine keeps one
chain per contribution, so an alter can tighten the owner's values
without ever being able to take another module's away.
(`Surfaces::bindRefiners()`)

### Cacheability on the shape

`ShapeInterface::addCacheableDependency()` lets the owner's shape say
what site state it read; the sealed surface carries it. An alter cannot,
because what it adds reaches no further than the owner's keys, and a
refiner cannot, because it calls no service. (`SurfaceShape`)

## Alters

### Where an alter's keys live

An alter's key is mounted at `third_party_settings.<module>.<key>`
(outputs: `third_party_outputs.<module>.<key>`), not beside the
owner's. The owner's storage and schema then never have to know a
contributor's keys, which is how core keeps third-party settings.
(`SurfaceShapeAdditions`)

### An alter cannot attach

`attach()` on an alter is refused. An alter's keys are mounted under
its module, and a subsurface inside that mount is not built yet.
(`SurfaceShapeAdditions::attach()`)

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
such a method was refused.* (`Surfaces::bindRefiners()`,
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
(`Surfaces::assertNotWatchingForeignMount()`, `Surfaces::mountedWatches()`,
`DataSurface::dependencyValues()`, `DataSurfaceBuilder::edgesOf()`)

### A storage shape for a mount

An alter whose keys are asked for in one shape and stored in another
implements `HasStorageShapeInterface`; its shape applies to its own
module's mount and nothing else. The alter does not own the target, so
it hands the translation to the surface, and the composed target
applies it. (`Surfaces`, `SurfaceTargetAdapter`)

### Required inside a plain map

A required property inside a plain map (a key that is neither a
subsurface nor a slot, such as the mount alters' keys live in) that
holds no value is refused with the surface's own "is required"
message, filed at its path, whether or not the map itself holds
anything; typed data's NotNull for the same path is dropped so it is
said once. No child judges a plain map's properties, and NotNull alone
passes an empty string and never sees a map that is absent, so a
mounted key an alter made required would otherwise never be refused.
(`DataSurfacePipeline::missingInMap()`)

### Placement is a describe option

Where an alter's fieldset is drawn is said once, by the alter, on its
own mount: `describe('third_party_settings.<module>', after: '<key>')`,
naming a top-level key of the owner's shape. It is recorded on the
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
shows. (`ShapeAdapterBase::assertPlaceable()`,
`DataSurfaceFormBuilder::placeMounts()`)

### Adding a key twice

Declaring a key that is already declared is refused, for owner and
alter alike, and `add()` with a NULL default means "no default", not a
declared NULL. A second `add()` silently replacing the first would be
an alter changing the owner's key. (`ShapeAdapterBase::add()`)

## Subsurfaces

### A slot nothing fills

A slot with no variant stays a placeholder and its deciding key gains
an empty `Choice`, so nothing can be chosen. Refusing the whole surface
would break it on every site without a variant module.
(`Surfaces::slotsOf()`)

### Derived variants

A value no `#[SurfaceVariant]` class fills may get a variant derived
from a description that already exists, through a
`DerivedVariantsInterface` service: a field type's config schema fills
the field's settings slot and the storage's. A derived
variant is a shape with no class, so no alter, refiner, target or access
class of its own, and a declared variant always wins.
(`Surfaces::buildSurface()`)

### A parent watching a subsurface

A parent's refiner that watches a whole subsurface key is refused, as
is one that refines it. The pattern says a parent cannot refine into a
child and a child cannot read a parent's value; refusing the watch too
makes the wall hold both ways. (`Surfaces::assertWalled()`)

### Outputs hold no subsurfaces

`attach()` and `attachBy()` on outputs are refused. The pattern lets an
output vary by an input that way, but the engine's output map has no
subsurfaces yet. (`ShapeAdapterBase`)

## Targets

### A child learns its parent's accepted identity

A routed child is prepared and committed in its own context plus the
identity values its parent accepted that it does not already know. A
creating parent's identity exists only once accepted, and a child target
loads by identity. (`SurfaceTargetAdapter`)

### Write order

An attached child is committed before its parent, a slot's variant
after. An attached child is a part the parent is built on (a field's
storage); a variant lives on what the parent writes (a field's
settings). (`SurfaceTargetAdapter::commit()`)

### Who produces outputs

A surface's outputs are what its target's `load()` hands back under an
output's name, once the values are committed. The target already knows
how to read the thing it wrote; a second verb would say the same twice.
(`SurfaceTargetAdapter::outputs()`)

## Access

### Children may refuse

A child the context resolves (an attached child, or a slot variant
whose deciding key is locked) is asked through its own access class in
its own context, and may refuse but never allow. A field type's settings
can then refuse the field they belong to, and nothing below a surface
can open what its own access closed. (`Surfaces::access()`)

### No declared situation, no permission tier

A context whose operation is no declared situation (a plugin host's
`configure`) has no permission tier: the access class alone answers, or
neutral when there is none. The host already checked the permission
that guards its own form. (`Surfaces::ownAccess()`)

### An unknown placeholder

A `%key` in a situation's permission that the context does not know
makes access forbidden. The permission cannot be named, and guessing
one would grant or refuse the wrong thing. (`Surfaces::ownAccess()`)

### Neutral is a refusal for a situation's caller

A route or tool addressing a situation reads a neutral answer as a
refusal (`DataSurfaceAccess::decisive()`). The situation owns its
operation, so nothing else is going to allow it. (`SurfaceSituationTool`,
`SituationAccessCheck`)

## Tools

### Identity before the parameters

A tool's static definition reads which identity keys a situation knows
off its signature: a scalar parameter supplies its own name, an entity
parameter every identity key no scalar names. The tool then refines
`values` to the real context once the parameters arrive. What a
situation knows is only on the context it returns, and a static
definition cannot call it. (`SituationInputs::inputs()`)

### A parameter named for a key

A scalar situation parameter named for one of the surface's plain keys
is described as that key: its label, meaning and allowed values. It is
that key's value, so it says what the key says.
(`SituationInputs::parameterInput()`)

### A plugin's surface is no tool

A surface any plugin names with `#[UsesSurface]` gets no tool,
whatever it declares. Only the plugin's host holds the instance it
configures. (`SurfaceSituationToolDeriver`, `SurfaceCatalogue::standalone()`)

### A permission nothing can name is no tool

A situation whose permission has a `%key` none of its parameters can
supply is no tool: it could never be allowed. `field.storage`'s `add`
is only ever a field's child. (`SurfaceSituationToolDeriver`,
`SurfaceCatalogue::standalone()`)

### A violation summary is plain text

A derived tool's refusal names each violation with its message rendered
as plain text before the line is handed on as a placeholder value. The
line is escaped there, so a message's own placeholder markup (Range's
`<em class="placeholder">`) would otherwise reach the caller, Drush
included, as literal tags. (`SurfaceResultReportingTrait::violationSummary()`)

### Required is the surface's, and defaults are what is stored

A derived tool's `values` property is required exactly when the surface,
built for the situation, requires the key, whatever its default and
whether or not the situation creates; built in a real context that
changes a thing that exists, each property defaults to what the target
loads now (a secret to nothing). `drush tool:info` has to say what the
surface requires and what is there. The cost is the Tool API's: it
refuses a required map property a payload leaves out, default or not
(`TypedInputsTrait::validateInputValue()`), so an edit sends every
required key. A situation that needs a subject keeps declared defaults
until its parameters refine it. A static definition is cached, so its
stored defaults are as of the last discovery; a write through the tool
clears it, a write by any other path shows at the next cache rebuild.
(`SituationInputs::values()`, `SurfaceInputDefinitions::withStored()`,
`SurfaceSituationTool::doExecute()`)

## Forms

### A panel inside the situation form

A route may name a read-only panel in `_data_surface_panel`, a service
id or class implementing `DataSurfaceFormPanelInterface`, the way it
names a cosmetic layer. The situation form places what it returns
inside the surface container, and the AJAX rebuild replaces it along
with the dependents of whatever changed, because a panel describing the
surface as it stands has to follow every change; it is handed the values the elements are built with,
not a refined surface, so it can tell which variant a slot shows. The
contract panel lives in `data_surface_tool`, since half of it is the
derived tool's JSON Schema. (`DataSurfaceSituationForm::surfacePanel()`)

### An error on the trigger renders inline

A refinement request validates one value, the trigger's, and what is
wrong with it is said under the trigger, once. A violation the surface
reports on the trigger is not made a Form API error on that request:
Form API skips the rebuild once anything has errored, and the
dependents would come back refined against the answer before this one.
`flagSurfaceErrors()` holds it in temporary form state instead, the
rebuild goes ahead (refining as though the refused value were absent,
by [the rule above](#a-refiner-never-sees-an-invalid-sibling)), and
`refreshSurface()` gives the trigger the error: `#errors`,
`aria-invalid` and the `error` class, printed in the form element
template's own error slot by the module's preprocess, which is where
Inline Form Errors would print it, so the two never print it twice.
The response then replaces the trigger's own wrapper — the one
exception to never redrawing the element that was touched — and the
trigger's next request says it showed an error (`INVALID_INPUT`), so
it is replaced once more and an error the person fixed is taken away.
An error Form API's own element validation sets on the trigger, a
maximum length, does stop the rebuild; it is printed the same way and
taken back out of the messages at the top, so it is not said twice
either. The surface's message wins when both have one. On a full
submission nothing changes: core prints every error at the top and
marks the elements. (`DataSurfaceFormBuilder::flagSurfaceErrors()`,
`DataSurfaceFormBuilder::refreshSurface()`, `Hook\InlineErrorHooks`)

### A Regex is explained by its message

A pattern is for machines. Wherever the module explains what a key
allows — the contract panel's "Allows" column, the React app's twin of
it — a Regex is said in its own `message`, the sentence a refusal is
reported in, and the pattern stays in the machine schema (`pattern`,
with the message beside it as `x-surface.patternMessage`). Only a Regex
with no message falls back to "matches a required format" and the
pattern in code. Neither the string widget nor the React text input
puts the pattern on the input: an HTML `pattern` makes the browser ask
for "the requested format", and Form API's `#pattern` says
"is not in the right format", both vaguer than the message the surface
answers with. (`SurfaceContractPanel::regex()`,
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
   shown — the surface author chose the default.
3. On first entry a required select comes up on the empty option, and
   a save leaving it there is refused with the key's own required
   message ("@label is required."), set as the element's
   `#required_error` so core's check, which answers first, says it in
   the surface's words. A required select standing for a stale value is
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

### Whether a situation creates

The catalogue says whether a situation creates only for one that needs
nothing; one that needs a subject is listed as not knowing. `creates`
is on the context a situation returns, not on `#[Situation]`, and the
catalogue builds nothing. (`SurfaceCatalogue::creates()`)

### A class that is not there

An alter, situation or variant naming a surface class that does not
load, or that loads and is a surface but was not discovered (its module
is off) — it carries `#[Surface]`, or it is a plugin that is its own
surface — is skipped; one naming a class that loads and is no surface
is refused. A disabled module must not break the site, and a
typo must not pass silently. (`SurfaceRegistry`)

### `#[UsesSurface]` into the plugin definition

Core's discovery reads only a plugin type's own attribute, so one
definition alter per plugin host copies `#[UsesSurface]` into the
definition under `UsesSurface::DEFINITION_KEY`, run last so a swapped
class is the one read. That is what lets a catalogue list surfaced
plugins without instantiating any. (`SurfacePluginHooks`)

### `#[UsesSurface]` is inherited

A subclass inherits its parent's `#[UsesSurface]`; the nearest class
naming one wins. A subclassed block keeps its parent's form, as it
would with `blockForm()`. A subclass of a plugin that is its own
surface is its own surface in turn, inheriting the static shape, so an
alter of the parent's class does not reach it.
(`SurfacePluginHooks::usedSurfaceOf()`)

### A plugin that is its own surface

`#[UsesSurface]` with no argument means the plugin class is its own
surface: it implements `SurfaceInterface`, with a static
`defineInputs()` and static `#[RefinesInput]` methods, and its
definition records its own class under `UsesSurface::DEFINITION_KEY`,
so every host builds it exactly as it builds a named surface. Such a
surface is found through the plugin definitions
(`SurfacePlugins::ownSurfaces()`), not a directory: a plugin lives
where its plugin type says. Its id is `<host type>:<plugin id>`, the
catalogue's name for the plugin, unless the class also carries
`#[Surface]`, whose id, identity, target and access then apply; a class
several plugins share (a deriver's derivatives) is one surface, named
after the first of them in sorted order. The catalogue lists it as used
by that same plugin, and an alter targets it by the plugin class. The
registry's cache id hashes the plugin-own list beside the compiler
pass's, so a plugin more or less is a different entry. The compiler
pass never sees such a class, so a target or access class it names is
not registered as an autowired service; the class resolver builds it,
which means no constructor arguments or `ContainerInjectionInterface`.
The demo formatter is written this way and the demo block is not, so
the two spellings sit side by side.
(`SurfaceRegistry::discover()`, `SurfacePluginHooks::usedSurfaceOf()`)

## Plugins

### A plugin host's context

A plugin host builds the surface its plugin names in a bare context
whose operation is the host's own verb (`configure`, `field_settings`),
which is no declared situation. The pattern says the host "supplies the
situation" without naming one; a plugin's configuration has no add or
edit, only the instance the host holds. (`DataSurfaceHostTrait`)

### A plugin host cannot overlay a full submission

*Open.* A plugin host — a block, or any plugin using
`DataSurfaceHostFormTrait` — answers NULL from `surfaceSubmissionPath()`,
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
protocols do not look. (`SurfacesInterface::defaults()`)

### The demo's bundle-field constraint

`DemoBlockSurface` points at a bundle's fields with a constraint of the
demo's own, `DataSurfaceDemoBundleField`, and its options resolver.
The pattern fetches such a list through an options source, which is
not built yet, and no core constraint names a bundle's fields.
(`DemoBlockSurface::fieldOfBundle()`)

## Examples

### What the examples are made of

Each example of `data_surface_examples` keeps its settings in a config
object of its own and names a target subclass of `RegistrationTarget`
that says which; an example's surface repeats the previous example's
keys rather than extending its class, so each example reads alone on
screen. The machine names keep the word step (`registration_step2`,
`RegistrationStep2Surface`, the `data_surface_examples.step2` route):
they were never shown to anybody, and renaming them would churn the
catalogue and every test for no reader's benefit. A
ticket price is a `float` with a `Range` minimum of 0.01: core's typed
data has no decimal type and no `Positive` constraint plugin. The event
title is stored as a `string`, not a translatable `label`, because a
`label` would make the classic twin's `#config_target` demand a
language code the surface side never needs. (`RegistrationStep3Surface`,
`PaidTicketSurface`, `data_surface_examples.schema.yml`)

### The examples' README is held, not generated

The README quotes each example's class from its first attribute down, and
`ExamplesReadmeTest` fails when a quoted block and its file differ, as
`ExamplesToolTest` fails when the three tool answers it quotes change.
Generating it would need a kernel for the answers and a generator for
prose written for a viewer; a drift test keeps the prose free and the
code true.

## Served contract

### The emitter is the main module's, and widgets are asked for

The served contract's emitter reads any sealed surface and nothing else,
so it is the main module's (`data_surface.contract_emitter`), the
canonical contract the situation form's contract panel shows and
`/surface-api` serves. The roadmap rejects widget vocabulary in the
contract, so `x-surface.widget`, the Form API mapping said for a
renderer, and `multiple`, its qualifier, are written only when a caller
passes `widgets: TRUE`; `data_surface_react` does, for its app. The rest
of `x-surface` (locked, dependsOn, refined, stale, emptyOption, a slot's
by, variants and chosen, a variant's id, checkedOnServer, enumerated,
dynamic) is the
surface's own reading of a key, not a widget, and is always written.
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

### Enumeration is capped

Where a key's refiners have nothing to refine against yet, the emitter
states what they would answer for every combination of their watched
siblings' values, as `if`/`then` conditionals, when every such sibling
offers a list of values and there are at most 64 combinations per key
(`ContractEmitter::ENUMERATION_CAP`). The cap is per key, over the
product of the lists, and bounds two costs at once: the document, which
a person reads in the contract panel and a client downloads on every
GET, and the emission, which refines the whole surface once per
combination. 64 holds every finite domain the shipped surfaces have
(the largest is the demo block's field, entity types times bundles) with
room for a real site's bundles, and is small enough that a
country-sized list (some 250) is refused outright rather than written.
Above it the key is `dynamic`. A key watching free text, a number, a
boolean or a key an alter mounted stays `dynamic` whatever the count:
its domain is not a list, so there is nothing to enumerate, and
inventing one (sampling numbers, guessing at a licence) would state a
rule the surface never made. Such a key keeps `dependsOn`, and a client
asks `/refine` when a watched key changes, as before.
(`ContractEmitter::enumerate()`)

### A served contract is never stored

The contract response carries the surface's, the option lists' and the
access answer's cacheability as HTTP cache metadata, and max-age 0: its
values are what a target loaded, and a target does not say how long that
holds, so a cached contract would serve values from before a save.
(`SurfaceApiController::respond()`)

### Stale paths travel, stored values do not

A stale value is served as `null`, its path listed under `stale`; a
client sends the paths back, and an empty answer at one of them stands
for the stored value again. It is the situation form's stale marker,
said in JSON, and it keeps a stored value off the wire whether or not a
person may still choose it. (`ServedSituations::keepStale()`)

### Required means must hold a value

A key the surface requires is in the schema's `required`, whatever its
default: the surface's meaning, which a form's required marker shows.
The Tool API bridge copies the same flag unchanged (see "Required is
the surface's, and defaults are what is stored"); the served contract's
`values` always carry every key, so presence is never the question
there. (`ContractEmitter::frame()`)

### A refused submit is an answer

`POST .../submit` answers a refusal with 200 and `committed: false`,
the violations as data, never 422 or 400: a value the pipeline refuses
is the expected outcome of asking, exactly as `/validate` reports it and
as the situation form shows it beside the field, and a client renders
both the same way from the same keys. A status code is kept for what is
not an answer about the values: 403 when access refuses the request
before it runs, 400 when the body cannot be read. Access refused inside
the pipeline (a forbidden answer the route did not already give) is
still filed as the `@access` violation, the pipeline's own rule.
(`SurfaceApiController::submit()`)

### A fingerprint is opt-in

The contract carries a fingerprint of the stored values it was built
from, and a submit that sends it back is refused, nothing written, when
storage no longer matches. A submit that sends none is not checked, and
the last write wins, which is what the situation form, the tool and
every PHP caller of the pipeline do. Opt-in, because making it required
would refuse every client that never asked for a contract first (a
script, an agent calling the tool's twin) and would put a rule on the
API the form does not have. The React app opts in; a page can turn it
off with `sendFingerprint: false`. A check, not a lock: it narrows the
window to the moment between the comparison and the commit.
(`ServedSituations::fingerprint()`)
