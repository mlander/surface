# Storage

A resource says what a thing accepts. Its storage says where that thing
is kept. Keeping them apart is what lets one resource describe a node
type whether a form, a config action, a test or an agent is doing the
submitting, and what lets one storage class serve both the operation
that adds a thing and the operation that edits it.

This page is about the second half: a resource's own storage and the
engine's seven storage classes, the prepare-versus-commit split, dry runs,
what may ride on a form, and the one small interface storage uses when
the input layout and the storage layout differ. [The pipeline](pipeline.md)
is the other half, and the stage names below are its.

A settings page needs none of this page but the next section: its
resource names a subclass of `ConfigObjectStorage` and is done ([A
simple config object](#a-simple-config-object), and [Your first
resource](first-resource.md) for the whole module).

## A simple config object

The most common resource is a settings page, kept in one simple config
object. The engine's `ConfigObjectStorage` stores it; a module names its
object on a subclass, in its `src/Storage/`, and the resource names the
subclass:

```php
final class NewsletterStorage extends ConfigObjectStorage {

  public const CONFIG = 'data_surface_first.newsletter';

}
```

```php
#[Resource('first.newsletter', storage: NewsletterStorage::class)]
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

The subclass is autowired as a service when the resource is discovered,
so it is the whole of the storage code. What it does:

- **`load()`** reads the object, less `_core` and `langcode`, which are
  the storage's.
- **`prepare()`** lays the values over what is stored and validates the
  *whole* object against its config schema, so a type that resolves
  through a sibling (`[%parent.pricing]`), or a translatable string that
  needs a language code, is judged as it will be stored. Violations are
  filed under the keys being written; one under a key nobody writes is
  not this write's problem ([below](#config-schema-and-whose-violation-it-is)).
  It writes nothing, and a dry run reports what it returns: the values,
  plus the language code when one is stored.
- **`commit()`** writes what `prepare()` returned.
- **The language code** follows core's rule: present exactly when the
  schema holds a translatable value (`label`, `text`). The storage keeps
  the stored one, or the site's default language when none is stored,
  and removes one the schema does not ask for. A resource never declares
  `langcode` or `_core`.
- **The object must exist**, from the module's `config/install`, and a
  config schema must describe it: a missing schema is a `\LogicException`
  naming the object, since the storage exists to hold values to it.
- **`mayClear()`** says which keys the object may hold nothing for, by
  core's own rule: any key, unless the schema is fully validatable
  (`constraints: {FullyValidatable: ~}` at its root), where only a key
  that says `nullable: true` may. A key that may not is always held: a
  tool's null for it is refused naming its default, both schemas leave
  null out of its type, and its select offers no `- None -` once a
  valid choice is selected
  ([decision](decisions.md#a-key-its-storage-always-holds)). So
  `nullable: true` matters only in a fully validatable schema, on each
  optional key that may be emptied; elsewhere it changes nothing. The
  test module's opening hours (`resource_test.opening_hours`) are such
  an object ([below](#a-fully-validatable-schema)).

### A fully validatable schema

`FullyValidatable` is core's opt-in to strict config validation, and
the one place `nullable:` means anything. What it asks of the author of
the schema:

- **The constraint at the root**, `constraints: {FullyValidatable: ~}`
  on the object's own entry. A part whose type is named by its data,
  such as `third_party_settings` typed `<owner>.third_party.[%key]`,
  answers to the type it resolves to: each mounting module's mapping
  says `FullyValidatable` too, or its keys are held as before.
- **A type on every key**, as any schema needs: config validation lets
  data no schema describes through without a word.
- **Every key stored.** A key the object leaves out is refused ("'notes'
  is a required key.") unless it says `requiredKey: false`, so the
  module's `config/install` carries every key, an empty one as `~`,
  `''` or `[]`.
- **`nullable: true` on each key that may hold nothing**, and only
  there: null anywhere else is refused, by core and by the resource,
  which holds such a key always ([decision](decisions.md#a-key-its-storage-always-holds)).
  A text that may be left blank is `nullable: true`; a sequence is
  never null, and stores `[]`.
- **A constraint on a leaf is optional.** Core's own types carry some
  (`email`, `label`); the resource's constraints are the rule at every
  door, and `prepare()` holds the stored object to the schema's as
  well, so a constraint written in both says the same thing twice and
  keeps them in step by hand.

To check a stored object, ask core's typed config, with the language
code a translatable value needs (the storage writes it):
`\Drupal::service('config.typed')->get('<name>')->validate()` answers no
violation. The test module's opening hours, whose recipes are
[Common settings recipes](recipes.md), store a text that may be left
blank and a door code that may be cleared, and nothing else may hold
null:

<!-- yaml: tests/modules/data_surface_resource_test/config/schema/data_surface_resource_test.schema.yml#data_surface_resource_test.opening_hours -->

```yaml
data_surface_resource_test.opening_hours:
  type: config_object
  label: 'Opening hours'
  constraints:
    FullyValidatable: ~
  mapping:
    opens:
      type: string
      label: 'Opens at'
    closes:
      type: integer
      label: 'Closes at, in minutes after midnight'
    days:
      type: sequence
      label: 'Days open'
      sequence:
        type: string
        label: 'Day'
    notes:
      type: text
      label: 'Notes'
      nullable: true
    after_hours:
      type: boolean
      label: 'After-hours access'
    door_code:
      type: string
      label: 'Door code'
      nullable: true
    late_days:
      type: sequence
      label: 'After-hours days'
      sequence:
        type: string
        label: 'Day'
    keywords:
      type: sequence
      label: 'Keywords'
      sequence:
        type: string
        label: 'Keyword'
    holidays:
      type: string
      label: 'On public holidays'
```

The values are stored as the resource accepted them, key for key: an
attached structure is the map at its key, and the variants at a key
are the map of the variant the deciding key chose.

### Keys another module mounts

An alter's keys are mounted under `third_party_settings.<module>`
([Alters](resources.md#alters)), and on a simple config object core
describes no such key: the owner declares it, and each module that
mounts there describes its own part. The convention is the one the
examples use, a sequence whose type is named by the module key:

```yaml
# The owner, in its config/schema/<owner>.schema.yml:
my_module.settings:
  type: config_object
  mapping:
    # ... the resource's own keys ...
    third_party_settings:
      type: sequence
      label: 'Third party settings'
      requiredKey: false
      sequence:
        type: my_module.settings.third_party.[%key]

# The module that mounts, in its config/schema/<module>.schema.yml:
my_module.settings.third_party.other_module:
  type: mapping
  label: 'Other module settings'
  mapping:
    license:
      type: string
      label: 'Event license'
```

Config validation lets data no schema describes through without a word,
so `ConfigObjectStorage` checks the mounts itself: a module whose
settings resolve to no type is refused under `third_party_settings`,
naming the module; an owner that declares no `third_party_settings` at
all is refused by the schema under that key. `data_surface_examples`
declares `data_surface_examples.registration.third_party.[%key]` and
`data_surface_examples_compliance` its part.

A structure an alter attaches at its mount is described the same way:
its keys are the mount's, so the mounting module's mapping lists them,
and a mapping type of the structure's keys declared once serves every
site that stores it ([Reusing a structure](resources.md#reusing-a-structure-from-another-module)).
The test module's `data_surface_resource_test.seating` is one, used by
the venue's `seating`, the hall's `chairs` and the hall's mount.

### Keys a mixed-in structure brings

A structure [mixed in](resources.md#mixins) stores nothing itself
either, and its members are the owner's keys, stored flat beside the
owner's own, so the owner's config schema lists them among its own, as
any key it declares. A mapping of them cannot be inherited: a simple
config object's mapping extends `config_object` already, and a mapping
takes one parent. So the owner repeats the members, and a comment
naming the structure keeps the two in step; whatever attaches the same
structure uses one mapping type of its keys, as for any structure
attached:

```yaml
# The owner, which mixes the timestamps in:
data_surface_resource_test.journal:
  type: config_object
  mapping:
    title:
      type: string
      label: 'Title'
    # Mixed in from resource_test.timestamps.
    created:
      type: integer
      label: 'Created'
      nullable: true
    changed:
      type: integer
      label: 'Changed'
      nullable: true

# Another, which attaches them under a key:
data_surface_resource_test.ledger:
  type: config_object
  mapping:
    stamps:
      type: data_surface_resource_test.timestamps
```

A member the structure's access omits for the account writing is kept
as stored, at the owner's level or inside a child the owner stores.

## What storage is

Two interfaces with the same three verbs. A resource's own storage class
implements the second, and nothing an author writes implements the
first:

- `Drupal\data_surface\Pipeline\DataSurfaceStorageInterface`, what the
  pipeline writes to, over the data surface. The engine's storage
  classes below implement it, all but `ConfigObjectStorage`, and
  `StorageAdapter` adapts a resource's storage to it.
- `Drupal\data_surface\Resource\ResourceStorageInterface`, what
  `#[Resource(storage:)]` names, over the context.

The pipeline's:

| Method | Does | Writes? |
| --- | --- | --- |
| `load($surface)` | Reads what is stored, in input layout. | no |
| `prepare($surface, $values)` | Turns accepted values into the storage layout and hands back a `PreparedValues`. | no |
| `commit($prepared)` | Stores the artifact. | yes |

The data surface is an argument rather than something the storage
holds. Storage describes a destination and a data surface describes
values: the same node type is the destination of the add operation and
the edit operation, and storage holding the built resource for one of
them could not serve both.

`load()` is what makes a partial update legal. It is the `current`
argument of [the pipeline's `accept()`](pipeline.md#accept), so a key
nobody sent keeps what it holds instead of falling back to its declared
default.

A resource names its storage with `#[Resource(storage:)]`, a
`Resource\ResourceStorageInterface` with the same three verbs over the
context instead of the data surface: `load($context)`,
`prepare($context, $values)`, which returns the array in storage layout
after storage's own checks, and `commit($context, $prepared)`. It loads
by the identity the context knows, so the context never carries an
entity, and creates or updates by whether the context creates.
`StorageAdapter` binds the context and makes it this interface;
[Prepare](resources.md#prepare) says what each storage class here
checks. A resource's storage class is where its own storage logic lives:
`NodeTypeStorage`, `FieldInstanceStorage`, `FieldStorageStorage` and
`AddressFieldSettingsStorage` each rehearse a config entity in
`prepare()` and hold it to its config schema with `SchemaViolations`,
and a resource's storage class may delegate to an engine storage class
below where one already says the translation: `NodeTypeStorage` writes
a content type's title label and workflow defaults through
`BaseFieldOverrideStorage`.

A variant derived from a config schema has no class and so no
storage of its own: its values are stored by its parent under the
variants' key, as `FieldStorageStorage` stores a string's derived `max_length`
among the field storage's settings.

## Getting one

```php
$resources = \Drupal::service('data_surface.resources');
$context = $resources->operation(NodeTypeResource::class, 'edit', ['type' => 'article']);
$storage = $resources->storage(NodeTypeResource::class, $context);
```

`ResourcesInterface::storage()` adapts the resource's storage to the
pipeline's interface and composes it along the tree of its children: a
child whose class names storage of its own (an attached resource) has
its values routed there, and a child without any (a structure, usually)
is stored by its parent under its key.

A structure a plugin uses names no storage, because only the plugin's
host holds the instance its values belong to. The host supplies it:

| Host family | Answers with |
| --- | --- |
| Any configurable plugin (block, condition, action) | `PluginConfigurationStorage` over itself, from `DataSurfaceHostFormTrait`: the one construction path for the family, which the form submit asks for rather than building inline. |
| Field types | `FieldSettingsStorage` over the field config entity the item is bound to, from `DataSurfaceFieldTypeTrait`. A field type whose storage layout differs overrides `getDataSurfaceStorage()` and hands `FieldSettingsStorage` a layout. |
| Field formatters | Nothing: they throw. |

### The refusal, and why it is a refusal

`ResourcesInterface::storage()` throws `\LogicException` for a class
that names no storage, and a host whose values have no destination of
its own throws from `getDataSurfaceStorage()`. Two kinds legitimately do:

1. **A host that owns the write.** A field formatter's settings are one
   component of an entity view display, and Field UI copies whatever the
   settings element produced onto that display and saves it. The
   formatter has no destination to hand out, so it says so.
2. **A read-only resource or structure**, describing values nobody
   writes through the pipeline.

Quietly useless storage would be worse than the exception: a caller
would submit into it, the pipeline would report the values committed,
and nothing would have been stored.

### When the destination is named by the submission

An add operation knows no identity, because the thing it creates does
not exist to be named, and yet its destination may depend on what is
being created. The node type demo is the worked example: a content
type's base field overrides belong to a bundle, and the bundle IS the
machine name being submitted.

The answer is that storage reads the identity in `prepare()`, not
before. `NodeTypeStorage` loads by the machine name the context knows,
and on add, where it knows none, loads nothing; `prepare()` builds the
node type around the machine name the accepted values carry, and has
`BaseFieldOverrideStorage` plan the overrides for that bundle, which
need not exist yet. Storage taking the accepted values when it is made
would have put a write-path argument on a method a discovery document
calls with nothing but a context.

## Prepare and commit are separate, and that is the whole design

`prepare()` writes nothing. It lays the values out, it validates
against whatever the storage itself has an opinion about (a config
schema, usually), and it hands back the artifact a commit would store. So a caller can preview a
submission, diff it, show it, or throw it away without anything having
happened.

Two rules follow, and both were bugs before they were rules:

1. **Prepare never touches what the caller handed over.** The config
   entity storage and every resource's storage class that rehearses an
   entity work on a copy, and the copy is the artifact. The object a
   caller passes in is almost always the one its form, its route and the
   rest of the request are holding; shaping values onto it would make a
   rehearsal visible to everybody, and the next unrelated save of that
   object would persist a submission nobody accepted.
2. **Commit stores the artifact, not the thing the storage holds.** They
   used to be able to differ, which is how a dry run could be saved by
   accident. After a commit the storage describes what it saved, so a
   `load()` afterwards reads the values that were written.

### The host-owns-the-write split

Some hosts save for themselves. Field UI copies whatever a settings
element produced onto the field config entity and saves it, so a field
type's settings form runs accept, validate and **prepare**, writes the
prepared artifact into form state, and deliberately does not commit:
calling both would save the field twice. That is why the transform lives
in `prepare()` and not in `commit()`: a caller that does own the write
reaches the same transform through `submit()` and gets the same stored
settings.

### Dry runs

`DataSurfacePipelineInterface::submit()` takes a `$dry_run` flag: it runs
everything up to and including prepare and hands back the artifact with
`committed` false.

Config entities write through the container's config factory, so an
entity cannot be pointed at another bin for one call. An unsaved clone,
held to the schema, is as far as a dry run goes; a resource's storage
class returns that clone's exported array from `prepare()`, which a
PHP caller reads on the result; a derived tool's dry run answers the
accepted values instead ([decision](decisions.md#every-door-projects-what-the-read-tool-projects)).

## The seven engine storage classes

| Storage | Destination | Artifact | Used by |
| --- | --- | --- | --- |
| `ConfigObjectStorage` | One simple config object, named by a subclass. A `ResourceStorageInterface`, named by a resource. | The values to write, plus the language code when the schema asks for one. | the examples, `data_surface_first` |
| `StateStorage` | One State key. | The accepted values. | the demo form |
| `ConfigEntityStorage` | One config entity's properties, plus core's third party settings. | An unsaved clone of the entity. | no shipped resource yet |
| `FieldSettingsStorage` | One field instance's settings. | The settings array. | the field type host |
| `BaseFieldOverrideStorage` | A bundle's overrides of an entity type's base fields. | The overrides to save, as exported arrays, and the fields whose override goes. | `NodeTypeStorage` |
| `PluginConfigurationStorage` | A plugin's configuration array. | The complete configuration. | the plugin hosts |
| `CompositeStorage` | Several of the above, in order. | One prepared set per child. | no shipped resource yet |

`ConfigObjectStorage` came back once every settings resource had
written the same class: the examples' three, and the one the first
fresh-eyes reader had to write ([the log](fresh-eyes-2026-10-18.md)).

The namespace says which kind a class is. A class in `Storage\` is one
you name on `#[Resource(storage:)]` or extend: `ConfigObjectStorage`,
and beside it what such a class works with, the layouts, the secret
codec and `SchemaViolations`. The other six implement the pipeline's
`DataSurfaceStorageInterface` and live beside it in `Pipeline\Storage\`
(`use Drupal\data_surface\Pipeline\Storage\StateStorage;`): a plugin's
host supplies one, and a resource's storage class may delegate to one,
but `#[Resource(storage: StateStorage::class)]` is refused, since it is
no `ResourceStorageInterface`.

A few things each of them is opinionated about:

- **`ConfigEntityStorage`** maps an input key to an entity property
  name, or to `['method' => 'setX']` for a property that needs a real
  setter. The method form is a string rather than a callable on purpose:
  it survives serialization, and it is the spelling core's config
  actions use, so a key a resource can write is a key a recipe can
  write.
- **`BaseFieldOverrideStorage`** is the translation nobody had written
  down: a content type's "published by default" is the default value of
  the node status base field narrowed to one bundle, and its title label
  is the title base field's label narrowed the same way. It compares
  before writing, as core's own bundle forms do, because an override
  that restates the base field's value is config nobody asked for. A
  key sent as `NULL` asks for the override to go away entirely, which is
  different from not sending the key at all: that says nothing and
  changes nothing. Only base fields have overrides; a map naming a
  configurable field is refused by name rather than quietly rewriting
  that field instance. Besides the pipeline's three verbs it offers
  their bodies to a resource's storage class that writes a bundle of
  its own: `values()` reads the mapped keys, `plan()` rehearses, and
  `write()` writes a plan onto the bundle as it then exists, after that
  storage class has saved the bundle.
- **`CompositeStorage`** requires every input key to be claimed by
  exactly one child. A key no child claims would be accepted, validated,
  reported committed and never written; a key two children claim would
  be written to two destinations that disagree the moment either is
  edited alone. Both are silent, so both are refused, at the first call
  that has the data surface to check against. A child listed without a
  key list is the one-child shorthand for "this child owns everything".
  A child may name a key the data surface does not declare: that is how
  a destination says where a mount would go before anything has mounted
  there.
- **`CompositeStorage` ordering is load-bearing.** Children run in the
  order they were listed, which matters when a later write needs an
  earlier one to have happened: base field overrides belong to a bundle,
  so on an add operation the storage that creates the bundle must be
  listed first. A resource's own children are composed by
  `StorageAdapter` instead, which routes variants by their deciding
  key's value; a composite hands each child only the keys it claims.

## Config schema, and whose violation it is

Every storage class that writes config holds its artifact to the config
schema, through `SchemaViolations`, and reports what it says in the
same `ViolationSet` the resource's own constraints answer with, filed
under the input key that owns the path.

Which data to hand it is the whole thing as it will be stored: the
written values laid over the stored object, with its language code when
it has one. Handed only the keys being written, a schema cannot resolve
a type named by a sibling, and core's "must specify a language code"
check, which sits at the root, never sees the object it is about. The
paths are the keys being written, so the report is about this write.
`ConfigObjectStorage` does exactly that.

Only the paths the storage writes are reported. A schema validates the
whole object, so a config object carrying a violation under a key the
resource never declared (written by another module, or left behind by
an older schema) used to refuse a submission that had nothing to do
with it, naming a key the caller cannot even see. That violation is real
and it is somebody's problem; it is not this write's problem. A caller
that wants the whole picture asks for it:

```php
SchemaViolations::collect($typed_config, $name, $data, $paths, FALSE);
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Config with no schema at all is not an error. Plenty of config still has
none, and storage that insisted would be unusable there.

## StorageLayoutInterface

`Drupal\data_surface\Storage\StorageLayoutInterface` has two methods,
`toStorage(array $values): array` and `fromStorage(array $settings):
array`. `FieldSettingsStorage` takes one, optionally; without one the
input layout is the storage layout. It is also where encryption sits,
below.

It exists because storage layouts are frequently not the layout a person
or an agent should be asked for. The address field type stores its
countries as a map of each code to itself, wraps every field override in
a single-key array, and keeps a deprecated key that silently wins over
the current one. That translation already exists today, half of it in a
settings form's validate handler and half in the item class's accessors,
which is exactly why only a form can write those settings correctly.
`AddressStorageLayout` is the same translation with a name, so a form, a
kernel test, a config action and an agent all go through it.

An implementation must be pure layout: no services, no current user, no
site state. The two halves are expected to round-trip, and where they
cannot the implementation says so in its own documentation.

### A layout for settings another module mounted

An alter that adds keys may ask for a value in one layout and store it
in another. It does not own the storage, so it hands the translation to
the data surface instead, by implementing
`AltersStorageLayoutInterface`, whose `storageLayout()` returns a
`StorageLayoutInterface`.

The sealed data surface carries the layout, refinement keeps it, and
`getThirdPartyLayout($module)` answers it. It is applied to that
module's mount, `third_party_settings.<module>`, and nothing else:
`toStorage()` before the storage prepares, so storage's own checks judge
what will actually be stored; `fromStorage()` after it loads. The layout
sees only that module's own settings, keyed by the keys it mounted. A
layout for a module that mounts nothing is refused.

`data_surface_demo_extras` is the worked example: `NodeTypeAlter` asks
for a content type's review deadline as an amount and a unit, and its
`ReviewDeadlineLayout` stores seconds, through `NodeTypeStorage`, which
knows nothing about it. `StorageAdapter` applies the layout for a
resource's own storage class; of the engine's storage classes, only
`ConfigEntityStorage` writes third party settings, so it is the only one
that reads it.

## Secrets at the codec

An input key declared secret
([Declaring a resource](declaring-a-resource.md#secrets)) is encrypted
on its way into storage and decrypted on its way back out, so plaintext
never reaches storage from any caller. Two small pieces do it:

| Piece | What it is |
| --- | --- |
| `SecretCodecInterface` | `encrypt()`, `decrypt()`, `isEncrypted()`. A service, so a deployment can replace it. |
| `EncryptedStorageLayout` | A `StorageLayoutInterface` decorator that wraps another layout, or none, and enciphers the secret keys of the data surface it was made for. |

The shipped codec, `SodiumSecretCodec`, is a **reference
implementation**: libsodium's `crypto_secretbox` with a fresh random
nonce per value, keyed by HKDF-SHA256 from the site's hash salt. It is
correct encryption and it is not key management: an attacker who can
read `settings.php` can read the secrets, and rotating the hash salt
makes every stored secret unreadable. A site holding real secrets
replaces the `data_surface.secret_codec` service with one backed by a key
manager, an HSM or a cloud KMS, and nothing else changes: no resource,
no structure, no storage class, no form. The envelope is
`data_surface:sodium:v1:` followed by base64 of the nonce and
ciphertext, and the prefix is what lets a migration tell ciphertext from
plaintext.

The decorator works in **input layout**: it encrypts before the inner
layout runs on the way in and decrypts after the inner layout has run on
the way out. That order is what lets it compose with any layout. The
secret flag is declared on the resource or structure, so input layout is
the only place the keys can be named, and an inner layout then carries
an opaque string exactly as it carried the plaintext. The one thing an
inner layout may not do is look *into* a secret value, since by then it
is ciphertext.

It takes its key list at construction rather than reading a data
surface at call time, because a layout has no data surface and is not
going to get one: `StorageLayoutInterface` is pure layout, built once and
reused for every instance of a field type.
`EncryptedStorageLayout::forSurface()` is the one line that reads the
flags off a data surface and hands back the inner layout unchanged when
there are none.

### What is wired, and what is not

`FieldSettingsStorage` composes the decorator itself. It is handed the
data surface on both calls that translate, so it wraps its layout when
the data surface declares a secret key and the codec service is
available, and leaves everything else exactly as it was.

**The other storage classes do not, and that is the honest state of
it.** The state storage, the config entity storage, the base field
override storage and the plugin configuration storage write values that
never pass through a `StorageLayoutInterface`, so there is nowhere for
the decorator to sit. Generalizing it to a decorator around any storage
class is the follow-up, and it is not a rename: storage that writes
config holds its artifact to the config schema, which would be
validating ciphertext against a schema describing the plaintext, and the
plugin configuration storage's values are read back by the plugin itself
rather than only by the pipeline. Until that is designed, a host with a
secret key in one of those destinations either uses
`FieldSettingsStorage` or calls the codec itself, and a resource or
structure declaring a secret against unwired storage still gets the form
and `accept()` halves of the flag, which is to say the value is kept and
never echoed, but stored in the clear.

### Values written before the flag

`fromStorage()` asks the codec `isEncrypted()` and hands plaintext
through unchanged, so a key declared secret today reads the values it
already had. `toStorage()` enciphers them on the next write. Migration is
therefore the next save of each affected thing, with no update hook and
no scan, and it is not retroactive: until that save the old plaintext is
still in storage, which is worth saying out loud to whoever administers
the site.

`NULL` and the empty string are stored as they are. There is no secret
to keep in "no secret", and enciphering nothing would make an absent
value look like a present one.

One consequence of a fresh nonce per value: an unchanged secret is
written as different ciphertext every time its thing is saved, so the
stored config differs even when nothing was edited. That is the price of
two equal secrets not looking equal in storage, and it is the right way
round, but it does mean a config export diff after an unrelated save is
not evidence that the secret changed.

## The serialization rule

**An element carries identifiers. The callback rebuilds the objects.**

Some host protocols have no validate or submit hook, so the whole
pipeline runs in an `#element_validate` callback, which is static and
has only the element and the form state to work from. The obvious thing
is to put the data surface and its storage on the element, and the
obvious thing is wrong, because a cached form is a serialized form
array, and a data surface holds its refiners while storage holds
services. That hands the form cache responsibility for objects nobody
designed to be stored.

So each element carries strings, and each static callback rebuilds:

| Host | The element carries | The callback rebuilds from |
| --- | --- | --- |
| Field formatter | `#data_surface` (plugin id), `#data_surface_field_name`, `#data_surface_current` | the entity display the host form is editing, which has the real field definition; failing that, the formatter plugin manager |
| Field type | `#data_surface` (field config id), `#data_surface_current` | the field config the host form is editing, which is also the only way to find an unsaved one; failing that, a load by id |

`#data_surface_current` is the values the form started from, and it is an
array, so it stores as it is. It is there because a key with no rendered
element has said nothing and must keep what is stored rather than fall
back to its declared default.

Every storage class also composes core's `DependencySerializationTrait`,
as a safety net for adopters who do put one on a form: its services
then store as service ids and come back as the container's own objects
rather than as dead copies. Two of them cannot be made fully safe that
way, because what they hold is not a service: `ConfigEntityStorage` and
`FieldSettingsStorage` each carry a config entity. The rule for
those is the one each class documents: hand it an object you own.

## Writing a storage class

Worth having when a destination is reached more than once, or when the
translation between input layout and storage layout is worth a name. A
simple config object needs none: subclass `ConfigObjectStorage`.

A resource's storage class, a `ResourceStorageInterface` named by
`#[Resource(storage:)]`:

1. `load($context)` reads by the identity the context knows, in input
   layout, and returns an empty array when nothing is stored (and for a
   context that creates).
2. `prepare($context, $values)` writes nothing, touches nothing the
   caller handed over, holds what it would store to every check the store
   makes (the config schema through `SchemaViolations`, on the whole
   object), throws `StorageViolationsException` with violations filed
   under input keys, and returns what `commit()` will write.
3. `commit($context, $prepared)` writes exactly what `prepare()`
   returned.
4. It is an autowired service, so it takes services in its constructor;
   container-built, it translates with `$this->t()`.
5. Compose `DependencySerializationTrait`, and hold no closures.

An engine storage class, a `DataSurfaceStorageInterface` the pipeline
writes to directly:

1. `load($surface)` reads in input layout and returns an empty array when
   nothing is stored.
2. `prepare($surface, $values)` writes nothing, touches nothing the
   caller handed over, and puts everything a commit needs into the
   `PreparedValues` artifact.
3. `commit($prepared)` stores the artifact it is given, and refuses an
   artifact that did not come from this storage.
4. Declare any dependencies the artifact carries in the `PreparedValues`,
   in the shape `calculateDependencies()` collects them.
5. Compose `DependencySerializationTrait`, and hold no closures.
