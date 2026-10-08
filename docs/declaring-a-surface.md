# Declaring a surface

A surface is declared in one place: a class in a module's
`src/Surface/` carrying `#[Surface]`. Discovery finds it the way core
finds a class in `src/Hook`, the build step (`data_surface.surfaces`)
builds it in a context and seals it, and nothing else constructs one. A
plugin whose configuration it is only names it, with `#[UsesSurface]`.

This page is the class and what it says. [Surfaces as
classes](surfaces.md) has situations, alters, subsurfaces, access,
targets and the tools generated from them.

## The class

```php
#[Surface('block.data_surface_demo')]
final class DemoBlockSurface implements SurfaceInterface {

  public function defineInputs(ShapeInterface $inputs): void {
    $headline = $inputs->add('headline', 'string', new TranslatableMarkup('Headline'), default: 'Featured content')
      ->setDescription(new TranslatableMarkup('Shown above the featured content.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 50]);
    DefinitionMetadata::setExamples($headline, ['Quarterly report']);
    $inputs->add('entity_type', 'string', new TranslatableMarkup('Entity type'), default: 'user')
      ->setRequired(TRUE)
      ->addConstraint('PluginExists', [
        'manager' => 'entity_type.manager',
        'interface' => ContentEntityInterface::class,
      ]);
    $inputs->add('bundle', 'string', new TranslatableMarkup('Bundle'));
    // ...
  }

  /**
   * The bundle must be one of the chosen entity type's bundles.
   */
  #[RefinesInput('bundle')]
  public function bundleOfEntityType(DataDefinitionInterface $bundle, string $entity_type): DataDefinitionInterface {
    return $bundle->addConstraint('EntityBundleExists', ['entityTypeId' => $entity_type]);
  }

}
```

That is `DemoBlockSurface` in `data_surface_demo`, shortened. Each part
of a surface has one home:

| What | Where |
| --- | --- |
| Its id, and which keys say which thing it is | `#[Surface('id', identity: [...])]` |
| Its keys: type, label, default, constraints | `defineInputs(ShapeInterface $inputs)` |
| What it emits | `defineOutputs()`, on `HasOutputsInterface`; see [Outputs](outputs.md) |
| A key whose allowed values depend on another key's value | a `#[RefinesInput('key')]` method |
| How much is already known: add, edit, reuse | `#[Situation]` static methods returning a `SurfaceContext` |
| Where its values are stored | `#[Surface(target:)]`, a `SurfaceTargetInterface` |
| What may refuse once the situation's permission allows | `#[Surface(access:)]`, a `SurfaceAccessInterface` |
| Its parts | `attach()` and `attachBy()` in `defineInputs()`, and `#[SurfaceVariant]` on each variant |

`FieldInstanceSurface` in `data_surface_tool` uses every row but
outputs, which `DemoFormatterSurface` declares; the test module's
`TestBlockSurface` uses two.

- **`defineInputs()` is a flat list.** No `if`, no loop, and it never
  mentions a sibling. A constraint written there is fully known with no
  values. Anything that reads another key's value is a `#[RefinesInput]`
  method.
- **`add()` takes name, type and label** and returns the core
  definition, so the rest is plain core API: `setRequired()`,
  `setDescription()`, `addConstraint()`, `setSetting()`.
  `addDefinition()` is the long form, for a map or a list.
- **A `#[RefinesInput]` method takes the key's definition first, then one
  parameter per sibling it watches**, matched by name, or listed as
  `watches:` when a renamed parameter should be refused rather than
  silently stop watching. Its signature is its dependency declaration.
  One rule per method, its docblock stating the rule.
- **The class has no constructor and holds no service.** That is what
  lets the build step make it with `new`, and what keeps a surface pure
  data. Targets, access classes and alters are autowired services and
  may hold services.

### Nothing live in the shape

A list of allowed values that depends on the site is a constraint whose
options resolver fetches it, never a list the surface assembles. "Live"
is decided by runtime dependence, not by structure: `PluginExists`,
whose values are resolved from a plugin manager, is still written down
as a literal constraint, and so is `EntityBundleExists`, handed the
entity type by a refiner. A refiner points; the resolver fetches. When
no core constraint names the list, a module adds one with its resolver,
as the demo does with `DataSurfaceDemoBundleField`; see [Options and
resolvers](options.md).

A shape that does depend on site state — a key offered only while a
module is installed, a label read from configuration — says so with
`ShapeInterface::addCacheableDependency()`, so a form rendered from it
is cached no longer than its shape holds.

## On a plugin

A plugin keeps rendering and names its surface:

```php
#[Block(
  id: 'data_surface_demo',
  admin_label: new TranslatableMarkup('Data surface demo'),
)]
#[UsesSurface(DemoBlockSurface::class)]
final class DataSurfaceDemoBlock extends DataSurfaceBlockBase {

  public function build(): array {
    $configuration = $this->getConfiguration();
    // ...
  }

}
```

Nothing else is needed. The block has no `defaultConfiguration()`, no
`blockForm()`, no `blockValidate()` and no `blockSubmit()`:
`DataSurfaceBlockBase` builds the surface the definition names, in the
host's context, and stores what the pipeline accepts in the block's
configuration. The same holds for a condition (`DataSurfaceConditionBase`,
writing `evaluate()` and `summary()`), an action (`DataSurfaceActionBase`,
writing `execute()` and `access()`) and a formatter
(`DataSurfaceFormatterBase`, writing `formatValue()`). Any configurable
plugin with no base class of this module's lists `DataSurfacePluginForm`
under its `forms` key. [Generated forms](forms.md) has the table.

A field type implements `FieldSurfaceProviderInterface` and uses
`DataSurfaceFieldTypeTrait`. Its static defaults are one line, because
`defaultFieldSettings()` is asked of the class:

```php
#[FieldType(id: 'data_surface_gated', /* ... */)]
#[UsesSurface(GatedFieldSettingsSurface::class)]
class SurfaceGatedItem extends StringItem implements FieldSurfaceProviderInterface {

  use DataSurfaceFieldTypeTrait;

  public static function defaultFieldSettings(): array {
    return static::surfaceDefaultFieldSettings(static::class) + parent::defaultFieldSettings();
  }

}
```

A field type whose storage shape differs from the shape a caller is
asked for overrides `getDataSurfaceTarget()` and hands the field
settings target a shape, as `SurfaceAddressItem` does.

What a host supplies, and why the surface does not:

- **The context.** A plugin instance is the whole of what it describes,
  so the host builds in `new SurfaceContext('configure')` — or
  `field_settings` for a field type: its own verb, which is no declared
  situation, and nothing known.
- **The target.** Only the host holds the instance: the plugin's
  configuration array, or the field config Field UI is editing. A
  formatter's settings are stored by the display, so its
  `getDataSurfaceTarget()` throws.
- **Static defaults.** `defaultSettings()` and `defaultFieldSettings()`
  read `#[UsesSurface]` off the class and take
  `SurfacesInterface::defaults()`: the surface's own shape alone, with
  no alter, context or refiner.

`#[UsesSurface]` is copied into the plugin definition, so the catalogue
lists which plugins use a surface without instantiating one, and a
surface any plugin uses is never generated as a tool of its own.

## Operations and identity

A context's **operation** is either a situation id — `add`, `edit`,
`reuse` — or a host's own verb — `configure`, `field_settings`. It
**never carries identity**. An operation that names the thing it acts
on is a vocabulary nobody can enumerate, and a discovery document cannot
list it.

Identity is the keys `#[Surface(identity:)]` names, and a context says
which of them it knows. An identity key the context knows is locked to
that value; one it does not know stays open. That is the whole
difference between add and edit, and it is why a situation, a route and
a tool address a surface the same way: by its id and a situation id,
`data_surface:node.type:edit`, with the situation's parameters saying
which content type.

## Names

Four naming rules, so that a reader who has only a grep finds the rest
of the story.

**A surface class ends in `Surface`, and its id is dotted.**
`NodeTypeSurface` is `node.type`, `FieldInstanceSurface` is
`field.instance`, `DemoBlockSurface` is `block.data_surface_demo`. An
alter ends in `Alter`, a target in `Target`, an access class in
`Access`.

**A class-swap adopter prefixes the swapped class with `Surface`, and
says so in the hook.** Adopting a plugin class you do not own means
subclassing it, naming the surface on the subclass, and swapping the
subclass in through an info alter — the address field type is the
shipped example, where `\Drupal\address\Plugin\Field\FieldType\AddressItem`
becomes `SurfaceAddressItem`. The prefix makes the pair legible at a
glance, and the hook implementation's docblock **must name the
replacement class**, so that grepping for the original class name lands
on the one line that replaces it rather than on a `use` statement with
no explanation. `AddressSurfaceHooks::fieldInfoAlter()` is the pattern
to copy.

**The catalogue names a plugin `<host type>:<plugin id>`** —
`block:data_surface_demo`, `field_formatter:data_surface_demo_string`,
`field_type:address`. The host type keeps two plugins of different
kinds that share an id apart.

**An operation is a verb.** See above.

## Translatable strings

Every human-facing string a surface carries is a translatable object,
never a concatenation of translated fragments. Which of the two forms
to use is decided by one question: is there an instance with a
container behind it?

**A surface class constructs it raw.** It has no constructor, so
nothing can inject the translation service, and `#[Situation]` methods
and attribute arguments are static. `new TranslatableMarkup('Headline')`
is the only spelling available. It resolves the translation service at
render time, which is correct here and costs nothing.

**Everything the container builds calls `$this->t()`.** An alter, a
target, an access class, a widget, a cosmetic layer: the trait is
available or can be, the spelling is shorter, and the sniffs read it. A
class we author that is built by the container and writes human-facing
strings injects `string_translation`, uses `StringTranslationTrait` and
calls `$this->t()`, so that translation never resolves through the
global container at render time from our own code. The two forms
render identically; what differs is where the service comes from.

The global `t()` function appears nowhere in object-oriented code.

So the split is between files, not inside one: `DemoFormatterSurface`
constructs raw markup, and `DemoFormatterAlter`, an alter of it, calls
`$this->t()`.

Two places keep raw construction with an instance in hand, and both say
why in a docblock: `DataSurfaceBuilder`, the engine under the build
step, is a value object made with `new`, so there is no constructor to
inject through, and `DemoVariant` is an enum, which cannot carry the
trait's property.

## Maps and lists

Core's `MapDataDefinition` takes only its own definition array in its
constructor and gains its property definitions through a setter. Set
them on the definition before handing it to the shape, with the core
setter:

```php
$overrides = MapDataDefinition::create()
  ->setLabel(new TranslatableMarkup('Field overrides'));
foreach (self::fieldOverrideDefinitions() as $field_name => $definition) {
  $overrides->setPropertyDefinition($field_name, $definition);
}
$inputs->addDefinition('field_overrides', $overrides, default: []);
```

`AddressFieldSettingsSurface` is the worked example. A map whose
properties are another surface's keys is a subsurface instead:
`attach()` or `attachBy()`.

A list is constructed around its item definition rather than described
into one, so write `new ListDataDefinition(['type' => 'list'], $item)`
and describe the list fluently afterwards. Core's
`ListDataDefinition::create()` asks the typed data manager for the item,
and a surface class reaches for no service.

## Defaults and examples

Core's data definitions carry neither a default value nor examples yet.
Both are written onto the definition here, under the definition array
keys the core draft proposes, through `DefinitionMetadata`:

| What | Definition array key | Written with |
| --- | --- | --- |
| Default value | `default_value` | the `default:` argument of `add()` and `addDefinition()`, or `DefinitionMetadata::setDefaultValue()` |
| Examples | `examples` | `DefinitionMetadata::setExamples()` |

Each accessor delegates to the definition's own core method when one
exists, so the class becomes a pass-through the day core lands its
version. Read them back with `hasDefaultValue()`, `getDefaultValue()`,
`defaultOf()` — which assembles a complex definition's default from its
property definitions — and `getExamples()`.

Passed to `add()` or `addDefinition()`, `NULL` declares no default,
which is what core definitions already say. Written with
`DefinitionMetadata::setDefaultValue()`, `NULL` is a declared default
and is distinct from declaring none. What consumers do with a declared
one: the surface's `getDefault()` and
`getDefaultValues()` read it, `accept()` merges it under the stored
values, and the string and number widgets render the first example as
`#placeholder`. A situation that creates may give starting values with
`withStarting()`, which become the key's defaults in that context.

Two consequences worth knowing before you declare one. A map's declared
default merges *over* the defaults its properties declare rather than
replacing them, and a list key absent from a payload keeps the stored
list rather than falling back to its default. Both rules, and why, are in
[Value semantics](semantics.md).

## Locking

A locked key's value is fixed. It is the degenerate refinement: the
value space narrowed to exactly one value. A surface class never locks a
key itself; an identity key the context knows is locked, to the value
the context knows, and stays locked whatever is submitted for it.

A locked key stays advertised. It renders disabled in a generated form,
and submitted input for it is ignored — not an error, just not a way to
move the value. `DataSurfaceInterface::isLocked()` answers it.

Locking is context rather than an intrinsic property of a definition,
which is why it lives on the surface rather than on the definition: the
same machine name key is locked in the edit situation and open in the
add situation, and one class declares both.

## Secrets

A key whose stored value must never be read back to whoever writes it —
an API token, a password, a signing key — is declared secret:

```php
$token = $inputs->add('token', 'string', new TranslatableMarkup('API key'), default: '')
  ->setDescription(new TranslatableMarkup('The key this field authenticates with.'));
DefinitionMetadata::setSecret($token);
```

That is the test module's `SecretFieldSettingsSurface`.

It is one flag on the definition, written the same way as defaults and
examples, and it is read in four places so that declaring it is all an
author does:

| Where | What the flag does |
| --- | --- |
| The generated form | A `password` element, with no `#default_value`, no `#placeholder`, no `#required`, and the note "Leave blank to keep the current value." The form builder does not hand the value to the widget at all, so no stored secret reaches the render array. |
| `accept()` | Input that holds nothing keeps the stored value instead of clearing it. See [Value semantics](semantics.md#secrets-keep-what-they-hold) for the rule and for how a caller clears one on purpose. |
| The storage shape | `EncryptedSettingsShape` encrypts the value on its way to storage and decrypts it on the way back. See [Targets](targets.md#secrets-at-the-codec). |
| The tool bridge | The converted input carries no default and says "Write only" in its description. |

A secret holds **one scalar value**. Marking a list or a map secret is
refused where it is declared, rather than half-supported downstream: the
keep rule and the codec both act on one value, and a map whose
properties are partly secret would be neither encrypted nor honestly
advertised. Declare the secret as a key of its own.

Secret and locked may coexist, and mean different things: locked says
input cannot move the value, secret says the value is never read back.

### What phase B will emit

Nothing emits a schema yet. When the schema emitter lands, two flags
already on the contract map onto JSON Schema keywords, and the mapping is
recorded here so it is not reinvented:

| Flag | Keyword |
| --- | --- |
| `secret` | `writeOnly` |
| locked | `readOnly` |

Until then the bridges say it in prose: a locked key carries "Fixed for
this operation." in its form description and the Tool API's own locked
flag, and a secret key carries the write-only note.

### What the flag does not do

It is worth being plain about the edges:

- **It is not access control.** A caller who may configure the surface
  may set the secret. Who may configure it is the situation's
  permission and the surface's access class.
- **It does not hide the key.** The key, its label and its constraints
  stay advertised, which is the point: a caller has to know the secret
  exists to send one.
- **It is not retroactive.** Values written before the key was declared
  secret are plaintext in storage until the next save of that thing.

## Required

`required` on a core `DataDefinition` is falsy unless you set it —
definitions are **optional by default**.

> **Requiredness appears only when it says something.** Write
> `setRequired(TRUE)` on the keys that must be configured, and write
> nothing at all on the keys that need not be. A `setRequired(FALSE)`
> repeats the default in every declaration, so it reads as a decision
> where there is none, and a reader scanning for the required keys has to
> read the falsy ones to rule them out.

This module's surfaces follow that rule throughout, so a
`setRequired()` anywhere in a surface is a `TRUE`. The one place the
opposite is written down is a definition that did not start optional: the
Tool API's `InputDefinition` defaults to *required*, so
`data_surface_tool` turns the flag off explicitly when it derives one
from config schema, and there the call is saying something.

What "required" means here is that the key must be *configured*, which is
a narrower claim than "non-empty": `FALSE`, `0` and `[]` all satisfy a
required key and are then held to its own constraints. Only `NULL` and
the empty string do not. The violation is always the surface's own
message, never a type-specific one from a constraint, so an empty
required number and an empty required string read alike to the person
filling in the form.

The full rules — what counts as configured, the casting table, shape
mismatches — are in [Value semantics](semantics.md). Read that page
before declaring a required key whose type is a list or a map.
