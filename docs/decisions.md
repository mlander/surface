# Decisions

[The pattern](pattern.md) says what a surface, an alter, a situation, a
subsurface, a target and an access class are. Building it meant
deciding points the pattern did not cover. Each is here, once, grouped
by topic: what was decided, in one or two sentences, and why. They are
written for the module's owner to accept or change; changing one is a
code change at the place its entry names.

A point that comes up undecided while working on the module is added
here, as an entry marked *open*, rather than as a comment in the code.

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

### An alter cannot refine its own mounted key

A `#[RefinesInput]` method names an owner's key; the key an alter
mounted is not one, so a method naming it is refused. Addressing
`third_party_settings.<module>.<key>` needs dotted refinement paths,
roadmap item 16. *Not from a code comment; recorded here because the
docs state it.*

### A storage shape for a mount

An alter whose keys are asked for in one shape and stored in another
implements `HasStorageShapeInterface`; its shape applies to its own
module's mount and nothing else. The alter does not own the target, so
it hands the translation to the surface, and the composed target
applies it. (`Surfaces`, `SurfaceTargetAdapter`)

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

## Discovery

### Whether a situation creates

The catalogue says whether a situation creates only for one that needs
nothing; one that needs a subject is listed as not knowing. `creates`
is on the context a situation returns, not on `#[Situation]`, and the
catalogue builds nothing. (`SurfaceCatalogue::creates()`)

### A class that is not there

An alter, situation or variant naming a surface class that does not
load, or that loads and carries `#[Surface]` but was not discovered (its
module is off), is skipped; one naming a class that loads and is no
surface is refused. A disabled module must not break the site, and a
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
would with `blockForm()`. (`SurfacePluginHooks::usedSurfaceOf()`)

## Plugins

### A plugin host's context

A plugin host builds the surface its plugin names in a bare context
whose operation is the host's own verb (`configure`, `field_settings`),
which is no declared situation. The pattern says the host "supplies the
situation" without naming one; a plugin's configuration has no add or
edit, only the instance the host holds. (`DataSurfaceHostTrait`)

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
