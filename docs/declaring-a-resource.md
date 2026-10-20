# Declaring a resource or structure

A resource or structure is declared in one place: a class in a module's
`src/Resource/` or `src/Structure/` carrying `#[Resource]` or
`#[Structure]`. The attribute decides the kind, not the directory.
Discovery finds the class the way core finds one in `src/Hook`, the
build step (`data_surface.resources`) builds it in a context and seals
it, and nothing ever constructs one: its declaration and its refiners are
static, asked of the class. A plugin whose configuration a structure is
only names it, with `#[UsesStructure]`, or is it, with `#[UsesStructure]`
and no argument ([On a plugin](#on-a-plugin)).

A resource is a thing with identity and storage that operations read
and write; a structure is a group of keys that something else
holds. Both are written the same way, and this page is the class and
what it says. [Resources and structures as classes](resources.md) has
operations, alters, children, access, storage and the tools generated
from them.

## The class

```php
#[Structure('block.data_surface_demo')]
final class DemoBlockStructure implements StructureInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    $headline = $inputs->add('headline', 'string', t('Headline'), default: 'Featured content')
      ->setDescription(t('Shown above the featured content.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 50]);
    DefinitionMetadata::setExamples($headline, ['Quarterly report']);
    $inputs->add('entity_type', 'string', t('Entity type'), default: 'user')
      ->setRequired(TRUE)
      ->addConstraint('PluginExists', [
        'manager' => 'entity_type.manager',
        'interface' => ContentEntityInterface::class,
      ]);
    $inputs->add('bundle', 'string', t('Bundle'));
    // ...
  }

  /**
   * The bundle must be one of the chosen entity type's bundles.
   */
  #[RefinesInput('bundle')]
  public static function bundleOfEntityType(DataDefinitionInterface $bundle, string $entity_type): DataDefinitionInterface {
    return $bundle->addConstraint('EntityBundleExists', ['entityTypeId' => $entity_type]);
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

That is `DemoBlockStructure` in `data_surface_demo`, shortened: a
structure, since the block that names it holds it and stores it. Each
part of a resource or structure has one home:

| What | Where |
| --- | --- |
| Its id, and which keys say which thing it is | `#[Resource('id', identity: [...])]`; a structure has an id and no identity, `#[Structure('id')]` |
| Its keys: type, label, default, constraints | `static defineInputs(BlueprintInterface $inputs)` |
| What it emits | `static defineOutputs()`, on `HasOutputsInterface`; see [Outputs](outputs.md) |
| A key whose allowed values depend on another key's value | a rule, `$inputs->narrow('key')->when(Condition::...)`, in `defineInputs()`; a static `#[RefinesInput('key')]` method where the effect must be computed ([Declaring a rule](refinement.md#declaring-a-rule)) |
| How much is already known: add, edit, reuse | `#[Operation]` static methods returning an `OperationContext` |
| Where its values are stored | `#[Resource(storage:)]`, a `ResourceStorageInterface` in `src/Storage/`; a structure is stored by what holds it |
| What may refuse once the operation's permission allows | `access:` on either attribute, an `OperationAccessInterface` in `src/Access/` |
| Its parts | `attach()` and `attachBy()` in `defineInputs()`, and `#[Variant]` on each variant |
| Which version of its stored values it describes | `version:` on either attribute, 1 until a breaking change is made after values are stored ([Evolving a resource](evolution.md#the-version)) |

`FieldInstanceResource` in `data_surface_tool` uses every row but the
version, which nothing raises yet, and outputs, which the demo formatter
declares; the test module's `TestBlockStructure`, a structure, uses two.

- **Everything on the class is static.** `defineInputs()` and
  `defineOutputs()` are static on the interface, and a `#[RefinesInput]`
  method on a resource or structure is refused unless it is static. What it
  declares is a property of the class: it takes no values, context or services, and
  static holds it to that by the language.
- **`defineInputs()` is a flat list.** No `if`, no loop, and it never
  mentions a sibling. A constraint written there is fully known with no
  values. Anything that reads another key's value is a rule declared
  with `narrow()`, which is data the build reads, or a `#[RefinesInput]`
  method.
- **`add()` takes name, type and label** and returns the core
  definition, so the rest is plain core API: `setRequired()`,
  `setDescription()`, `addConstraint()`, `setSetting()`. The label is
  the one every door shows: the form element's title, the tool's
  property label, the schema's `title`; the description is the text
  under the element and the property's description.
  `addDefinition()` is the long form, for a map or a list. The label is
  `t('...')` with a literal string; see [Translatable
  strings](#translatable-strings). The type may be a shape's class, a
  value declared once for every key that asks for it ([Named
  shapes](#named-shapes)).
- **A `#[RefinesInput]` method takes the key's definition first, then one
  parameter per sibling it watches**, matched by name, or listed as
  `watches:` when a renamed parameter should be refused rather than
  silently stop watching. Its signature is its dependency declaration.
  One rule per method, its docblock stating the rule.
- **The class has no constructor and holds no service**, and is never
  instantiated: the build step calls its static methods on the class,
  and what rides along in a cached form is the class name. Storage
  classes, access classes and alters are autowired services and may hold
  services; an alter's refiners are static methods too, unless they
  declare `runtime: TRUE`, the one kind an instance method may be.

### Named shapes

A value asked for at more than one key, with the same type, the same
pattern and the same words for it, is declared once as a **shape**: a
class in the module's `src/Shape/` carrying `#[Shape]`, whose static
`define()` returns a plain core definition. The examples' event licence:

```php
#[Shape('event.licence')]
final class Licence implements ShapeInterface {

  public static function define(): DataDefinition {
    $pattern = ['pattern' => '/^\d{4}$/D', 'message' => t('A licence number is four digits, such as 2048.')];
    $licence = DataDefinition::create('string')
      ->setLabel(t('Event licence'))
      ->setDescription(t('The four digits after EV- on the licence, such as 2048. Required to host more than 100 people.'))
      ->addConstraint('Regex', $pattern);
    DefinitionMetadata::setExamples($licence, ['2048']);
    return $licence;
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

A constraint's message is a sentence a person reads, so a translator
should find it beside every label: a message option may be written with
`t()`, as the licence's is; the build hands Symfony its source string,
which core's validator translates when it refuses a value ([Violation
messages are objects](semantics.md#violation-messages-are-objects)).

A key uses it in the type position of `add()`, with a label of its own:

```php
$inputs->add('licence', Licence::class, t('Event licence'));
```

- **The key starts from the shape and may only narrow it.** What `add()`
  returns is a copy of the shape's definition, so the key adds what any
  key adds with the core setters: `setRequired()`, a `default:`, a
  constraint, a description of its own. When its owner is built, the
  key is held to the shape by the check a refiner's answer is held to;
  a key that widens it (a higher maximum, a second pattern) is refused,
  naming the key and the shape. Core's `addConstraint()` replaces a
  constraint of the same name, so a key that tightens a bound the shape
  already has restates the bounds it keeps:
  `addConstraint('Length', ['min' => 4, 'max' => 12])` over a shape
  allowing twelve.
- **A shape is a value.** `define()` returns one scalar type, never a
  map or a list, which is a structure, and declares no default and no
  required flag, which are each key's. A shape handed to `attach()`,
  `attachList()` or `with()` is refused.
- **A port may read one.** `$inputs->reads('code', Sku::class)` reads a
  value of the shape's type, and a container may wire it only to a key
  declared with that shape ([Ports](resources.md#ports)).
- **An alter of the shape tightens it at every key.** An alter naming
  the shape, `#[Alters(Licence::class)]`, is handed the shape itself in
  `alterInputs()`, named by its id: `narrow('event.licence')` with no
  condition and its bounds, a list's subset or a pattern where it has
  none, and `describe('event.licence', ...)`. It mounts no key and
  attaches nothing.
- **The contract writes it once.** Each shape is one def in both
  documents, `$defs.event.licence`, and each key using it refers to it,
  saying beside the reference only what the key says differently
  ([The served contract](served-contract.md#a-shape-is-one-def)).

The test module's stock code, `Shape\Sku`, is used at two keys of the
depot that narrow it differently, read by the shelf tag's port, and
tightened by `Alter\SkuAlter`. Why a shape is a named definition rather
than a data type plugin is in
[Decisions](decisions.md#a-shape-is-a-named-definition).

### Nothing live in the blueprint

A list of allowed values that depends on the site is a constraint whose
options resolver fetches it, never a list the class assembles. "Live"
is decided by runtime dependence, not by structure: `PluginExists`,
whose values are resolved from a plugin manager, is still written down
as a literal constraint, and so is `EntityBundleExists`, handed the
entity type by a refiner. A refiner points; the resolver fetches. When
no core constraint names the list, a module adds one with its resolver,
as the demo does with `DataSurfaceDemoBundleField`; see [Options and
resolvers](options.md).

A blueprint whose keys do depend on site state (a key offered only while a
module is installed, a label read from configuration) says so with
`BlueprintInterface::addCacheableDependency()`, so a form rendered from it
is cached no longer than those keys hold.

## On a plugin

A plugin keeps rendering and names the structure its configuration is,
a class of its own:

```php
#[Block(
  id: 'data_surface_demo',
  admin_label: new TranslatableMarkup('Data surface demo'),
)]
#[UsesStructure(DemoBlockStructure::class)]
final class DataSurfaceDemoBlock extends DataSurfaceBlockBase {

  public function build(): array {
    $configuration = $this->getConfiguration();
    // ...
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Nothing else is needed. The block has no `defaultConfiguration()`, no
`blockForm()`, no `blockValidate()` and no `blockSubmit()`:
`DataSurfaceBlockBase` builds the structure the definition names, in the
host's context, and stores what the pipeline accepts in the block's
configuration. The same holds for a condition (`DataSurfaceConditionBase`,
writing `evaluate()` and `summary()`), an action (`DataSurfaceActionBase`,
writing `execute()` and `access()`) and a formatter
(`DataSurfaceFormatterBase`, writing `formatValue()`). Any configurable
plugin with no base class of this module's lists `DataSurfacePluginForm`
under its `forms` key. [Generated forms](forms.md) has the table.

Or the plugin is its own structure: `#[UsesStructure]` with no
argument, and the plugin class implements `StructureInterface` with the
static methods a structure class has, beside the plugin's own code:

```php
#[FieldFormatter(
  id: 'data_surface_demo_string',
  label: new TranslatableMarkup('Data surface demo formatter'),
  field_types: ['string'],
)]
#[UsesStructure]
final class DataSurfaceDemoFormatter extends DataSurfaceFormatterBase implements StructureInterface, HasOutputsInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('prefix', 'string', t('Prefix'))
      ->setDescription(t('Text placed before each value.'))
      ->addConstraint('Length', ['max' => 10]);
    // casing, variant
  }

  // defineOutputs(), variantsOfCasing(): static as well.

  public function formatValue(FieldItemInterface $item, array $settings): array {
    // ...
  }

}
```

Discovery finds it through the plugin definition, not a directory. Its
id is `<host type>:<plugin id>`, `field_formatter:data_surface_demo_string`,
unless the class also carries `#[Structure]`; the catalogue lists it as
used by that same plugin; an alter names it by the plugin class. The
demo module keeps both spellings side by side: its block names
`DemoBlockStructure`, its formatter is its own. A separate class reads
better for a large structure, one with children or operations, or one
more than one plugin shares; the plugin's own reads better for a small
plugin whose settings are only ever its own, because the settings sit
beside the code that reads them.

A field type implements `FieldSurfaceProviderInterface` and uses
`DataSurfaceFieldTypeTrait`. Its static defaults are one line, because
`defaultFieldSettings()` is asked of the class:

```php
#[FieldType(id: 'data_surface_gated', /* ... */)]
#[UsesStructure(GatedFieldSettingsStructure::class)]
class SurfaceGatedItem extends StringItem implements FieldSurfaceProviderInterface {

  use DataSurfaceFieldTypeTrait;

  public static function defaultFieldSettings(): array {
    return static::surfaceDefaultFieldSettings(static::class) + parent::defaultFieldSettings();
  }

}
```

A field type whose storage layout differs from how a caller is asked
for its settings overrides `getDataSurfaceStorage()` and hands
`FieldSettingsStorage` a layout, as `SurfaceAddressItem` does.

What a host supplies, and why the structure does not:

- **The context.** A plugin instance is the whole of what it describes,
  so the host builds in `new OperationContext('configure')` (or
  `field_settings` for a field type): its own verb, which is no declared
  operation, and nothing known.
- **The storage.** Only the host holds the instance: the plugin's
  configuration array, or the field config Field UI is editing. A
  formatter's settings are stored by the display, so its
  `getDataSurfaceStorage()` throws.
- **Static defaults.** `defaultSettings()` and `defaultFieldSettings()`
  read `#[UsesStructure]` off the class and take
  `ResourcesInterface::defaults()`: the structure's own declaration alone,
  with no alter, context or refiner.

`#[UsesStructure]` is copied into the plugin definition, so the catalogue
lists which plugins use a structure without instantiating one, and
whatever a plugin uses is never generated as a tool of its own.

## Operations and identity

A context's **id** is either an operation id (`add`, `edit`, `reuse`)
or a host's own verb (`configure`, `field_settings`). It **never
carries identity**. An id that names the thing it acts on is a
vocabulary nobody can enumerate, and a discovery document cannot
list it.

Identity is the keys `#[Resource(identity:)]` names, and a context says
which of them it knows. An identity key the context knows is locked to
that value; one it does not know stays open. That is the whole
difference between add and edit, and it is why an operation, a route and
a tool address a resource the same way: by its id and an operation id,
`data_surface:node.type:edit`, with the operation's parameters saying
which content type.

## Names

Four naming rules, so that a reader who has only a grep finds the rest
of the story.

**A resource class ends in `Resource`, a structure class in
`Structure`, and the id is dotted.** `NodeTypeResource` is `node.type`,
`FieldInstanceResource` is `field.instance`, `DemoBlockStructure` is
`block.data_surface_demo`. A plugin that is its own structure keeps its
plugin's class name, and its id is its plugin's,
`<host type>:<plugin id>`, unless it carries `#[Structure]`. An alter
ends in `Alter`, a storage class in `Storage`, an access class in
`Access`, and a deriver is named for what it makes, in the plural
(`ContentEntityResources`, `FieldItemStructures`). A shape class is
named for the value, with no suffix, as a data type is (`Licence` is
`event.licence`).

**A class-swap adopter prefixes the swapped class with `Surface`, and
says so in the hook.** Adopting a plugin class you do not own means
subclassing it, naming the structure on the subclass, and swapping the
subclass in through an info alter. The address field type is the
shipped example, where `\Drupal\address\Plugin\Field\FieldType\AddressItem`
becomes `SurfaceAddressItem`. The prefix makes the pair legible at a
glance, and the hook implementation's docblock **must name the
replacement class**, so that grepping for the original class name lands
on the one line that replaces it rather than on a `use` statement with
no explanation. `AddressResourceHooks::fieldInfoAlter()` is the pattern
to copy.

**The catalogue names a plugin `<host type>:<plugin id>`**:
`block:data_surface_demo`, `field_formatter:data_surface_demo_string`,
`field_type:address`. The host type keeps two plugins of different
kinds that share an id apart.

**An operation is a verb.** See above.

## Translatable strings

Every human-facing string a class carries is a translatable object,
never a concatenation of translated fragments. Which spelling to use is
decided by one question: is there an instance a translation service
could have been injected into?

**Static context calls the global `t()`.** A resource's or structure's
declaration, its refiners and its operations are static, and so are a
plugin's static protocol methods; an enum has no properties. Nothing can
be injected there, so `t('Headline')` is the spelling. It returns the
same lazy `TranslatableMarkup` that `new TranslatableMarkup('Headline')`
would, resolves the translation service at render time exactly as late,
and is what string extraction looks for. The string is a literal, which
`Drupal.Semantics.FunctionT` holds it to; values go in placeholders.

**Attribute arguments construct `new TranslatableMarkup`.** A function
call is not allowed in an attribute argument, so `#[Operation(label:)]`
and a plugin attribute's label are the one place the constructor
stays.

**Everything the container builds calls `$this->t()`.** An alter, a
storage class, an access class, a widget, a cosmetic layer, a deriver: a class
we author that is built by the container and writes human-facing
strings injects `string_translation`, uses `StringTranslationTrait` and
calls `$this->t()`, so that translation never resolves through the
global container from our own code. A plugin whose base class already
carries the trait, a condition's `summary()`, calls `$this->t()` too.
An alter's static refiner is a static method, so it calls `t()`, or
constructs `PluralTranslatableMarkup` for a plural, as a resource's
does.

So the split is by context, and can fall inside one file: the demo
formatter's static `defineInputs()` calls `t()`, its plugin attribute
constructs `new TranslatableMarkup`, and `DemoFormatterAlter`, an alter
of it, calls `$this->t()` in `alterInputs()`; the compliance alter of
example 3 calls `$this->t()` there and `t()` in its static refiners. `DataSurfaceBuilder`, the engine under the
build step, is a value object made with `new`, so its one labeling
method is static and calls `t()`.

## Maps and lists

Core's `MapDataDefinition` takes only its own definition array in its
constructor and gains its property definitions through a setter. Set
them on the definition before handing it to the blueprint, with the core
setter:

```php
$overrides = MapDataDefinition::create()
  ->setLabel(t('Field overrides'));
foreach (self::fieldOverrideDefinitions() as $field_name => $definition) {
  $overrides->setPropertyDefinition($field_name, $definition);
}
$inputs->addDefinition('field_overrides', $overrides, default: []);
```

`AddressFieldSettingsResource` is the worked example. A map whose
properties are another class's keys is a child instead: `attach()` or
`attachBy()`.

A list is constructed around its item definition rather than described
into one, so write `new ListDataDefinition(['type' => 'list'], $item)`
and describe the list fluently afterwards. Core's
`ListDataDefinition::create()` asks the typed data manager for the item,
and a resource or structure class reaches for no service.

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
`defaultOf()` (which assembles a complex definition's default from its
property definitions) and `getExamples()`.

Passed to `add()` or `addDefinition()`, `NULL` declares no default,
which is what core definitions already say. Written with
`DefinitionMetadata::setDefaultValue()`, `NULL` is a declared default
and is distinct from declaring none. What consumers do with a declared
one: the data surface's `getDefault()` and
`getDefaultValues()` read it, `accept()` merges it under the stored
values, and the string and number widgets render the first example as
`#placeholder`. An operation that creates may give starting values with
`withStarting()`, which become the key's defaults in that context.

Two consequences worth knowing before you declare one. A map's declared
default merges *over* the defaults its properties declare rather than
replacing them, and a list key absent from a payload keeps the stored
list rather than falling back to its default. Both rules, and why, are in
[Value semantics](semantics.md).

## Locking

A locked key's value is fixed. It is the degenerate refinement: the
value space narrowed to exactly one value. A class never locks a key
itself; an identity key the context knows is locked, to the value
the context knows, and stays locked whatever is submitted for it.

A locked key stays advertised. It renders disabled in a generated form,
and submitted input for it is ignored: not an error, just not a way to
move the value. `DataSurfaceInterface::isLocked()` answers it.

Locking is context rather than an intrinsic property of a definition,
which is why it lives on the data surface rather than on the
definition: the same machine name key is locked in the edit operation
and open in the add operation, and one class declares both.

## Secrets

A key whose stored value must never be read back to whoever writes it
(an API token, a password, a signing key) is declared secret:

```php
$token = $inputs->add('token', 'string', t('API key'), default: '')
  ->setDescription(t('The key this field authenticates with.'));
DefinitionMetadata::setSecret($token);
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

That is the test module's `SecretFieldSettingsStructure`, a structure
the secret field type names.

It is one flag on the definition, written the same way as defaults and
examples, and it is read in four places so that declaring it is all an
author does:

| Where | What the flag does |
| --- | --- |
| The generated form | A `password` element, with no `#default_value`, no `#placeholder`, no `#required`, and the note "Leave blank to keep the current value." The form builder does not hand the value to the widget at all, so no stored secret reaches the render array. |
| `accept()` | Input that holds nothing keeps the stored value, required or not. See [Value semantics](semantics.md#secrets-keep-what-they-hold) for the rule and for how a caller clears one on purpose. |
| The storage layout | `EncryptedSettingsLayout` encrypts the value on its way to storage and decrypts it on the way back. See [Storage](storage.md#secrets-at-the-codec). |
| The tool bridge | The converted input is optional, carries no default and says "Write only" in its description. |

A secret holds **one scalar value**. Marking a list or a map secret is
refused where it is declared, rather than half-supported downstream: the
keep rule and the codec both act on one value, and a map whose
properties are partly secret would be neither encrypted nor honestly
advertised. Declare the secret as a key of its own.

Secret and locked may coexist, and mean different things: locked says
input cannot move the value, secret says the value is never read back.

### What the schema says

The served contract ([The served contract](served-contract.md)) states
both flags with JSON Schema's own keywords:

| Flag | Keyword |
| --- | --- |
| `secret` | `writeOnly: true`, never in `required`, no default |
| locked | `readOnly: true`, with `x-surface.locked` |

The other doors say it in their own terms: a locked key carries "Fixed
for this operation." in its form description and the Tool API's own
locked flag, and a secret key carries the write-only note.

### What the flag does not do

It is worth being plain about the edges:

- **It is not access control.** A caller who may configure the
  structure may set the secret. Who may configure it is the operation's
  permission and the access class.
- **It does not hide the key.** The key, its label and its constraints
  stay advertised, which is the point: a caller has to know the secret
  exists to send one.
- **It is not retroactive.** Values written before the key was declared
  secret are plaintext in storage until the next save of that thing.

## Required

`required` on a core `DataDefinition` is falsy unless you set it:
definitions are **optional by default**.

> **Requiredness appears only when it says something.** Write
> `setRequired(TRUE)` on the keys that must be configured, and write
> nothing at all on the keys that need not be. A `setRequired(FALSE)`
> repeats the default in every declaration, so it reads as a decision
> where there is none, and a reader scanning for the required keys has to
> read the falsy ones to rule them out.

This module's resources and structures follow that rule throughout, so
a `setRequired()` anywhere in one is a `TRUE`. The one place the
opposite is written down is a definition that did not start optional: the
Tool API's `InputDefinition` defaults to *required*, so
`data_surface_tool` turns the flag off explicitly when it derives one
from config schema, and there the call is saying something.

What "required" means here is that the key must be *configured*, which is
a narrower claim than "non-empty": `FALSE`, `0` and `[]` all satisfy a
required key and are then held to its own constraints. Only `NULL` and
the empty string do not. The violation is always the data surface's
own message, never a type-specific one from a constraint, so an empty
required number and an empty required string read alike to the person
filling in the form.

The full rules (what counts as configured, the coercion table, shape
mismatches) are in [Value semantics](semantics.md). Read that page
before declaring a required key whose type is a list or a map.
