# Declaring a surface

A surface is built by a builder and sealed. Nothing else constructs one,
and once it is sealed every mutator on the builder throws. Where the
building happens is the only real choice: in a `#[DataSurfaceAware]`
attribute on the class, or at runtime in `getDataSurface()`.

## One home, never split

The rule is short and it is the one to get right:

> A surface that can be written down as a literal is declared entirely in
> the attribute. A surface that needs live site state to describe itself
> at all is built entirely at runtime. Never half of each.

A class whose contract is half in an attribute and half in a method has
no single place to read it, which defeats the point of having one.

**The attribute** is worth reaching for, because a fully
attribute-declared surface is harvestable without instantiating anything:
`DataSurfaceAwareness` reads the definitions, the locks and the
dependency graph from the class alone, which is what a deriver,
documentation, or an agent enumerating configurable things needs.

Note that "static" is decided by runtime *dependence*, not by structure.
A constraint such as `PluginExists`, whose allowed values are resolved
live from a plugin manager, still has a static spelling, so a definition
carrying it belongs in the attribute. What forces a runtime build is a
surface whose definitions cannot be written down without calling a
service — a `LabeledChoice` whose list is assembled from the country
repository, say. When you find yourself in that position, read [Options
and resolvers](options.md): saying it as an existence constraint plus a
resolver is usually what turns a runtime surface back into a literal one.

### The attribute

PHP's new-in-initializers covers attribute arguments, so complete data
definitions live there. Static calls are not allowed in an attribute
argument, which rules out the fluent `DataDefinition::create(...)`
spelling; the constructor takes the definition array instead, and every
key a surface reads fits in it.

```php
#[Block(
  id: 'my_teaser',
  admin_label: new TranslatableMarkup('Teaser'),
)]
#[DataSurfaceAware(
  definitions: [
    'headline' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Headline'),
      'description' => new TranslatableMarkup('Shown above the items.'),
      'required' => TRUE,
      'constraints' => ['Length' => ['max' => 50]],
      'default_value' => 'Featured content',
      'examples' => ['Quarterly report'],
    ]),
    'entity_type' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Entity type'),
      'required' => TRUE,
      'constraints' => [
        'PluginExists' => [
          'manager' => 'entity_type.manager',
          'interface' => ContentEntityInterface::class,
        ],
      ],
    ]),
    'bundle' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Bundle'),
      'required' => FALSE,
    ]),
  ],
  refinements: ['bundle' => ['entity_type']],
)]
final class TeaserBlock extends DataSurfaceBlockBase {

  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if ($name === 'bundle') {
      $definition->addConstraint('EntityBundleExists', ['entityTypeId' => $values['entity_type']]);
    }
    return $definition;
  }

  public function build(): array {
    // $this->configuration holds the accepted values.
  }

}
```

The attribute takes five arguments: `definitions` keyed by surface key,
`refinements` as a map of target key to the sibling keys it is refined
against, `locked` as a list of keys whose value is fixed, and — for a
host that says what it emits as well as what it takes — `outputs` and
`output_refinements`, which are [Outputs](outputs.md).

Nothing else is needed. `DataSurfaceBlockBase::getDataSurface()` already
routes the class through `DataSurfaceFactoryInterface::buildFromClass()`,
passing the plugin itself as the refiner, so the block above has no
`defaultConfiguration()`, no `blockForm()`, no `blockValidate()` and no
`blockSubmit()`. See [Generated forms](forms.md) for the equivalent base
class or trait per host family.

### At runtime

A host whose surface needs services builds it in `getDataSurface()` and
routes it through the factory, which is what dispatches the build event
and seals the result:

```php
public function getDataSurface(string $operation = 'configure'): DataSurfaceInterface {
  $builder = new DataSurfaceBuilder();
  $builder->setDefinition('language', DataDefinition::create('string')
    ->setLabel(new TranslatableMarkup('Language'))
    ->addConstraint('LanguageExists', ['allowLocked' => FALSE]));
  $builder->setDefault('language', $this->languageManager->getDefaultLanguage()->getId());
  if ($operation === 'edit') {
    $builder->lock('id');
  }
  return $this->surfaceFactory()->build($builder, static::class, 'block:' . $this->getPluginId());
}
```

A surface that did not come from the factory was never offered to
subscribers, so nothing may assume it is complete. Build through the
factory even when you are sure nobody is listening.

The `$operation` argument is how one class serves more than one form: a
host that resolves a form class per operation asks for the surface by
name, and the same class can lock a key on `edit` that it leaves open on
`add`. The default is `configure`.

### Host ids

Both factory methods take a host id, and it is namespaced
`<host type>:<id>` — `block:my_teaser`, `field_formatter:my_formatter`,
`field_type:address`, `entity_type:node_type`. The namespace is what
keeps two hosts of different kinds that happen to share a plugin id from
looking like one host to a subscriber. It is part of a host's public
surface, because subscribers match on it; a host whose kind core has no
name for picks one and keeps it.

## Map properties, before seal

Core's `MapDataDefinition` takes only its own definition array in its
constructor and gains its property definitions through a setter, so a map
declared in the attribute arrives with no properties at all.
`ListDataDefinition` has no such problem: its item definition is a
constructor argument.

The builder is where a map's properties are supplied, and the timing is
the whole point — **before seal**, so that subscribers and every later
consumer see one complete surface rather than one the host filled in
afterwards. `buildFromClass()` takes a callback for exactly this:

```php
public function getFieldSurface(): DataSurfaceInterface {
  return $this->surfaceFactory()->buildFromClass(
    static::class,
    NULL,
    'field_type:my_type',
    static function (DataSurfaceBuilderInterface $builder): void {
      $builder->setPropertyDefinitions('field_overrides', $properties);
    },
  );
}
```

`setPropertyDefinitions()` replaces properties of the same name and keeps
the rest; `setPropertyDefinition()` does one at a time. Both refuse a key
the builder does not hold, and a key whose definition takes no
properties. The address field type's item class is the worked example.

## Defaults and examples

Core's data definitions carry neither a default value nor examples yet.
Both are written onto the definition here, under the definition array
keys the core draft proposes, through `DefinitionMetadata`:

| What | Definition array key | Written with |
| --- | --- | --- |
| Default value | `default_value` | `DefinitionMetadata::setDefaultValue()`, or `DataSurfaceBuilderInterface::setDefault()` as sugar |
| Examples | `examples` | `DefinitionMetadata::setExamples()` |

Each accessor delegates to the definition's own core method when one
exists, so the class becomes a pass-through the day core lands its
version. Read them back with `hasDefaultValue()`, `getDefaultValue()`,
`defaultOf()` — which assembles a complex definition's default from its
property definitions — and `getExamples()`.

`NULL` is a declared default and is distinct from declaring none. What
consumers do with each: the surface's `getDefault()` and
`getDefaultValues()` read them, `accept()` merges them under the stored
values, and the string and number widgets render the first example as
`#placeholder`.

Two consequences worth knowing before you declare one. A map's declared
default merges *over* the defaults its properties declare rather than
replacing them, and a list key absent from a payload keeps the stored
list rather than falling back to its default. Both rules, and why, are in
[Value semantics](semantics.md).

## Locking

`DataSurfaceBuilderInterface::lock()` fixes a key's value. It is the
degenerate refinement: the value space narrowed to exactly one value.
That value is **whatever storage already holds**, and the declared
default only for a key storage has never held.

A locked key stays advertised. It renders disabled in a generated form,
and submitted input for it is ignored — not an error, just not a way to
move the value. This is why editing an entity whose id is locked does not
reset it to the default.

Locking is build-time context rather than an intrinsic property of a
definition, which is why it lives on the surface rather than on the
definition: the same machine name key is locked on the edit operation and
open on the add operation, and one class declares both.

## Secrets

A key whose stored value must never be read back to whoever writes it —
an API token, a password, a signing key — is declared secret:

```php
$key = DataDefinition::create('string')
  ->setLabel(new TranslatableMarkup('API key'))
  ->setRequired(TRUE);
DefinitionMetadata::setSecret($key);
```

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
  may set the secret. Who may configure it is `surfaceAccess()`.
- **It does not hide the key.** The key, its label and its constraints
  stay advertised, which is the point: a caller has to know the secret
  exists to send one.
- **It is not retroactive.** Values written before the key was declared
  secret are plaintext in storage until the next save of that thing.

## Required

`required` on a core `DataDefinition` is falsy unless you set it —
definitions are **optional by default**. That differs from the input
world (the Tool API's `InputDefinition` defaults to required), so say
which you mean rather than relying on the default.

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
