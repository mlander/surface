# Surfaces as classes

A surface can be written as a class of its own: one method that declares
its shape, and one named method per key whose allowed values depend on
another key. Other modules change it with classes of their own, and
nothing is registered by hand. This is the spelling the module is moving
to. The older one — `declareDataSurface()` on a host, a provider
service, a build event subscriber — still works beside it and is what
the rest of these pages describe; both build the same sealed surface, so
the pipeline, forms, widgets and targets read either without knowing.

## Where things live

A module has two predictable directories, scanned in every enabled
module the way core scans `src/Hook`:

| | Owns | Reaches into |
| --- | --- | --- |
| **Surfaces** | `src/Surface/` | `src/SurfaceAlter/` |

- A class in `src/Surface/` carrying `#[Surface]` is a surface. It has
  no constructor and holds no service.
- A class in `src/SurfaceAlter/` carrying `#[AltersSurface]` is an
  alter. It is registered as an autowired service, so it may hold
  services.
- A static method carrying `#[Situation]` in either directory is a
  situation.

Two more kinds of class are named by a surface rather than discovered:
its target (`#[Surface(target:)]`) and its run-time access class
(`#[Surface(access:)]`). Both are registered as autowired services when
the surface naming them is found. Their directory is a suggestion —
`src/Target/`, `src/Access/`.

The API is in `Drupal\data_surface\Surface`; the attributes in
`Drupal\data_surface\Surface\Attribute`.

## The pattern

| | Shape | Values |
| --- | --- | --- |
| **Owner** | `defineInputs($inputs)`, optionally `defineOutputs($outputs)` | `#[RefinesInput('key')]` methods |
| **Another module** | `alterInputs($inputs)`, optionally `alterOutputs($outputs)` | `#[RefinesInput('key')]` methods |

The demo block's configuration, `DemoBlockSurface` in the demo module,
is the first one written this way:

```php
#[Surface('block.data_surface_demo')]
final class DemoBlockSurface implements SurfaceInterface {

  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('entity_type', 'string', new TranslatableMarkup('Entity type'), default: 'user')
      ->setRequired(TRUE)
      ->addConstraint('PluginExists', [
        'manager' => 'entity_type.manager',
        'interface' => ContentEntityInterface::class,
      ]);
    $inputs->add('bundle', 'string', new TranslatableMarkup('Bundle'));
    $inputs->add('field', 'string', new TranslatableMarkup('Highlight field'));
    // ...
  }

  #[RefinesInput('bundle')]
  public function bundleOfEntityType(DataDefinitionInterface $bundle, string $entity_type): DataDefinitionInterface {
    return $bundle->addConstraint('EntityBundleExists', ['entityTypeId' => $entity_type]);
  }

  #[RefinesInput('field', watches: ['entity_type', 'bundle'])]
  public function fieldOfBundle(DataDefinitionInterface $field, string $entity_type, string $bundle): DataDefinitionInterface {
    return $field->addConstraint('DataSurfaceDemoBundleField', [
      'entityTypeId' => $entity_type,
      'bundle' => $bundle,
    ]);
  }

}
```

- **The shape methods get a shape to fill, and nothing else**: no
  values, no context. The owner gets `ShapeInterface`; an alter gets
  `ShapeAdditionsInterface`, which can add but not change or remove
  what the owner declared. Adding a key twice is refused. The one change
  anyone may make to a key already declared is `describe()`: its label
  and description, which change nothing about what is accepted.
- **`add()` takes name, type and label** and returns the core
  definition, so the rest is plain core API. `addDefinition()` is the
  long form, for a map or a list.
- **A `#[RefinesInput('key')]` method takes the key's definition first,
  then one parameter per sibling it watches**, matched by name, or
  listed as `watches:` so that a renamed parameter is refused when the
  surface is built rather than silently never watched. It runs once
  every watched sibling has a value, and again when one changes. A
  method that watches nothing runs once, when the surface is built.
- **A refiner never calls a service.** A list that depends on a value
  is a constraint whose options resolver fetches it, handed the value as
  an option. The refiner points; the resolver fetches. Core's
  `EntityBundleExists` is one; the demo brings `DataSurfaceDemoBundleField`
  for a bundle's fields.
- **The framework checks every refinement is narrower**, exactly as it
  does for the older spelling, so a refiner cannot widen, retype, or
  turn off required.

## Reading a surface

- `defineInputs()` is a flat list. No `if`, no loop.
- Name, type and label sit on one line.
- It is ordered like the thing it describes: identity first, the
  thing's own keys next.
- `defineInputs()` never mentions a sibling. Anything that reads another
  key's value is a `#[RefinesInput]` method, one rule per method, its
  docblock stating the rule and its signature stating what it reads.
- Everything is named by class: the surface an alter or a situation
  belongs to, a target, an access class. Never an instance or a string.

## Situations: add, edit, and the rest

A surface never mentions add or edit. The difference is how much is
already known, and that is a **context**, a `SurfaceContext`:

- `#[Surface(identity: [...])]` names the identity keys. An identity key
  the context knows is locked to that value; one it does not know stays
  open. That is the whole difference between add and edit.
- The ways a surface is asked for are static methods carrying
  `#[Situation]`, each returning a context whose operation is the
  situation id. The permission on the attribute is the static part of
  access; `%key` in it is filled from the identity the context knows.
- Another module adds a situation with
  `#[Situation('clone', of: SomeSurface::class)]` on a static method in
  its `src/SurfaceAlter/`. Two providers of one id are refused, naming
  both.
- A context may also narrow a key (`withConstraint()`, checked narrower)
  and, when it creates, give starting values (`withStarting()`), which
  become the key's defaults: values the caller sees first and may
  change.

```php
$surfaces = \Drupal::service('data_surface.surfaces');
$surface = $surfaces->buildSituation(RecipeSurface::class, 'edit', ['main', 'stew']);
$access = $surfaces->access(RecipeSurface::class, RecipeSurface::edit('main', 'stew'));
$target = $surfaces->target(RecipeSurface::class, RecipeSurface::edit('main', 'stew'));
```

The target is a `SurfaceTargetInterface` with `load()` and `commit()`,
reached by the pipeline's `submit()` like any other target. A generic
caller passes an empty context: every identity key stays open, and it
is still the same surface.

## Plugins

A plugin keeps rendering; its configuration is a surface in
`src/Surface/`, named by `#[UsesSurface]` on the plugin class. The
attribute is copied into the plugin definition, so a tool can list the
surfaced plugins without instantiating one. The host supplies the
context (`configure`, knowing nothing) and the target (the plugin's own
configuration), because only it holds the instance:

```php
#[Block(id: 'data_surface_demo', admin_label: new TranslatableMarkup('Data surface demo'))]
#[UsesSurface(DemoBlockSurface::class)]
final class DataSurfaceDemoBlock extends DataSurfaceBlockBase {

  public function build(): array { /* ... */ }

}
```

Blocks do this today. Formatters, conditions and actions follow.

## Surface alters

An alter says which surface it applies to with `#[AltersSurface]` on its
class, optionally naming the situations it applies in. What it adds is
mounted under its own module's name — at
`third_party_settings.<module>.<key>` for an input,
`third_party_outputs.<module>.<key>` for an output — so the owner's
storage and schema never have to know a contributor's keys. Its
`#[RefinesInput]` methods tighten the owner's keys, running after the
owner's own.

`DemoBlockAlter` in the demo extras module does all three things an
alter can: it adds a badge, rewords the headline's help text with
`describe()`, and caps the number of items while the block is a grid.

```php
#[AltersSurface(DemoBlockSurface::class)]
final class DemoBlockAlter implements SurfaceAlterInterface {

  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'), default: 'star');
    $inputs->describe('headline', description: $this->t('Shown above the featured content, beside its badge.'));
  }

  #[RefinesInput('limit')]
  public function shortInGrid(DataDefinitionInterface $limit, string $presentation): DataDefinitionInterface {
    // ...
  }

}
```

`describe()` takes the owner's key by its name, the alter's own key by
its name, and another alter's key by its mounted path,
`third_party_settings.<module>.<key>`.

A build event subscriber written for the older spelling still runs on a
surface built this way, after the alters and the context, so the two
spellings extend each other while consumers move over.

## What is checked when a surface is built

Each refusal names the class and method at fault:

- an identity key the shape never declares;
- a `#[RefinesInput]` naming a key the shape does not declare, or an
  output key;
- a watched sibling that is not a declared input;
- a `watches:` list that does not match the parameters, in order;
- two providers of one situation id;
- a context constraint, or a refiner that watches nothing, that widens;
- starting values on a context that does not create;
- a refiner that refines or watches a subsurface key, or a child's
  refiner that watches a key only its parent declares;
- a slot chosen by a key that is not a plain input, or a variant its
  deciding key does not allow;
- a context constraint or starting value for a subsurface key;
- a surface attached inside itself.

## Subsurfaces

A surface attaches other surfaces at a key. Each child is its own class,
with its own shape and refiners, and alters can target it alone.

- `attach('key', Child::class)` is a fixed child.
- `attachBy('key', by: 'sibling', children: [...])` is a child the
  sibling's value chooses: a **slot**. With no children it is an
  **open** slot, filled by every surface marked
  `#[SurfaceVariant(of: Parent::class, key: 'key', value: '...')]`, so a
  new variant brings itself and the parent never changes.

The demo block's presentation is a slot with two children named by
class; the field instance surface's settings are an open slot the
address module fills:

```php
// DemoBlockSurface
$inputs->add('presentation', 'string', new TranslatableMarkup('Presentation'), default: 'list')
  ->setRequired(TRUE)
  ->addConstraint('Choice', ['choices' => ['list', 'grid']]);
$inputs->attachBy('presentation_settings', by: 'presentation', children: [
  'list' => ListPresentationSurface::class,
  'grid' => GridPresentationSurface::class,
]);
$inputs->describe('presentation_settings', label: new TranslatableMarkup('Presentation settings'));

// FieldInstanceSurface, in data_surface_tool
$inputs->attachBy('settings', by: 'field_type');

// AddressFieldSettingsSurface, in data_surface_address
#[Surface('field.settings.address', target: AddressFieldSettingsTarget::class)]
#[SurfaceVariant(of: FieldInstanceSurface::class, key: 'settings', value: 'address')]
```

What that means, end to end:

- **A child is built by the same build step**, after its parent's
  context is applied: its own shape, its own alters, its own refiners,
  its own build event (named `surface:<child id>`). It sees the context
  the parent's context hands it with `withChild()`, and otherwise the
  parent's operation, `creates` and known identity — never the parent's
  constraints or starting values, which name the parent's keys.
- **Its value is a map at the key**, advertised as a map whose
  properties are the child's keys. The child accepts, validates and
  refines it, in its own frame. Violations come back dotted under the
  parent's key (`presentation_settings.columns`); that, and an emitted
  schema, are the only places a path crosses into a child.
- **The wall.** A parent cannot refine a subsurface key or watch one;
  a child cannot watch its parent's keys. The one door is the context:
  a parent hands a child what it needs as identity, the way the field
  instance surface's situations tell its settings which field they
  belong to.
- **A slot** is advertised as an `any` placeholder marked as a slot
  (`DefinitionMetadata::slotOf()`) while its deciding key holds
  nothing, and as exactly the chosen variant's map once it does — or
  from the start, when the context locks the deciding key. The deciding
  key gains a Choice over the variants' values, checked narrower than
  any list it already had, and becomes a refinement dependency of the
  slot, so the form rebuilds on it over AJAX and the discard cascade
  drops input left by another variant. A payload carrying another
  variant's keys is refused by name
  (`presentation_settings.show_summary belongs to the list variant, but
  presentation chose grid.`); a stored value that does not fit a newly
  chosen variant falls back to that variant's defaults.
- **Narrowing reads inside maps.** A refined map holds exactly the
  properties it was handed, each held to the same table, list items
  too; the one shape refinement may introduce is a slot's placeholder
  resolving to its variant.
- **Targets compose along the tree.** A child whose `#[Surface(target:)]`
  names a target is loaded from and committed to it, after its parent,
  in its own context plus whatever identity the parent accepted that it
  did not already know; a child without one is stored by its parent
  under its key. `SurfacesInterface::target()` builds the composed
  target.
- **Emission.** The tool bridge converts an attached child to a nested
  map input, and an unresolved slot to the widest honest map: every
  variant's keys, none required and none with a default, each
  annotated with the values it belongs to, the table in the
  description. The Tool API cannot say a union keyed by a sibling.

An alter cannot attach yet (its keys are mounted under its module), and
neither can an output; both are refused by name.

## Not built yet

Options lists, and the alters of them, are a separate piece of work;
until then a live list is a constraint with an options resolver.
