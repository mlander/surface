# Surfaces as classes

A surface is a class of its own: one static method that declares its
shape, and one named static method per key whose allowed values depend
on another key. Other modules change it with classes of their own, and nothing is
registered by hand. The build step, `data_surface.surfaces`, turns the
class and a context into a sealed `DataSurfaceInterface`, which the
pipeline, forms, widgets and targets read.

This page is the reference for each part. [The pattern](pattern.md) is
the short version with the reasoning, [How it fits](how-it-fits.md)
walks one build, and [Decisions](decisions.md) records each point the
pattern left open.

## Where things live

A module has two predictable directories, scanned in every enabled
module the way core scans `src/Hook`:

| | Owns | Reaches into |
| --- | --- | --- |
| **Surfaces** | `src/Surface/` | `src/SurfaceAlter/` |

- A class in `src/Surface/` carrying `#[Surface]` is a surface. It is
  never instantiated: its shape and refiners are static, called on the
  class, so it has no constructor and holds no service.
- A plugin carrying `#[UsesSurface]` with no argument is its own
  surface, found through its plugin definition rather than a directory
  ([Plugins](#plugins)).
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
| **Owner** | `static defineInputs($inputs)`, optionally `static defineOutputs($outputs)` | static `#[RefinesInput('key')]` methods |
| **Another module** | `alterInputs($inputs)`, optionally `alterOutputs($outputs)` | `#[RefinesInput('key')]` methods |

The demo block's configuration, `DemoBlockSurface` in the demo module,
is the first one written this way:

```php
#[Surface('block.data_surface_demo')]
final class DemoBlockSurface implements SurfaceInterface {

  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('entity_type', 'string', t('Entity type'), default: 'user')
      ->setRequired(TRUE)
      ->addConstraint('PluginExists', [
        'manager' => 'entity_type.manager',
        'interface' => ContentEntityInterface::class,
      ]);
    $inputs->add('bundle', 'string', t('Bundle'));
    $inputs->add('field', 'string', t('Highlight field'));
    // ...
  }

  #[RefinesInput('bundle')]
  public static function bundleOfEntityType(DataDefinitionInterface $bundle, string $entity_type): DataDefinitionInterface {
    return $bundle->addConstraint('EntityBundleExists', ['entityTypeId' => $entity_type]);
  }

  #[RefinesInput('field', watches: ['entity_type', 'bundle'])]
  public static function fieldOfBundle(DataDefinitionInterface $field, string $entity_type, string $bundle): DataDefinitionInterface {
    return $field->addConstraint('DataSurfaceDemoBundleField', [
      'entityTypeId' => $entity_type,
      'bundle' => $bundle,
    ]);
  }

}
```

- **The shape methods get a shape to fill, and nothing else**: no
  values, no context, no services. A surface's are static, so the
  language holds them to that; the build step calls them on the class
  and never makes an instance. The owner gets `ShapeInterface`; an alter gets
  `ShapeAdditionsInterface`, which can add but not change or remove
  what the owner declared. Adding a key twice is refused. The one change
  anyone may make to a key already declared is `describe()`: its label
  and description, which change nothing about what is accepted.
- **`add()` takes name, type and label** and returns the core
  definition, so the rest is plain core API. `addDefinition()` is the
  long form, for a map or a list. A label is `t('...')`, with a literal
  string: a static method has no instance for the translation service
  to be injected into, and `t()` returns the same lazy
  `TranslatableMarkup`.
- **A `#[RefinesInput('key')]` method takes the key's definition first,
  then one parameter per sibling it watches**, matched by name, or
  listed as `watches:` so that a renamed parameter is refused when the
  surface is built rather than silently never watched. It runs once
  every watched sibling has a value, and again when one changes. A
  method that watches nothing runs once, when the surface is built. A
  surface's refiner is static, and one that is not is refused when the
  surface is built; an alter's is an instance method, because an alter
  is a service and may hold configuration.
- **A refiner never calls a service.** A list that depends on a value
  is a constraint whose options resolver fetches it, handed the value as
  an option. The refiner points; the resolver fetches. Core's
  `EntityBundleExists` is one; the demo brings `DataSurfaceDemoBundleField`
  for a bundle's fields.
- **The framework checks every refinement is narrower**, so a refiner
  cannot widen, retype, or turn off required. The two type changes it
  accepts are away from `any` and to a derivative of the type
  (`entity` to `entity:node` to `entity:node:article`); see [the
  narrowing table](refinement.md#the-narrowing-table).

## Reading a surface

- Everything on it is static: shape, refiners, situations. No
  constructor, no properties, no `$this`.
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
  situation id. What a situation needs is its own signature: a route or
  a tool supplies its parameters by name.
- Another module adds a situation with
  `#[Situation('clone', of: SomeSurface::class)]` on a static method in
  its `src/SurfaceAlter/`. Two declarations of one id are refused,
  naming both.
- A context may also narrow a key (`withConstraint()`, checked narrower)
  and, when it creates, give starting values (`withStarting()`), which
  become the key's defaults: values the caller sees first and may
  change. It may hand a subsurface a context of its own
  (`withChild()`). What depends on where you are is the situation's;
  what depends on what was entered is a refiner's.

The content type surface is the worked example:

```php
#[Surface('node.type',
  identity: ['type'],
  target: NodeTypeTarget::class,
  access: NodeTypeAccess::class,
)]
final class NodeTypeSurface implements SurfaceInterface {

  #[Situation('add', label: 'Add a content type', permission: self::PERMISSION)]
  public static function add(): SurfaceContext {
    // Unique on add only: that is where you are, not what was entered.
    return (new SurfaceContext('add', creates: TRUE))
      ->withConstraint('type', 'DataSurfaceUniqueNodeType');
  }

  #[Situation('edit', label: 'Edit a content type', permission: self::PERMISSION)]
  public static function edit(NodeTypeInterface $type): SurfaceContext {
    return new SurfaceContext('edit', known: ['type' => $type->id()]);
  }

  // defineInputs(): name, type, description, title_label, ...

}
```

and the field instance surface has three, each knowing more than the
last: `add($entity_type_id, $bundle)`, `reuse($storage, $bundle)` — an
add for the field and, through `withChild('storage',
FieldStorageSurface::edit($storage))`, an edit for its storage — and
`edit($field)`. The storage's edit situation narrows cardinality not to
shrink once the field has data, with `withConstraint()`, and knows the
field type, its one identity key, which locks its settings to that
type's.

```php
$surfaces = \Drupal::service('data_surface.surfaces');
// By parameter name or position; an entity parameter takes the entity
// or its id, loaded by the entity type whose class satisfies the type.
$context = $surfaces->situation(NodeTypeSurface::class, 'edit', ['type' => 'article']);
$surface = $surfaces->build(NodeTypeSurface::class, $context);
$access = $surfaces->access(NodeTypeSurface::class, $context);
$target = $surfaces->target(NodeTypeSurface::class, $context, $surface);
```

A generic caller passes an empty context: every identity key stays
open, and it is still the same surface.

### Routes from situations

A route names a surface class and a situation, and its parameters are
the situation method's, by name; an upcast entity parameter arrives as
the entity. `DataSurfaceSituationForm` builds the context with the
situation, renders the surface, loads current values from the composed
target and submits through the pipeline to it. The route's requirement
is the situation's access:

```yaml
data_surface_demo_node_type.edit:
  path: '/admin/structure/types/manage/{type}/surface-edit'
  defaults:
    _form: 'Drupal\data_surface\Form\DataSurfaceSituationForm'
    _data_surface_surface: 'Drupal\data_surface_demo_node_type\Surface\NodeTypeSurface'
    _data_surface_situation: 'edit'
    _data_surface_cosmetics: 'data_surface_demo_node_type.form_cosmetics'
  requirements:
    _data_surface_situation_access: 'TRUE'
  options:
    parameters:
      type:
        type: 'entity:node_type'
```

A locked identity key renders as a disabled element holding the value
the situation knows, and extraction keeps that value whatever is
posted. The cosmetic layer (`DataSurfaceFormCosmeticsInterface`), named
by the route's optional `_data_surface_cosmetics`, is told the situation
id as its operation and the raw value of the situation's first route
parameter. [Generated forms](forms.md#the-situation-form) has the rest.

## Access

Two tiers, and alters never touch either:

- **The situation's permission**, on `#[Situation(permission:)]`: the
  static part, answerable with nothing loaded. A `%key` in it is filled from
  the identity the context knows (`administer %entity_type_id fields`);
  a placeholder the context does not know is refused.
- **The surface's access class**, `#[Surface(access:)]`, a
  `SurfaceAccessInterface` that may hold services, asked only once the
  permission allows: what depends on the thing itself. `NodeTypeAccess` asks
  the node type entity whether one may be created, or this one updated,
  so this module never grants more than core's own form;
  `FieldInstanceAccess` refuses a locked storage.

`SurfacesInterface::access()` is that answer, and every caller reads it:
the route requirement `_data_surface_situation_access`, the form's
floor, the content type listing's operation link, and the generated
tool. A situation owns its operation, so a caller that hands the answer
to the pipeline makes it decisive (`DataSurfaceAccess::decisive()`): no
opinion is a refusal, as it is on a route.

## Targets

`#[Surface(target:)]` names a `SurfaceTargetInterface` with three verbs:
`load()`, `prepare()` and `commit()`. It loads by the identity the
context knows, so the context never carries an entity, and creates or
updates by the context's `creates`. `SurfacesInterface::target()` adapts
it to the pipeline's target and composes it along the tree:

- A subsurface whose class names a target is loaded from, prepared for
  and committed to it, in the context its parent's hands it, plus the
  identity the parent accepted that it did not already know. An
  attached child (a field's storage) is committed **before** its parent,
  which is built on it; a slot's variant (a field's settings) **after**,
  since it lives on what the parent writes.
- A subsurface without one is stored by its parent under its key.
- An alter whose keys are asked for in one shape and stored in another
  implements `HasStorageShapeInterface`. Its shape is applied to that
  module's mount, `third_party_settings.<module>`, and nothing else:
  `toStorage()` before the target prepares, `fromStorage()` after it
  reads. `NodeTypeAlter` in the extras module asks for a review
  deadline as an amount and a unit; `NodeTypeTarget` writes the seconds
  core's form writes, and never knows the extras module exists.

## Prepare

`prepare(SurfaceContext $context, array $values): array` rehearses the
write. It builds what `commit()` would store, in storage's own shape,
holds it to every check storage itself would make — the config schema,
which for a config entity is also its entity validation — and has no
side effects. It returns that array, and `commit(SurfaceContext
$context, array $prepared)` writes exactly it.

- **A dry run stops after prepare** and reports what it returned. The
  pipeline's result carries it as `PreparedValues`; the adapter's
  `preview()` turns it into plain arrays (a surface with routed children
  reports its own under `own` and each child's under `children`, by the
  key it sits at), and a derived tool's dry run answers with it as the
  `prepared` output.
- **A real submit prepares first**, so `commit()` is never the first to
  find out storage would refuse. A refusal is a
  `TargetViolationsException` carrying violations filed under the
  surface keys that own them, which the pipeline returns like its own;
  a routed child's are filed under the key it sits at. Every level is
  prepared before anything is thrown, so one answer names them all.
- What each target here checks: `NodeTypeTarget` builds the unsaved
  node type (a copy of the stored one, or a new one) and each base field
  override that would move, and holds them to `node.type.*` (fully
  validatable: a name with a line break is refused, as core's form
  refuses it) and to the override's schema; empty description and help
  are stored as none, as core's form stores them. `FieldStorageTarget`
  and `FieldInstanceTarget` build the unsaved storage and field (a field
  being added on a storage being added beside it is checked against an
  unsaved stand-in built from the same identity) and hold them to
  `field.storage.*` and `field.field.*`; the storage target also
  refuses a field type other than its field's, and, once the field has
  data, a settings change that would alter its database columns, which
  the SQL storage would otherwise throw on at save. `AddressFieldSettingsTarget`
  holds the settings, in the shape the field stores, to
  `field.field_settings.address`.
- What a prepare returns is what is stored: the node type's prepared
  array is the config the commit writes, uuid included.

## Tools

With `data_surface_tool` enabled, a situation that can be asked on its
own is a tool, `data_surface:<surface id>:<situation id>`, derived by
`SurfaceSituationToolDeriver` from the static layer alone:

| Tool | Inputs |
| --- | --- |
| `data_surface:node.type:add` | `values` (every key, `type` unique), `dry_run` |
| `data_surface:node.type:edit` | `type` (the content type's id), `values` (every key but `type`), `dry_run` |
| `data_surface:field.instance:add` | `entity_type_id`, `bundle`, `values`, `dry_run` |
| `data_surface:field.instance:reuse` | `storage` (its id), `bundle`, `values`, `dry_run` |
| `data_surface:field.instance:edit` | `field` (its id), `values`, `dry_run` |
| `data_surface:field.storage:edit` | `storage` (its id), `values`, `dry_run` |

- **Inputs** are the situation's parameters, by name — an entity
  parameter as the entity's id, resolved when the tool runs; a scalar
  parameter named for one of the surface's keys described as that key,
  with its label, meaning and allowed values — then `values`, the
  surface's keys less the identity keys the situation knows, then
  `dry_run`. A key the surface requires is required in `values`, whatever
the situation, and for a situation that needs nothing and does not
create, each key's default is the value stored now. The definition is static, which is what situations make
  possible: a situation that needs nothing is the exact contract before
  anyone calls, and one that needs an existing thing refines `values`
  to its real context (a field's settings become its type's) through
  the Tool API's own input refiners once its parameters arrive.
- **Access** is the situation's, decisively, then each subsurface the
  context resolves may refuse through its own access class.
- **Execution** is the situation, the build, and one pipeline submit to
  the composed target; a dry run stops after prepare.
- **Outputs** are the accepted `values`, `committed`, and the surface's
  own outputs, as its target reads them back after the write; a dry run
  answers with `prepared`, what prepare rehearsed, instead.

Two rules say what is not a tool, and the catalogue applies the same
two (`SurfaceCatalogue::standalone()`):

1. **A permission nothing can name.** A situation whose permission has a
   `%key` placeholder that none of its parameters can supply — one named
   for it, or an entity its situation reads identity off — could never
   be allowed, so it is no tool. `field.storage`'s `add` needs nothing
   and asks `administer %entity_type_id fields`: only a field adding its
   storage can ask it, as that field's child.
2. **A plugin's surface.** A surface with no target of its own, or one
   any plugin names with `#[UsesSurface]`, is configured through its
   host, which holds the instance and supplies the target. It gets no
   tool, whatever it declares.

A surface or situation that cannot be described is left out and logged.

## Catalogue

`data_surface.surface_catalogue` (`SurfaceCatalogue::describe()`) lists
every discovered surface with its id, class, identity, target, access
class, the plugins whose configuration it is (`<host type>:<plugin id>`,
from the plugin definitions, through `data_surface.surface_plugins`),
situations (id, label, parameters, whether it creates, permission, the
placeholders nothing supplies, whether it can be asked on its own),
alters, declared variants and, per slot, where derived variants come
from, without building anything. Whether a situation
creates is on the context it returns, so it is known only for a
situation that needs nothing. [`catalogue.md`](catalogue.md) is that
array for this repository's modules, generated by
`scripts/generate-catalogue.php` and held to it by
`SurfaceCatalogueTest`.

## Plugins

A plugin keeps rendering; its configuration is a surface, named by
`#[UsesSurface]` on the plugin class. The host supplies the context
(`configure`, knowing nothing) and the target (the plugin's own
configuration, or for a field type the field config Field UI is
editing), because only it holds the instance. The surface is a class in
`src/Surface/` the attribute names:

```php
#[Block(id: 'data_surface_demo', admin_label: new TranslatableMarkup('Data surface demo'))]
#[UsesSurface(DemoBlockSurface::class)]
final class DataSurfaceDemoBlock extends DataSurfaceBlockBase {

  public function build(): array { /* ... */ }

}
```

or, with no argument, the plugin class itself, which implements
`SurfaceInterface` with the same static methods a surface class has:

```php
#[FieldFormatter(id: 'data_surface_demo_string', label: new TranslatableMarkup('Data surface demo formatter'), field_types: ['string'])]
#[UsesSurface]
final class DataSurfaceDemoFormatter extends DataSurfaceFormatterBase implements SurfaceInterface, HasOutputsInterface {

  public static function defineInputs(ShapeInterface $inputs): void { /* ... */ }

  public static function defineOutputs(ShapeInterface $outputs): void { /* ... */ }

  #[RefinesInput('variant')]
  public static function variantsOfCasing(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface { /* ... */ }

  public function formatValue(FieldItemInterface $item, array $settings): array { /* ... */ }

}
```

A plugin that is its own surface is found through the plugin
definitions (`SurfacePlugins::ownSurfaces()`), not a directory, and
discovery holds it to the same rules: it implements `SurfaceInterface`,
its shape and refiners are static. Its id is `<host type>:<plugin id>`,
`field_formatter:data_surface_demo_string`, unless the class also
carries `#[Surface]`, whose id, identity and access then apply. The
catalogue lists it as used by that same plugin, and an alter names it
by the plugin class. A target or access class it names is not
registered by the compiler pass, which never sees it; the class
resolver builds one, so it is constructor-free or implements
`ContainerInjectionInterface`.

Every host reads it the same way:

| Host | Base or trait | Context | Target |
| --- | --- | --- | --- |
| Block | `DataSurfaceBlockBase` | `configure` | the block's configuration |
| Formatter | `DataSurfaceFormatterBase` | `configure` | none: the display stores it |
| Condition | `DataSurfaceConditionBase` | `configure` | the condition's configuration |
| Action | `DataSurfaceActionBase` | `configure` | the action's configuration |
| Field type | `DataSurfaceFieldTypeTrait` | `field_settings` | the field config's settings |
| Any configurable plugin | `DataSurfacePluginForm` | the form's operation | its configuration |

A host's methods take no arguments that say which surface or which
thing: `getDataSurface()` builds the surface the plugin names,
`getDataSurfaceTarget()` is the target in the last column (a formatter
throws), and `surfaceAccess(?AccountInterface $account, string
$operation)` asks the surface's access class in the host's context,
neutral when it names none. A field type's surface is
`getFieldSurface()`, on `FieldSurfaceProviderInterface`, which Field
UI's static `#element_validate` callback needs to find the rebuilt item
by.

- **Into the definition.** `SurfacePluginHooks` copies the attribute
  into each host's plugin definitions, under `UsesSurface::DEFINITION_KEY`:
  the surface class it names, or the plugin's own class when it names
  none,
  with one definition alter per host, run last so a class another
  module swapped in (`SurfaceAddressItem` for `AddressItem`) is the one
  read. A field item reaches it as its typed data definition, which core
  derives from the field type's. So the catalogue lists which plugins
  use a surface without instantiating one.
- **The build.** `DataSurfaceHostTrait::hostedSurface()` builds the
  surface the definition names, in `new SurfaceContext($operation)`: the
  host's own verb, which is no declared situation, and nothing known. A
  plugin naming no surface is refused by name.
- **Static defaults.** A formatter's `defaultSettings()` and a field
  type's `defaultFieldSettings()` are asked of the class, so they read
  the attribute off the class and take `SurfacesInterface::defaults()`:
  the surface's own shape alone, no alter, context or refiner.
- **Access.** A field type's host asks the field config entity, then
  the surface's access class, which may refuse. The gated test field
  type refuses that way, in Field UI and in the derived field tools
  alike.
- **No base class needed.** `DataSurfacePluginForm` serves any
  configurable plugin whose definition names a surface: it builds that
  surface in its operation and stores into the configuration array.

The demo block, the demo formatter, the address field type and every
plugin in the test module are written this way; the demo formatter and
the test module's sticky note block are their own surface. A plugin surface is
never a tool (see Tools).

## Surface alters

An alter says which surface it applies to with `#[AltersSurface]` on its
class, optionally naming the situations it applies in. What it adds is
mounted under its own module's name — at
`third_party_settings.<module>.<key>` for an input,
`third_party_outputs.<module>.<key>` for an output — so the owner's
storage and schema never have to know a contributor's keys. Its
`#[RefinesInput]` methods tighten the owner's keys, running after the
owner's own, and may tighten the keys the alter itself added, named as
the alter named them. They may watch the owner's keys and the keys the
alter itself added, the latter named the same way and handed as they
stand, NULL while unanswered, so the parameter takes NULL. In the
examples' compliance alter, `#[RefinesInput('capacity')]` watches the
alter's own `licence` and caps the owner's capacity at a hundred while
it is empty, and `#[RefinesInput('stewards')]` raises the alter's own
stewards count with the owner's `capacity`. A watched mounted key is
known everywhere else by its path, `third_party_settings.<module>.<key>`:
the capacity's dependencies, its AJAX trigger and its `dependsOn` name
the licence that way. Another module's mounted key is watched by no
alter ([Decisions](decisions.md#an-alter-watches-its-own-mounted-key)).

The one widening an alter may make is `extendChoices()`: more values on
a key whose owner declared a list of allowed values. The values are the
alter's module's contribution, advertised under its name, and an
`#[RefinesInput]` method of the same alter on that key is that
contribution's refiner: it is handed the alter's values only, the
owner's methods never see them, and what is offered is the union.
`DemoFormatterAlter` offers a ribbon variant on the demo formatter and
keeps it to upper case.

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

Nothing an alter does removes: it cannot take a key or a value away
from the owner. A site policy that hides an owner's key is a later
concept, not a verb here.

## What is checked when a surface is built

Each refusal names the class and method at fault:

- an identity key the shape never declares;
- a `#[RefinesInput]` naming a key the shape does not declare, or an
  output key;
- a watched sibling that is not a declared input;
- a `watches:` list that does not match the parameters, in order;
- two declarations of one situation id;
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
- `attachBy('key', by: 'sibling')` is a child the sibling's value
  chooses: a **slot**. A slot is always open. The parent never names
  its children: every surface marked
  `#[SurfaceVariant(of: Parent::class, key: 'key', value: '...')]` fills
  it, and the sibling's allowed values become exactly the values the
  variants fill. A new variant brings itself and the parent never
  changes.

The demo block's presentation is a slot its two presentation surfaces
fill; the field instance surface attaches its storage, and its settings
are a slot each field type's module fills, as are the storage's own
settings:

```php
// DemoBlockSurface
$inputs->add('presentation', 'string', t('Presentation'), default: 'list')
  ->setRequired(TRUE)
  ->addConstraint('Choice', ['choices' => ['list', 'grid']]);
$inputs->attachBy('presentation_settings', by: 'presentation')
  ->setLabel(t('Presentation settings'))
  ->setDescription(t('What the chosen presentation needs.'));

// ListPresentationSurface and GridPresentationSurface, beside it
#[Surface('block.data_surface_demo.presentation.list')]
#[SurfaceVariant(of: DemoBlockSurface::class, key: 'presentation_settings', value: 'list')]

#[Surface('block.data_surface_demo.presentation.grid')]
#[SurfaceVariant(of: DemoBlockSurface::class, key: 'presentation_settings', value: 'grid')]

// FieldInstanceSurface, in data_surface_tool
$inputs->attach('storage', FieldStorageSurface::class)
  ->setLabel(t('Field storage'));
$inputs->attachBy('settings', by: 'field_type')
  ->setLabel(t('Field settings'));

// FieldStorageSurface, in data_surface_tool
$inputs->attachBy('settings', by: 'field_type')
  ->setLabel(t('Storage settings'));

// AddressFieldSettingsSurface, in data_surface_address
#[Surface('field.settings.address', target: AddressFieldSettingsTarget::class)]
#[SurfaceVariant(of: FieldInstanceSurface::class, key: 'settings', value: 'address')]
```

`attach()` and `attachBy()` return the map definition at the key, a
core `MapDataDefinition`, the way `add()` returns its definition, so
the owner labels and describes its own subsurface key with the core
setters. `describe()` is for an alter rewording a key it does not own;
an owner calling it on its own key is saying in two statements what
one says.

What that means, end to end:

- **A child is built by the same build step**, after its parent's
  context is applied: its own shape, its own alters, its own refiners.
  It sees the context
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
  from the start, when the context locks the deciding key. That is an
  ordinary refinement under the rule that `any` may become anything
  narrower, not a mechanism of its own. The deciding
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
  names a target is loaded from and committed to it — an attached child
  before its parent, a slot's variant after — in its own context plus
  whatever identity the parent accepted that it did not already know; a
  child without one is stored by its parent under its key.
  `SurfacesInterface::target()` builds the composed target (see
  Targets, above).
- **Emission.** The tool bridge converts an attached child to a nested
  map input, and an unresolved slot to the widest honest map: every
  variant's keys, none required and none with a default, each
  annotated with the values it belongs to, the table in the
  description. The Tool API cannot say a union keyed by a sibling.

An alter cannot attach yet (its keys are mounted under its module), and
neither can an output; both are refused by name.

### Derived variants

A slot's deciding values may come from somewhere no surface class
answers for. Most field types declare no settings surface, and yet each
one's settings are described already, by its config schema. A service
tagged `data_surface.derived_variants` implementing
`SurfaceBuild\DerivedVariantsInterface` fills one open slot for the
values no `#[SurfaceVariant]` fills:

- `slot()` names the surface class and the slot's key;
- `source()` says, in one phrase, where the variants come from, for the
  catalogue;
- `variants(array $declared)` returns fresh core definitions keyed by
  key, keyed by deciding value, leaving out the values a declared
  variant already fills.

The build step seals each value's definitions into a child beside the
declared ones, and from there it is advertised, refined, accepted and
emitted the same way. A derived variant is a shape and nothing else: no
class, no alters, no refiners, no target and no access class, so it is
stored by its parent under the slot's key. A declared variant always
wins over a derived one for the same value.

`data_surface_tool` ships two. `FieldSettingsSchemaVariants` fills
`FieldInstanceSurface`'s `settings` slot from
`field.field_settings.<field type>` for every field type offered in the
UI, and `FieldStorageSettingsSchemaVariants`, the same class pointed at
another slot, fills `FieldStorageSurface`'s from
`field.storage_settings.<field type>`. So the field tools offer a plain
`string` or `integer` field as they offer an address field, with what
the schema says and nothing more: its keys, their types and labels, and
the field type's own default settings where their type fits. A string's
`max_length` is `storage.settings.max_length` when the field is added,
and `settings.max_length` on `data_surface:field.storage:edit`. The
catalogue lists the declared variants per slot, and says where the
derived ones come from.

### A child that cannot be listed

A slot covers a child chosen from a set of values someone can name. A
child whose shape cannot be enumerated at all is the one case for a
`#[RefinesInput]` method on an `any` key that returns the narrower
definition itself, a map included. No verb is needed for it: `any` may
become anything narrower, and the method is an ordinary refiner.

## Not built yet

Collections (`attachList()`, `attachListBy()`), input shapes, and
options sources with their alters are deferred; [the
pattern](pattern.md#deferred) records the position each is held to.
Until options sources exist, a live list is a constraint with an
options resolver.
