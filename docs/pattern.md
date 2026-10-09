# The surface pattern

A surface is a form you can ask questions of before anything is saved:
what it will accept right now, on this site, for this thing, and the
same answer for a person, a route, a tool or an agent.

This page is the pattern: what an author writes, where it goes, and why
it is shaped that way. [Surfaces as classes](surfaces.md) is the
reference for each part, [How it fits](how-it-fits.md) walks one build
from caller to sealed surface, and [Decisions](decisions.md) lists the
points the pattern left open and how the module settled them.

The worked example throughout is the field: `FieldInstanceSurface` in
`data_surface_tool`, with its storage, `FieldStorageSurface`, and the
address module's `AddressFieldSettingsSurface` filling its settings.

## Where things live

A module has two predictable directories: one for what it owns, one for
where it reaches into something it does not own.

| | Owns | Reaches into |
| --- | --- | --- |
| **Surfaces** | `src/Surface/` | `src/SurfaceAlter/` |

Both are scanned in every enabled module, the way core scans
`src/Hook`, so nothing is registered by hand. A surface is never
instantiated: its shape and its refiners are static methods, asked of
the class. An alter is registered as an autowired service and may hold
services. A plugin may also be its own surface, with the same static
methods on the plugin class; it is found through its plugin definition
rather than a directory ([Plugins](#plugins)).

Two more kinds of class are named by a surface rather than discovered,
so their directory is a suggestion, not a rule: targets (`src/Target/`)
and run-time access (`src/Access/`). Both are registered as autowired
services when the surface naming them is found.

```
src/Surface/                          the authoring API, in this module
src/Surface/Attribute/                #[Surface], #[Situation], #[RefinesInput],
                                      #[AltersSurface], #[SurfaceVariant], #[UsesSurface]

modules/data_surface_tool/src/
  Surface/FieldInstanceSurface.php    the generic field surface
  Surface/FieldStorageSurface.php     its storage, attached at `storage`
  Target/FieldInstanceTarget.php      where its values go
  Target/FieldStorageTarget.php
  Access/FieldInstanceAccess.php      the part of access that needs the subject
  FieldSettingsSchemaVariants.php     settings for field types with no surface

modules/data_surface_demo/src/
  Plugin/Block/DataSurfaceDemoBlock.php   a plugin: #[UsesSurface], and build()
  Surface/DemoBlockSurface.php            its configuration, as a surface
  Surface/ListPresentationSurface.php     fill the block's presentation slot
  Surface/GridPresentationSurface.php
  Plugin/Field/FieldFormatter/DataSurfaceDemoFormatter.php
                                          a plugin that is its own surface

modules/data_surface_address/src/
  Surface/AddressFieldSettingsSurface.php fills the field's settings slot

modules/data_surface_demo_node_type/src/
  Surface/NodeTypeSurface.php         a content type, with add and edit

modules/data_surface_demo_extras/src/
  SurfaceAlter/DemoBlockAlter.php     adds a key, rewords one, narrows one
  SurfaceAlter/DemoFormatterAlter.php offers one more value on a fixed list
  SurfaceAlter/NodeTypeAlter.php      adds keys stored in another shape
```

## The pattern

A surface is a class with one static method that declares its shape,
and one named static method per key whose allowed values depend on
another key. An alter is a class with the same two jobs and a narrower
tool; it is a service, so its methods are instance methods.

| | Shape | Values |
| --- | --- | --- |
| **Owner** | `static defineInputs($inputs)`, optionally `static defineOutputs($outputs)` | static `#[RefinesInput('key')]` methods |
| **Another module** | `alterInputs($inputs)`, optionally `alterOutputs($outputs)` | `#[RefinesInput('key')]` methods |

- **The shape methods get a shape to fill, and nothing else**: no
  values, no context, no services. On a surface they are static, so the
  language holds them to that: the build step calls them on the class
  and never makes an instance. The owner gets `ShapeInterface`; an alter gets
  `ShapeAdditionsInterface`, which is the same minus `attachBy()`. An
  alter can add keys, reword a label or description with `describe()`,
  and offer more values on a fixed choice list with `extendChoices()`.
  It cannot remove a key or change its type. Inputs and outputs use the
  same two interfaces.
- **A `#[RefinesInput('bundle')]` method takes the key's definition
  first, then one parameter per sibling it watches**, matched by name
  to that sibling's key, or listed on the attribute as `watches:` when
  a renamed parameter should fail at build rather than silently stop
  watching. Its signature is its dependency declaration, the way a
  situation's is. `DemoBlockSurface::fieldOfBundle()` watches the entity
  type and the bundle. It runs once every watched sibling has a value
  (a key its alter mounted is never waited for), and again when any of
  them changes. It returns the definition
  tightened with plain core API, and the framework checks the result is
  narrower, so a refinement cannot widen, retype, or add or remove a
  map property. It receives only what it named, so it is a pure
  function of its arguments. **A surface's refiner is static; an
  alter's is an instance method**, because an alter is a service that
  may hold configuration.
- **Labels are `t('...')`.** A static method has no instance for the
  translation service to be injected into, and the global `t()` returns
  the same lazy `TranslatableMarkup`, which string extraction finds.
  `new TranslatableMarkup` is kept for attribute arguments, where a
  function call is not allowed; an alter, a service, calls `$this->t()`.

```php
#[Surface('field.instance',
  identity: ['entity_type_id', 'bundle', 'field_name', 'field_type'],
  target: FieldInstanceTarget::class,
  access: FieldInstanceAccess::class,
)]
final class FieldInstanceSurface implements SurfaceInterface {

  public static function defineInputs(ShapeInterface $inputs): void {
    // Which field this is.
    $inputs->add('entity_type_id', 'string', t('Entity type'))->setRequired(TRUE);
    $inputs->add('bundle', 'string', t('Bundle'))->setRequired(TRUE);
    $inputs->add('field_type', 'string', t('Field type'))->setRequired(TRUE);
    $inputs->add('field_name', 'string', t('Machine name'))->setRequired(TRUE);
    // The field itself.
    $inputs->add('label', 'string', t('Label'))->setRequired(TRUE);
    $inputs->add('description', 'string', t('Help text'));
    $inputs->add('required', 'boolean', t('Required field'), default: FALSE);
    // Its parts.
    $inputs->attach('storage', FieldStorageSurface::class)
      ->setLabel(t('Field storage'));
    $inputs->attachBy('settings', by: 'field_type')
      ->setLabel(t('Field settings'));
  }

  /**
   * The bundle must belong to the chosen entity type.
   */
  #[RefinesInput('bundle')]
  public static function bundleOfEntityType(DataDefinitionInterface $bundle, string $entity_type_id): DataDefinitionInterface {
    return $bundle->addConstraint('EntityBundleExists', ['entityTypeId' => $entity_type_id]);
  }

}
```

(The real class adds descriptions and the machine name's own `Regex`
and `Length`, and three situations, below.)

## Reading a surface

- **Everything on it is static.** `defineInputs()`, `defineOutputs()`,
  every `#[RefinesInput]` method and every `#[Situation]`. Shape is a
  property of the class: nothing it says takes a value, a context or a
  service, and a static method cannot reach for one. No constructor, no
  properties, no `$this`.
- **`defineInputs()` is a flat list.** No `if`, no loop. Wanting one
  means a second surface, a subsurface, or a situation.
- **Name, type and label sit on one line.** `add()` takes those three
  and returns the core definition, so the rest is plain core API.
  `addDefinition()` is the long form, for a list or a map. The label is
  `t('...')`, a literal string.
- **It is ordered like the thing it describes.** Identity first, the
  thing's own keys next, its parts last.
- **`defineInputs()` never mentions a sibling.** A constraint written
  there is fully known with no values. Anything that reads another
  key's value is a `#[RefinesInput]` method.
- **One refiner, one rule, one name.** `bundleOfEntityType()`,
  `fieldOfBundle()`, `shortInGrid()`. The docblock states the rule; the
  signature states what it reads.
- **A refiner trusts what it is handed.** A sibling value its own key
  refuses is never passed in: the engine treats it as unanswered
  ([decision](decisions.md#a-refiner-never-sees-an-invalid-sibling)).
  So a refiner asks whether a licence is there, not whether it is well
  formed.
- **A Regex carries a message.** The pattern cannot be read by the
  person it refuses, so its `message` is what they are told, in the
  error and wherever the key's allowed values are explained
  ([decision](decisions.md#a-regex-is-explained-by-its-message)). A
  Regex without one is a smell: the panel can only show the pattern.
- **Everything named by class.** Children, variants, targets, access,
  the surface an alter or situation belongs to: always `X::class`,
  never an instance or a string, so it is one click away.

## Subsurfaces

A surface attaches other surfaces at a key. Each child is its own class,
with its own shape and refiners, and alters can target it alone.

- `attach()` is a fixed child. The field attaches its storage.
- `attachBy()` is a slot: a child that a sibling key chooses. The
  field's `settings` are chosen by `field_type`, and so are the
  storage's own `settings`.
- `attachList()` and `attachListBy()` are collections, and are
  **deferred**; see [Deferred](#deferred).

Both return the map definition at the key, as `add()` returns its
definition, so the owner labels and describes its own subsurface key
with the core setters.

A slot is always **open**. The parent never names its children. Each
child marks itself with `#[SurfaceVariant]`, naming the surface, the
slot and the value it is for, so a new field type brings its settings
surface with it and the field surface never changes:

```php
#[Surface('field.settings.address', target: AddressFieldSettingsTarget::class)]
#[SurfaceVariant(of: FieldInstanceSurface::class, key: 'settings', value: 'address')]
final class AddressFieldSettingsSurface implements SurfaceInterface {
```

The deciding key's allowed values become exactly the values the
variants fill. A slot's values may also come from a description that
already exists: a service implementing `DerivedVariantsInterface` fills
a slot for the values no variant class fills.
`FieldSettingsSchemaVariants` derives a plain `string` or `integer`
field's settings from `field.field_settings.<field type>`, and
`FieldStorageSettingsSchemaVariants` its storage settings from
`field.storage_settings.<field type>`, so a string's `max_length` can be
set when the field is added. A declared variant always wins.

Underneath, a slot is an `any` placeholder that the framework narrows to
the chosen child's map once the deciding key has a value: an ordinary
refinement under the rule that `any` may become anything narrower, not
a new mechanism.

**The escape hatch.** A child whose shape cannot be listed at all is
the one case for a `#[RefinesInput]` method on an `any` key returning
the narrower definition itself, a map included. No verb is needed;
the test module's `DynamicChildSurface` is the example.

**The wall.** A child never reads its parent's values, and a parent
never refines or watches a key inside its child. When a child needs
something from its parent, the parent hands it over as identity in the
child's context, which is how the storage learns which field it belongs
to. That keeps every child buildable and testable on its own.

## Situations: add, reuse, edit

A surface never mentions add or edit. The difference between them is
not shape. It is how much is already known.

- `#[Surface(identity: [...])]` names the **identity keys**: the input
  keys that say which thing this is. An identity key is settable until
  the context knows it, and locked from then on. It is on the attribute
  rather than in `defineInputs()` because identity is the address: what
  a catalogue lists, what a tool or route must supply.
- The ways a surface is asked for are **static methods on the
  surface**: `FieldInstanceSurface::add()`, `::reuse()`, `::edit()`.
  Each returns a `SurfaceContext` and knows more than the last.
- Each carries `#[Situation]`: an id, a label, and the static part of
  its access. What it needs is its own signature. Whether it creates is
  on the context it returns, where the target reads it.
- **Another module can add one.** `#[Situation('clone', of:
  FieldInstanceSurface::class)]` on a static method of any class in its
  `src/SurfaceAlter/` is found the way a hook method is, no interface
  needed, typically building on one of the owner's situations and
  giving the context starting values with `withStarting()`.

That makes situations listable ([the catalogue](catalogue.md)),
addressable (`field.instance:edit`), and generatable: one route or one
tool per situation, its inputs being the parameters plus the surface's
open keys.

| Keys | `add()` | `reuse()` | `edit()` |
| --- | --- | --- | --- |
| Entity type, bundle | locked | locked | locked |
| Field type, machine name | open | locked | locked |
| Label, help text, required | open | open | open, current values loaded |
| Storage subsurface | its own add | its own edit | its own edit |
| Field settings | chosen by field type | fixed by the known type | fixed by the known type |
| Storage settings | chosen by the storage's field type | fixed by the known type | fixed by the known type |

A situation can hand a subsurface its own context (`withChild()`):
reusing a storage is an add for the field and an edit for its storage.
A situation can also narrow (`withConstraint()`): the storage's edit
situation, when the field has data, constrains cardinality not to
shrink. The rule: **what depends on where you are is the situation's;
what depends on what was entered is a refiner's.**

A caller that uses no situation passes a bare context: every identity
key stays open, and it is still the same surface.

## Allowed values

A key says what it allows on its own definition, as a constraint. The
surface has no verb for options.

| The list is | Constraint | Example |
| --- | --- | --- |
| Fixed | core `Choice`, or `LabeledChoice` for labels | `DemoBlockSurface`'s `presentation` |
| Live, from site state | a constraint whose options resolver fetches the list | `DataSurfaceDemoBundleField` on the demo block's `field` |
| Already modelled by core | the core constraint | `EntityBundleExists` on `bundle`, attached by a refiner because it needs the entity type |

**A refiner never calls a service.** A list that depends on a value is
a constraint with the value as an option. The refiner points; the
options resolver fetches ([Options and resolvers](options.md)). That is
what lets a surface stay a class of static methods.

A list can be changed in two places. Tightening one key on one surface
is an alter's `#[RefinesInput]` method. Widening one key on one surface
is `extendChoices()`, for fixed choice lists only; it is the one
widening verb. Changing a list everywhere it is used is an options
alter, which is deferred with options sources. Removing is deliberately
absent.

## Surface alters

An alter says which surface it applies to with `#[AltersSurface]` on its
class, optionally naming the situations it applies in. Static, like the
variant and situation attributes, so a catalogue can say who alters a
surface without running anything.

```php
#[AltersSurface(DemoBlockSurface::class)]
final class DemoBlockAlter implements SurfaceAlterInterface {

  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'), default: 'star');
    $inputs->describe('headline', description: $this->t('Shown above the featured content, beside its badge.'));
  }

  /**
   * A grid shows at most a few items.
   */
  #[RefinesInput('limit')]
  public function shortInGrid(DataDefinitionInterface $limit, string $presentation): DataDefinitionInterface {
    // ...
  }

}
```

- What an alter adds is mounted under its module,
  `third_party_settings.<module>.<key>`, so the owner's storage and
  schema never have to know it. Its `#[RefinesInput]` methods may
  refine and watch those keys by the names it added them under, and
  never another module's; a watched one is handed as it stands, NULL
  while unanswered.
- `describe()` rewords a key anyone declared: label and description
  only, because that changes nothing about what is accepted.
- `extendChoices()` offers more values on a key whose owner declared a
  fixed list. The alter's `#[RefinesInput]` method on that key says what
  its own values mean and is handed only them, so it can never take the
  owner's away (`DemoFormatterAlter`).
- An alter that only tightens implements no interface: the attribute is
  what makes it an alter.
- An alter never touches access.

[Refinement and contributions](refinement.md) has the rules.

## Targets

`#[Surface(target:)]` names a `SurfaceTargetInterface` with three
verbs:

- `load($context)` reads the current values, by the identity the
  context knows, so the context never carries an entity.
- `prepare($context, $values)` rehearses the write with every check
  storage would make (the config schema, a field type the storage could
  not take, a schema change a field with data could not take) and no
  side effects, and returns what would be stored, in storage's own
  shape. A dry run stops here and reports it.
- `commit($context, $prepared)` writes what prepare returned.

Whether to create or update is the context's `creates`. Targets compose
along the tree: the storage subsurface has its own target, committed
before the field; the address settings have their own, committed after
it; a variant with none, such as a derived one, is stored by its parent
under its key. [Targets](targets.md) has the engine side.

## Access

Two tiers, and alters touch neither.

- **Static**: the permission on each `#[Situation]`, answerable with no
  subject. A `%key` in it is filled from the identity the context knows
  (`administer %entity_type_id fields`).
- **Run time**: `#[Surface(access:)]`, a `SurfaceAccessInterface` that
  may hold services, asked once the permission allows.
  `FieldInstanceAccess` refuses a locked storage; `NodeTypeAccess` asks
  the node type entity. A child the context resolves may refuse through
  its own access class, never allow.

## Outputs

A surface that answers with something implements `HasOutputsInterface`:
`defineOutputs()` takes the same shape as inputs, so `defineInputs()`
reads as "what this asks for" and most surfaces never mention outputs.
Outputs are never refined: a refinement narrows what may be sent, and
nobody sends an output. A surface's output values are what its target
loads back after the write. The demo formatter answers with its
rendered `text` and `classes`. [Outputs](outputs.md) has the rest.

## Plugins

A plugin keeps rendering, and names its configuration's surface with
`#[UsesSurface]`. Two spellings, side by side in the demo module.

**Naming a surface class.** The configuration moves to a surface in
`src/Surface/`, and the plugin names it:

```php
#[Block(id: 'data_surface_demo', admin_label: new TranslatableMarkup('Data surface demo'))]
#[UsesSurface(DemoBlockSurface::class)]
final class DataSurfaceDemoBlock extends DataSurfaceBlockBase {

  public function build(): array { /* ... */ }

}
```

**The plugin is its own surface.** `#[UsesSurface]` with no argument,
and the plugin implements `SurfaceInterface`: its static shape and
refiners sit beside the code that uses the values, and the host builds
the surface from the plugin class without constructing it.

```php
#[FieldFormatter(id: 'data_surface_demo_string', label: new TranslatableMarkup('Data surface demo formatter'), field_types: ['string'])]
#[UsesSurface]
final class DataSurfaceDemoFormatter extends DataSurfaceFormatterBase implements SurfaceInterface, HasOutputsInterface {

  public static function defineInputs(ShapeInterface $inputs): void { /* prefix, casing, variant */ }

  public static function defineOutputs(ShapeInterface $outputs): void { /* text, classes */ }

  #[RefinesInput('variant')]
  public static function variantsOfCasing(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface { /* ... */ }

  public function formatValue(FieldItemInterface $item, array $settings): array { /* ... */ }

}
```

Such a surface is found through the plugin definitions, not a
directory. Its id is `<host type>:<plugin id>`
(`field_formatter:data_surface_demo_string`) unless the class also
carries `#[Surface]`; the catalogue lists it as used by that same
plugin; an alter names it by the plugin class,
`#[AltersSurface(DataSurfaceDemoFormatter::class)]`. The separate class
reads better when the surface is large, has subsurfaces or situations,
or is shared; the plugin's own reads better when the plugin is small
and its settings are only ever its own.

Either way: no `blockForm()`, `blockValidate()` or `blockSubmit()`. The
host supplies the context and the target, since only it holds the
instance. The attribute is copied into the plugin definition, so tools
and the catalogue can list surfaced plugins without instantiating any.
Block, formatter, condition, action and field type hosts all read it,
and `DataSurfacePluginForm` serves any other configurable plugin.

## Deferred

Three concepts are recorded here so their position is fixed, and are
not built.

### Collections

`attachList($key, Child::class)` would be a list whose every item is
the child surface; `attachListBy($key, by: 'plugin')` a list whose every
item chooses its own child, the deciding key being *inside* each item
(visibility conditions, each naming its plugin), children marking
themselves with `#[SurfaceVariant]` as for a slot.

The contract is an ordered list, as typed data lists and field items
already are: delta is position, reordering is sending the items in
another order, and nothing stores a delta. Storage that must address
one item (image effects keyed by uuid with a weight) gets that from an
identity key the child declares; the target supplies it on load,
generates it on commit for a new item, and turns order into weights in
`prepare()`. The caller never sees a weight. A child's refiners run per
item in its own frame. Rules across items (no duplicates, at most five)
are constraints on the returned list definition, declared by the
parent. Add, remove and reorder are value changes, since the whole list
is submitted; they are not situations. Changing one item without
resending the list is a later question, probably a situation on the
child.

### Input shapes

Another way to enter the same value, converted to one canonical value
(the widget alter layer on the `variants` branch), is a separate
concept. It waits until subsurfaces have settled, so that one idea
arrives at a time.

### Options

`OptionsList` naming an options source class in `src/Options/` (a
class that may hold services and answers a list for some arguments),
and options alters in `src/OptionsAlter/` changing one list everywhere
it is used. Until then a live list is a constraint with an options
resolver, as above. Two points are recorded for when it is built: an
options alter may remove an entry, under the stale rule the module
already has (a stored value no longer offered renders as no longer
available, is kept on an untouched save, and is refused as a new
value); and a site policy that hides an owner's key is the same later
concept. The naming of sources and resolvers is undecided.

## Questions the pattern settled

1. **Should an alter declare its target?** Yes: `#[AltersSurface]` on
   the class, with optional `situations`. An alter that targets by
   interface rather than class is the one thing lost, and no example
   needed it.
2. **A child depends on its parent's class.** `#[SurfaceVariant]` names
   `FieldInstanceSurface::class`, so the address module references the
   field surface's module. That direction is right: it is the
   dependency the address module already has on fields, and a class
   name is what makes the slot one click away.
3. **Create-only keys that are not identity.** No new verb.
   `withConstraint()` with a one-value `Choice` is a lock in every way
   that matters: the form renders it fixed, the pipeline refuses
   anything else, the schema says one value.
4. **May an options alter remove entries?** Yes, under the stale rule
   (see [Options](#options)), once options alters exist.
5. **Parent and child.** Keep the wall, with one door: identity in the
   child's context.
6. **The build step.** It is `Surfaces::build()`, written against the
   module's existing builder; [How it fits](how-it-fits.md) walks it.

## What it replaced

| The pattern | Before the rework |
| --- | --- |
| A class in `src/Surface/` | a static `declareDataSurface()` on the host, or a provider service |
| `#[RefinesInput('key')]` methods, dependencies in the signature | `refineDataDefinition($name, $definition, $values)` with a `match`, dependencies declared separately |
| `attach()`, `attachBy()` | `mount()`, `mountVariants()` |
| `#[SurfaceVariant]` on the child | a resolver service, or a table the parent holds |
| `#[Surface(identity:)]` and `#[Situation]` methods | the builder's `lock()`, called by each provider per operation |
| A class in `src/SurfaceAlter/` with `#[AltersSurface]` | a build event subscriber holding the full builder |
| `#[Surface(target:, access:)]` | the provider triple: `getDataSurface()`, `surfaceAccess()`, `getDataSurfaceTarget()` |
| `defineOutputs()`, never refined | `setOutputDefinition()` plus output refiners |
| `#[UsesSurface]` on the plugin, naming a surface or being one | `declareDataSurface()` on the plugin class itself |
