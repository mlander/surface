# Declaring a surface

A surface is built by a builder and sealed. Nothing else constructs one,
and once it is sealed every mutator on the builder throws. Where the
building happens is the only real choice: in the class's own
`declareDataSurface()`, or at runtime in `getDataSurface()`.

## One home, never split

The rule is short and it is the one to get right:

> A surface that can be written down without asking the site anything is
> declared entirely in `declareDataSurface()`. A surface that needs live
> site state to describe itself at all is built entirely in
> `getDataSurface()`. Never half of each.

A class whose contract is half in one method and half in another has no
single place to read it, which defeats the point of having one.

**The declaration** is worth reaching for, because it is static: several
host protocols ask a class for its defaults with no instance to ask. A
field formatter's `defaultSettings()` and a field type's
`defaultFieldSettings()` are static methods that have to answer with the
defaults of the very surface the instance advertises, and a static
declaration is what lets them, with no second copy of the defaults
anywhere. It is also what a deriver, a documentation page or an agent
reads when it wants a class's contract without booting a plugin.

Note that "static" is decided by runtime *dependence*, not by structure.
A constraint such as `PluginExists`, whose allowed values are resolved
live from a plugin manager, still has a static spelling, so a definition
carrying it belongs in the declaration. What forces a runtime build is a
surface whose definitions cannot be written down without calling a
service — a `LabeledChoice` whose list is assembled from the country
repository, say. When you find yourself in that position, read [Options
and resolvers](options.md): saying it as an existence constraint plus a
resolver is usually what turns a runtime surface back into a literal one.

### The declaration

`DataSurfaceDeclarationInterface` is one static method taking the
builder. Everything a surface says goes into it: definitions, defaults,
refinement edges, locks, map properties, and [outputs](outputs.md).

```php
#[Block(
  id: 'my_teaser',
  admin_label: new TranslatableMarkup('Teaser'),
)]
final class TeaserBlock extends DataSurfaceBlockBase {

  public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
    $headline = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Headline'))
      ->setDescription(new TranslatableMarkup('Shown above the items.'))
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 50]);
    DefinitionMetadata::setExamples($headline, ['Quarterly report']);
    $builder->setDefinition('headline', $headline);
    $builder->setDefault('headline', 'Featured content');

    $builder->setDefinition('entity_type', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Entity type'))
      ->setRequired(TRUE)
      ->addConstraint('PluginExists', [
        'manager' => 'entity_type.manager',
        'interface' => ContentEntityInterface::class,
      ]));

    $builder->setDefinition('bundle', DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Bundle')));
    // The edge sits beside the key it belongs to.
    $builder->addRefinement('bundle', ['entity_type']);
  }

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

Nothing else is needed. `DataSurfaceBlockBase::getDataSurface()` hands
this method a fresh builder with the plugin already bound as the refiner
and seals the result through the factory, so the block above has no
`defaultConfiguration()`, no `blockForm()`, no `blockValidate()` and no
`blockSubmit()`. See [Generated forms](forms.md) for the equivalent base
class or trait per host family.

The declaration may consult nothing but itself: no `$this`, no
container. That is the price of being readable from the class, and it is
the same price the static host protocols pay.

### At runtime

A host whose surface needs services builds it in `getDataSurface()` and
routes it through the factory, which is what dispatches the build event
and seals the result. Two helpers on `DataSurfaceHostTrait` are the whole
of the ceremony:

```php
public function getDataSurface(string $operation = 'configure', ?string $subject = NULL): DataSurfaceInterface {
  // This plugin is its own subject, so there is nothing a subject could
  // name and one is refused rather than ignored.
  $this->surfaceSelfSubject($subject);
  // A fresh builder, with this plugin already bound as its refiner and,
  // when it implements the interface, as its output refiner too.
  $builder = $this->surfaceBuilder();
  $builder->setDefinition('language', DataDefinition::create('string')
    ->setLabel(new TranslatableMarkup('Language'))
    ->addConstraint('LanguageExists', ['allowLocked' => FALSE]));
  $builder->setDefault('language', $this->languageManager->getDefaultLanguage()->getId());
  if ($operation === 'edit') {
    $builder->lock('id');
  }
  return $this->builtSurface($builder, 'block:' . $this->getPluginId());
}
```

A class that builds here answers its host's static protocols itself:
there is no declaration for `defaultSettings()` to read, and the shim
says so with an exception rather than returning a quietly empty array.

A surface that did not come from the factory was never offered to
subscribers, so nothing may assume it is complete. Build through the
factory even when you are sure nobody is listening.

The `$operation` argument is how one class serves more than one form: a
host that resolves a form class per operation asks for the surface by
name, and the same class can lock a key on `edit` that it leaves open on
`add`. The default is `configure`.

### History: the attribute

Until Phase B a class could declare the flat part of its surface in a
`#[DataSurfaceAware]` attribute instead, and the factory harvested it.
The attribute is gone, and the argument for removing it is worth keeping:
the static harvest it was built for had shrunk to detection, which the
provider interface already provides, and the sketch it held was a lie of
omission next to what the factory builds. Its costs were real —
array constructors only, so no fluent `DataDefinition::create()`; no map
property definitions, which is why the address field type needed a
before-seal callback; no translatable constants; and two homes for one
contract. A declaration in a method says all of it in one place, so
plugins and standalone providers now author identically.

### The operation and subject pair

`getDataSurface()` and `surfaceAccess()` take one coordinate in two
halves, and the same two halves in the same order everywhere:

- **`$operation`** is a closed verb from the host type's own
  vocabulary — `configure`, `add`, `edit`, `field_settings` — and it
  **never carries identity**. An operation that names the thing it acts
  on is a vocabulary nobody can enumerate, and a discovery document
  cannot list it.
- **`$subject`** is an **opaque string id the provider resolves for
  itself**. Nothing between the caller and the provider parses it: it is
  a content type machine name to one provider and a workflow state to
  the next.

`NULL` for the subject means **the provider is its own subject**, which
is the ordinary case: a block, a condition, an action, a formatter and a
field item each describe themselves, and there is nothing left to name.
Those hosts refuse any other subject by name —
`DataSurfaceHostTrait::surfaceSelfSubject()` is the one line that does
it — rather than serving the surface nobody asked for. A provider that
owns several subjects resolves the id itself and throws
`\InvalidArgumentException` naming one it cannot place.

An access question is never answered with an exception, so
`surfaceAccess()` **refuses** an operation or a subject it cannot place
instead of throwing; the neutral host default has no opinion about
either.

`data_surface_demo_node_type` is the worked example: `('add', NULL)`
builds the surface for a content type that does not exist yet and
`('edit', 'article')` the surface for one that does, and the provider's
`surfaceFor(?NodeTypeInterface)` stays beside them as the typed,
in-process convenience for a caller that already holds the entity.

**This pair is the wire coordinate**: the Phase B discovery route and
the dry-run endpoint address any surface by host type, host id,
operation and subject, which is why identity is not allowed to hide
inside the verb.

### Host ids

The factory takes a host id with every build, and it is namespaced
`<host type>:<id>` — `block:my_teaser`, `field_formatter:my_formatter`,
`field_type:address`, `entity_type:node_type`. The namespace is what
keeps two hosts of different kinds that happen to share a plugin id from
looking like one host to a subscriber. It is part of a host's public
surface, because subscribers match on it; a host whose kind core has no
name for picks one and keeps it.

## Class names

Two class-naming rules, so that a reader who has only a grep finds the
rest of the story.

**A standalone provider ends in `SurfaceProvider`.** A class that
implements `DataSurfaceProviderInterface` without being a host of its own
— it answers with a surface, a target and an access result for an
operation and subject — is named for what it provides:
`NodeTypeSurfaceProvider`. A host that carries its own declaration is
named for the host, not for the surface, because the surface is not the
thing it is.

**A class-swap adopter prefixes the swapped class with `Surface`, and
says so in the hook.** Adopting a class you do not own means subclassing
it and swapping the subclass in through an info alter — the address field
type is the shipped example, where
`\Drupal\address\Plugin\Field\FieldType\AddressItem` becomes
`SurfaceAddressItem`. The prefix makes the pair legible at a glance, and
the hook implementation's docblock **must name the replacement class**,
so that grepping for the original class name lands on the one line that
replaces it rather than on a `use` statement with no explanation.
`AddressSurfaceHooks::fieldInfoAlter()` is the pattern to copy.

Both rules exist for the same reason as the host id namespace: the
module's seams have to be findable from either end.

## Map properties, before seal

Core's `MapDataDefinition` takes only its own definition array in its
constructor and gains its property definitions through a setter.
`ListDataDefinition` has no such problem: its item definition is a
constructor argument.

The builder is where a map's properties are supplied, and the timing is
the whole point — **before seal**, so that subscribers and every later
consumer see one complete surface rather than one the host filled in
afterwards. That is one more line of the declaration:

```php
public static function declareDataSurface(DataSurfaceBuilderInterface $builder): void {
  $builder->setDefinition('field_overrides', MapDataDefinition::create()
    ->setLabel(new TranslatableMarkup('Field overrides')));
  $builder->setDefault('field_overrides', []);
  $builder->setPropertyDefinitions('field_overrides', static::fieldOverrideDefinitions());
}
```

`setPropertyDefinitions()` replaces properties of the same name and keeps
the rest; `setPropertyDefinition()` does one at a time. Both refuse a key
the builder does not hold, and a key whose definition takes no
properties. The address field type's item class is the worked example.

A list is constructed around its item definition rather than described
into one, so write `new ListDataDefinition(['type' => 'list'], $item)`
and describe the list fluently afterwards. Core's
`ListDataDefinition::create()` asks the typed data manager for the item,
and a declaration reaches for no service.

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
definitions are **optional by default**.

> **Requiredness appears only when it says something.** Write
> `setRequired(TRUE)` on the keys that must be configured, and write
> nothing at all on the keys that need not be. A `setRequired(FALSE)`
> repeats the default in every declaration, so it reads as a decision
> where there is none, and a reader scanning for the required keys has to
> read the falsy ones to rule them out.

This module's declarations follow that rule throughout, so a
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
