# Surface pattern sketch

A surface is a form you can ask questions of before anything is saved:
what it will accept right now, on this site, for this thing, and the
same answer for a person, a route, a tool or an agent.

A sketch of the pattern only. Interfaces and one example. Nothing here is
wired: no validation, no storage, no forms, no schema output, no discovery.
The signatures are placeholders for the idea, not proposals.

Start with `modules/field/src/Surface/FieldInstanceSurface.php`.

## Where things live

A module has four predictable directories. Two are what it owns, two are
where it reaches into something it does not own.

| | Owns | Reaches into |
| --- | --- | --- |
| **Surfaces** | `src/Surface/` | `src/SurfaceAlter/` |
| **Lists of allowed values** | `src/Options/` | `src/OptionsAlter/` |

Options are not under `Surface/` because a list of countries is not a
surface concern. A form, a validator or a schema can use the same list.

Two more kinds of class are named by a surface rather than discovered,
so their directory is a suggestion, not a rule: targets (`src/Target/`)
and run-time access (`src/Access/`).

```
api/                               what core would provide
  Surface/                         the two interfaces, their tools, context
  Surface/Attribute/               #[Surface], #[SurfaceVariant]
  Options/                         options list, its alter, the OptionsList constraint

modules/field/src/
  Surface/FieldInstanceSurface.php     the generic field surface
  Surface/FieldStorageSurface.php
  Options/FieldableEntityTypeOptions.php
  Target/FieldInstanceTarget.php       where its values go
  Target/FieldStorageTarget.php
  Access/FieldInstanceAccess.php       the part of access that needs the subject

modules/demo/src/
  Plugin/Block/TeaserBlock.php         a plugin: #[UsesSurface], and only build()
  Surface/TeaserBlockSurface.php       its configuration, as a surface
  Options/ContentEntityTypeOptions.php
  Options/BundleOptions.php            takes the entity type as an argument

modules/address/src/
  Surface/AddressFieldSettingsSurface.php   fills the field's settings slot
  Options/CountryOptions.php

modules/link/src/
  Surface/LinkFieldSettingsSurface.php      fills the field's settings slot

modules/editorial/src/
  SurfaceAlter/RequiredFieldsNeedHelpTextAlter.php   every field

modules/field_clone/src/
  SurfaceAlter/FieldCloneSituations.php   a situation for a surface it does not own

modules/shipping/src/
  SurfaceAlter/AddressCountryPolicyAlter.php         address fields only
  OptionsAlter/ExtraCountriesOptionsAlter.php        the country list, everywhere
```

The precedent is `src/Hook`: core scans it, and every class in it is found
and autowired. The same would apply to all four directories, so nothing
is registered by hand.

## The pattern

A surface is a class with one method that declares its shape, and one
named method per key whose allowed values depend on another key. An
alter is a class with the same two jobs and a narrower tool.

| | Shape | Values |
| --- | --- | --- |
| **Owner** | `defineInputs($inputs)`, optionally `defineOutputs($outputs)` | `#[RefinesInput('key')]` methods |
| **Another module** | `alterInputs($inputs)`, optionally `alterOutputs($outputs)` | `#[RefinesInput('key')]` methods |

- The shape methods get a shape to fill, and nothing else: no values,
  no context. The owner gets `ShapeInterface`; an alter gets
  `ShapeAdditionsInterface`, which is the same minus `attachBy()`. An
  alter can add keys, attach a child, reword a label or description
  with `describe()`, and offer more values on a fixed choice list with
  `extendChoices()`. It cannot remove a key or change its type. Inputs
  and outputs use the same two interfaces.
- A `#[RefinesInput('bundle')]` method takes the key's definition first,
  then one parameter per sibling it watches, matched by name to that
  sibling's key, or listed on the attribute as `watches:` when you
  would rather a renamed parameter fail at seal than silently stop
  watching. Its signature is its dependency declaration, the way a
  situation's is. A key refined by two inputs is a method with two
  sibling parameters; `TeaserBlockSurface::fieldsOfBundle()` watches
  the entity type and the bundle. It runs once every watched sibling
  has a value, and again when any of them changes. Sealing refuses a
  watched key the shape never declared. It returns
  the definition tightened with plain core API, and the framework checks
  the result is narrower, so a refinement cannot widen, retype, or add
  or remove a map property. It receives only what it named, so it is a
  pure function of its arguments.

## Reading a surface

- **`defineInputs()` is a flat list.** No `if`, no loop. Wanting one
  means a second surface, a subsurface, or a situation.
- **Name, type and label sit on one line.** `add()` takes those three and
  returns the core definition, so the rest is plain core API.
- **It is ordered like the thing it describes.** Identity first, the
  thing's own keys next, its parts last.
- **`defineInputs()` never mentions a sibling.** A constraint written
  there is fully known with no values. Anything that reads another key's
  value is a `#[RefinesInput]` method.
- **One refiner, one rule, one name.** `bundleOfEntityType()`,
  `defaultAmongAvailable()`, `onlyShippable()`. The docblock states the
  rule; the signature states what it reads.
- **Everything named by class.** Children, variants, targets, access,
  option lists, the surface an alter or situation belongs to: always
  `X::class`, never an instance or a string, so it is one click away.

## Subsurfaces

A surface attaches other surfaces at a key. Each child is its own class,
with its own shape and refiners, and alters can target it alone.

- `attach()` is a fixed child. The field attaches its storage.
- `attachBy()` is a slot: a child that a sibling key chooses. The
  field's `settings` are chosen by `field_type`.
- `attachList()` and `attachListBy()` are collections: a list whose
  every item is the child, or chooses its own child. **Deferred** to the
  next concept; declared so the position is recorded. The contract is
  an ordered list with delta as position, stable identity is an
  identity key on the child, and weights are the target's business.

A slot is always **open**. The parent never names the children. Each
child marks itself with `#[SurfaceVariant]`, naming the slot and the
value it is for, so a new field type brings its settings surface with it
and the field module never changes. Underneath, a slot is an `any` stub
that the framework narrows to the chosen child's map once the deciding
key has a value: an ordinary refinement, not a new mechanism. A child
that cannot be enumerated statically is the one case for a
`#[RefinesInput]` method on an `any` key returning the narrower
definition itself.

## Situations: add, reuse, edit

A surface never mentions add or edit. The difference between them is not
shape. It is how much is already known.

- `#[Surface(identity: [...])]` names the **identity keys**: the input
  keys that say which thing this is. An identity key is settable until
  the context knows it, and locked from then on. It is on the attribute
  rather than in `defineInputs()` because identity is the address: what
  a discovery document lists, what a tool or route must supply. Sealing
  refuses an identity key the shape never declared.
- The ways a surface is asked for are **static methods on the surface**:
  `FieldInstanceSurface::add()`, `::reuse()`, `::edit()`. Each returns
  a context and knows more than the last. They sit beside the identity
  keys because they are the same concern: which identity is known.
- Each carries `#[Situation]`: an id, a label, and the static part of
  its access. What it needs is its own signature. Whether it creates is
  on the context it returns, where the target reads it.
- **Another module can add one.** `#[Situation(of: FieldInstanceSurface::class)]`
  on a static method of any class in its `src/SurfaceAlter/` is found
  the way a hook method is, no interface needed. `FieldCloneSituations`
  adds `clone`, building on the owner's `reuse` and giving the context
  starting values from the source field.
  That makes situations listable (a discovery document), addressable
  (`field.instance:edit`), and generatable (one tool per situation, its
  inputs being the parameters plus the surface's open keys).

| Keys | `add()` | `reuse()` | `edit()` |
| --- | --- | --- | --- |
| Entity type, bundle | locked | locked | locked |
| Field type, machine name | open | locked | locked |
| Label, help text, required | open | open | open, current values loaded |
| Storage subsurface | its own add | its own edit | its own edit |
| Settings subsurface | chosen by field type | fixed by the known type | fixed by the known type |

A situation can hand a subsurface its own context. Reusing a storage is
an add for the field and an edit for its storage. A situation can also
narrow: the storage situation, when the field has data, constrains
cardinality not to shrink. The rule: what depends on where you are is
the situation's; what depends on what was entered is a refiner's.

A generic tool uses no situation. It passes an empty context, every
identity key stays open, and it is still the same surface.

## Allowed values

A key says what it allows on its own definition, as a constraint. The
surface has no verb for options.

| The list is | Constraint | Example |
| --- | --- | --- |
| Fixed | core `Choice` | `LinkFieldSettingsSurface` |
| Live, from an options list | `OptionsList` naming a class in `src/Options/` | `AddressFieldSettingsSurface` |
| Already modelled by core | the core constraint | `EntityBundleExists` on `bundle`, attached by a refiner because it needs the entity type |

An options list may hold services. A surface never does, which is what lets
surfaces stay plain objects with no constructor.

A list can be altered in two places:

| To change | Put a class in | Example |
| --- | --- | --- |
| The list everywhere it is used | `src/OptionsAlter/` | `ExtraCountriesOptionsAlter` |
| One key on one surface, tightening it | `src/SurfaceAlter/` | `AddressCountryPolicyAlter` |

Widening one key on one surface is `extendChoices()`, for fixed choice
lists only; it is the one widening verb. Removing is deliberately
absent: a site that must hide an owner's key by policy is a later
concept, recorded beside options alters.

## Surface alters

An alter says which surface it applies to with `#[AltersSurface]` on
its class, optionally naming the situations it applies in. Static, like
the variant and situation attributes, so a catalogue can say who alters
a surface without running anything.

- `RequiredFieldsNeedHelpTextAlter` names `FieldInstanceSurface`, so it
  applies to every field.
- `AddressCountryPolicyAlter` names `AddressFieldSettingsSurface`, so it
  applies to one field type.

## The gaps, closed or deferred

These are what the sketch had not covered and `data_surface` does.

- **Targets.** `#[Surface(target:)]` names a `SurfaceTargetInterface`
  with `load()`, `prepare()` and `commit()`. `prepare()` rehearses the
  write with every check storage would make and no side effects; a dry
  run stops there, and a real submit runs it before `commit()`. It loads by the identity the context
  knows, so the context never carries an entity. Targets compose along
  the tree: the storage subsurface has its own, the settings subsurface
  has none and is stored by its parent under its key.
- **Access.** Two tiers. The static tier is the permission on each
  `#[Situation]`, answerable with no subject. The run-time tier is
  `#[Surface(access:)]`, a `SurfaceAccessInterface` that may hold
  services. Alters never touch access.
- **Input shapes.** Deliberately not in the sketch. The widget alter
  layer from the variants branch (another way to enter the same value,
  converted to one canonical) is a separate concept, and it waits until
  subsurfaces have landed so that one idea arrives at a time.
- **Outputs.** Their own method, `defineOutputs()`, on an optional
  `HasOutputsInterface`, taking the same tool as inputs minus the one
  input-only verb, so `defineInputs()` reads as "what this asks for"
  and most surfaces never mention outputs. Outputs are never refined: a
  refinement narrows what may be sent, and nobody sends an output. An
  output whose shape depends on an input value is a variant, declared
  with `attachBy()` on that input. The field surface answers with the
  created field's id.
- **Plugins.** The plugin keeps rendering; its configuration moves to a
  surface in `src/Surface/`, named by `#[UsesSurface]` on the plugin.
  The host supplies the situation and the target, since only it holds
  the instance. The attribute is in the plugin definition, so tools can
  list surfaced plugins without instantiating any. One more file per
  plugin, in return for the same reading everywhere.

And one rule that fell out of the plugin example: **a refiner never
calls a service.** A list that depends on a value is an `OptionsList`
constraint with the value as an argument. The refiner points; the list
fetches.

## Open questions before wiring, with a recommendation on each

1. ~~Should an alter declare its target?~~ Settled: `#[AltersSurface]`
   on the class, with optional `situations`. `applies()` is gone. An
   alter that targets by interface rather than class is the one thing
   lost, and no example needed it.
2. **A child depends on its parent's class.** `#[SurfaceVariant]` names
   `FieldInstanceSurface::class`, so the address module references the
   field module. That direction is right, but it is a hard dependency.
   *Recommendation: keep the class reference.* It is the same dependency
   the address module already has on the field module through the
   field type plugin, and a class name is what makes the slot one click
   away. Variants of a surface in a module you do not depend on are not
   a case worth designing for.
3. **Create-only keys that are not identity.** Rare. A situation can
   constrain a key but has no verb to lock one that is not identity.
   *Recommendation: no new verb.* `withConstraint()` with a one-value
   `Choice` is a lock in every way that matters: the form renders it
   fixed, the pipeline refuses anything else, the schema says one
   value. Add `locking()` only if a real surface needs a lock that a
   constraint cannot express, and none has yet.
4. **May an options alter remove entries?** Adding is safe. Removing an
   option that stored values already use needs a rule.
   *Recommendation: yes, with the stale rule the module already has.* A
   options alter may remove. A stored value that is no longer offered
   renders as "previous value no longer available", is kept on an
   untouched save, and is refused as a new value. That is the branch's
   stash model, and it is the same answer for a deleted bundle, so one
   rule covers both.
5. **Parent and child.** Can a parent refine a child's key, or a child
   read a parent's value? The sketch says no: a child sees only context.
   *Recommendation: keep the wall, and give it one door.* A child never
   reads a parent's values. When a child genuinely needs one, the parent
   hands it over as identity in the child's context, which the child's
   situation declares. That is already how the storage child learns its
   entity type and field name, and it keeps every child buildable and
   testable on its own.
6. **The build step is prose.** `api/HOW-IT-FITS.md` describes it;
   nothing runs it. *Recommendation: make it the first unit of the
   rework, not part of the sketch.* Written against the module's
   existing builder and factory, it is the facade the migration needs
   anyway, and the field example becomes its first test. Writing it
   twice, once standalone and once for real, buys nothing.

## Against today's module

| Sketch | `data_surface` today |
| --- | --- |
| A class in `src/Surface/` | static `declareDataSurface()` on the host, or a provider service |
| `#[RefinesInput('key')]` methods, dependencies in the signature | `refineDataDefinition($name, $definition, $values)` with a `match`, dependencies declared separately with `addRefinement()` |
| `attach()`, `attachBy()` | `mount()`, `mountVariants()` |
| `#[SurfaceVariant]` on the child | a resolver service, or a table the parent holds |
| `#[Surface(identity:)]` and `FieldInstanceSurface::edit()` | the builder's `lock()`, called by each provider per operation |
| A class in `src/SurfaceAlter/` with `#[AltersSurface]` | a build event subscriber holding the full builder, choosing at run time |
| `OptionsList` naming a class in `src/Options/` | one options resolver per constraint, found by constraint name |
| A class in `src/OptionsAlter/` | nothing; a list can only be changed per surface |
| `#[Surface(target:, access:)]` | the provider triple: `getDataSurface()`, `surfaceAccess()`, `getDataSurfaceTarget()` |
| `defineOutputs()`, never refined | `setOutputDefinition()` plus output refiners |
| `#[UsesSurface]` on the plugin | `declareDataSurface()` on the plugin class itself |
