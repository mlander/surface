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
  what the owner declared. Adding a key twice is refused.
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

`DemoBlockAlter` in the demo extras module does both: it adds a badge,
and caps the number of items while summaries are shown.

```php
#[AltersSurface(DemoBlockSurface::class)]
final class DemoBlockAlter implements SurfaceAlterInterface {

  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'), default: 'star');
  }

  #[RefinesInput('limit')]
  public function shortWithSummaries(DataDefinitionInterface $limit, bool $show_summary): DataDefinitionInterface {
    // ...
  }

}
```

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
- starting values on a context that does not create.

## Not built yet

Subsurfaces. `attach()` and `attachBy()` are declared on the shapes and
refuse until they are built, and `#[SurfaceVariant]` is collected by
discovery but fills nothing yet. Options lists, and the alters of them,
are a separate piece of work; until then a live list is a constraint
with an options resolver.
