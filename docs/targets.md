# Targets

A surface says what a thing accepts. A target says where that thing is
kept. Keeping them apart is what lets one surface describe a node type
whether a form, a config action, a test or an agent is doing the
submitting, and what lets one destination serve the surface for adding a
thing and the surface for editing it.

This page is about the second half: the seven shipped targets, the
prepare-versus-commit split, dry runs, what may ride on a form, and the
one small interface a target uses when the input shape and the storage
shape differ. [The pipeline](pipeline.md) is the other half, and the
stage names below are its.

## What a target is

Three methods, `Drupal\data_surface\Pipeline\DataSurfaceTargetInterface`:

| Method | Does | Writes? |
| --- | --- | --- |
| `load($surface)` | Reads what is stored, in surface shape. | no |
| `prepare($surface, $values)` | Turns accepted values into the storage shape and hands back a `PreparedValues`. | no |
| `commit($prepared)` | Stores the artifact. | yes |

The surface is an argument rather than something a target holds. A
target describes a destination and a surface describes values: the same
node type is the destination of the add surface and the edit surface,
and a target holding one of them could not serve both.

`load()` is what makes a partial update legal — it is the `current`
argument of [the pipeline's `accept()`](pipeline.md#accept), so a key
nobody sent keeps what it holds instead of falling back to its declared
default.

## Asking a provider for one

A target is the third answer of the provider contract's triple, beside
the surface and the access answer, and all three resolve from the same
coordinate:

```php
$surface = $provider->getDataSurface($operation, $subject);
$access  = $provider->surfaceAccess($operation, $subject);
$target  = $provider->getDataSurfaceTarget($operation, $subject);
```

That is what lets a caller holding nothing but an operation and a
subject write as well as read — a generated form, a config action, a
Drush command, the discovery endpoint. Before the accessor existed,
target acquisition had three spellings: plugin hosts wrapped themselves
implicitly inside a submit handler, the field contract had an accessor
of its own name, and a standalone provider invented a method nobody
else could call.

The operation and subject mean here exactly what they mean on
`getDataSurface()`, and a provider refuses the same coordinates in the
same words: a target answering for a coordinate the surface refuses
would be a destination for values nobody could describe.

| Host family | Answers with |
| --- | --- |
| Any configurable plugin (block, condition, action) | `PluginConfigurationTarget` over itself, from `DataSurfaceHostFormTrait` — the one construction path for the family, which the form submit now asks for rather than building inline. |
| Field types | `FieldSettingsTarget` over the field config entity the item is bound to, from `DataSurfaceFieldTypeTrait`. A field type whose storage shape differs overrides it and hands the target a shape. |
| Field formatters | Nothing: they throw. |
| A standalone provider | Whatever it writes. The node type demo answers with its composite. |

### The refusal, and why it is a refusal

A provider whose operation has a surface but no target it can name
throws `\LogicException`. Two kinds of provider legitimately do:

1. **A host that owns the write.** A field formatter's settings are one
   component of an entity view display, and Field UI copies whatever the
   settings element produced onto that display and saves it. The
   formatter has no destination to hand out, so it says so.
2. **A read-only surface**, describing values nobody writes through the
   pipeline.

A quietly useless target would be worse than the exception: a caller
would submit into it, the pipeline would report the values committed,
and nothing would have been stored.

### When the destination is named by the submission

An add operation has no subject, because the thing it creates does not
exist to be named — and yet its destination may depend on what is being
created. The node type demo is the worked example: a content type's base
field overrides belong to a bundle, and the bundle IS the machine name
being submitted.

The answer is a target that waits one stage. `NodeTypeAddTarget` reads
against the entity type's own base fields, exactly as a brand new bundle
does, and builds the real composite in `prepare()` around the machine
name the accepted values carry. The alternative — a target accessor
taking the accepted values — would have put a write-path argument on a
method the discovery endpoint calls with nothing but a coordinate.

## Prepare and commit are separate, and that is the whole design

`prepare()` writes nothing. It shapes, it validates against whatever the
storage itself has an opinion about (a config schema, usually), and it
hands back the artifact a commit would store. So a caller can preview a
submission, diff it, show it, or throw it away without anything having
happened.

Two rules follow, and both were bugs before they were rules:

1. **Prepare never touches what the caller handed over.** The config
   entity target and the config object target both work on a copy, and
   the copy is the artifact. The object a caller passes in is almost
   always the one its form, its route and the rest of the request are
   holding; shaping values onto it would make a rehearsal visible to
   everybody, and the next unrelated save of that object would persist a
   submission nobody accepted.
2. **Commit stores the artifact, not the thing the target holds.** They
   used to be able to differ, which is how a dry run could be saved by
   accident. After a commit the target describes what it saved, so a
   `load()` afterwards reads the values that were written.

### The host-owns-the-write split

Some hosts save for themselves. Field UI copies whatever a settings
element produced onto the field config entity and saves it, so a field
type's settings form runs accept, validate and **prepare**, writes the
prepared artifact into form state, and deliberately does not commit —
calling both would save the field twice. That is why the transform lives
in `prepare()` and not in `commit()`: a caller that does own the write
reaches the same transform through `submit()` and gets the same stored
settings.

### Dry runs

`DataSurfacePipelineInterface::submit()` takes a `$dry_run` flag: it runs
everything up to and including prepare and hands back the artifact with
`committed` false.

For the config object target there is a stronger kind of dry run, and it
is the one place in the config system where redirecting a single write
costs nothing. A `Config` holds the storage it was constructed with, so:

```php
$config = new Config($name, new MemoryStorage(), new EventDispatcher(), $typed_config);
$config->initWithData($active_storage->read($name));
$target = new ConfigObjectTarget($config, $map, $typed_config);
```

commits for real into a bin nobody else reads. The private dispatcher is
the part that keeps the rehearsal's save event away from the listeners
the live config factory keeps.

Config entities have no equivalent: their storage writes through the
container's config factory, so an entity cannot be pointed at another
bin for one call. An unsaved clone is as far as that dry run goes.

## The seven shipped targets

| Target | Destination | Artifact |
| --- | --- | --- |
| `StateTarget` | One State key. | The accepted values. |
| `ConfigObjectTarget` | One simple config object, by dotted path. | A copy of the `Config`. |
| `ConfigEntityTarget` | One config entity's properties, plus core's third party settings. | An unsaved clone of the entity. |
| `FieldSettingsTarget` | One field instance's settings. | The settings array. |
| `BaseFieldOverrideTarget` | A bundle's overrides of an entity type's base fields. | The overrides to save and the overrides to remove. |
| `PluginConfigurationTarget` | A plugin's configuration array. | The complete configuration. |
| `CompositeTarget` | Several of the above, in order. | One prepared set per child. |

A few things each of them is opinionated about:

- **`ConfigObjectTarget`** mirrors core's `ConfigTarget`, callables in
  each direction included, so a form already carrying `#config_target`
  metadata describes the same thing and the two can be ported into each
  other.
- **`ConfigEntityTarget`** maps a surface key to an entity property name,
  or to `['method' => 'setX']` for a property that needs a real setter.
  The method form is a string rather than a callable on purpose: it
  survives serialization, and it is the spelling core's config actions
  use, so a key a surface can write is a key a recipe can write.
- **`BaseFieldOverrideTarget`** is the translation nobody had written
  down: a content type's "published by default" is the default value of
  the node status base field narrowed to one bundle, and its title label
  is the title base field's label narrowed the same way. It compares
  before writing, as core's own bundle forms do, because an override
  that restates the base field's value is config nobody asked for. A
  key sent as `NULL` asks for the override to go away entirely, which is
  different from not sending the key at all — that says nothing and
  changes nothing. Only base fields have overrides; a map naming a
  configurable field is refused by name rather than quietly rewriting
  that field instance.
- **`CompositeTarget`** requires every surface key to be claimed by
  exactly one child. A key no child claims would be accepted, validated,
  reported committed and never written; a key two children claim would
  be written to two destinations that disagree the moment either is
  edited alone. Both are silent, so both are refused, at the first call
  that has the surface to check against. A child listed without a key
  list is the one-child shorthand for "this child owns everything". A
  child may name a key the surface does not declare — that is how a
  destination says where a mount would go before anything has mounted
  there.
- **`CompositeTarget` ordering is load-bearing.** Children run in the
  order they were listed, which matters when a later write needs an
  earlier one to have happened: base field overrides belong to a bundle,
  so on an add operation the target that creates the bundle must be
  listed first.

## Config schema, and whose violation it is

Both config targets hold their artifact to the config schema and report
what it says in the same `ViolationSet` the surface's own constraints
answer with, filed under the surface key that owns the path.

Only the paths the target writes are reported. A schema validates the
whole object, so a config object carrying a violation under a key the
surface never declared — written by another module, or left behind by an
older schema — used to refuse a submission that had nothing to do with
it, naming a key the caller cannot even see. That violation is real and
it is somebody's problem; it is not this write's problem. A caller that
wants the whole picture asks for it:

```php
SchemaViolations::collect($typed_config, $name, $data, $paths, FALSE);
```

Config with no schema at all is not an error. Plenty of config still has
none, and a target that insisted would be unusable there.

## SettingsShapeInterface

`Drupal\data_surface\Target\SettingsShapeInterface` has two methods,
`toStorage(array $values): array` and `fromStorage(array $settings):
array`. `FieldSettingsTarget` takes one, optionally; without one the
surface shape is the storage shape. It is also where encryption sits,
below.

It exists because storage shapes are frequently not the shape a person
or an agent should be asked for. The address field type stores its
countries as a map of each code to itself, wraps every field override in
a single-key array, and keeps a deprecated key that silently wins over
the current one. That translation already exists today — half of it in a
settings form's validate handler and half in the item class's accessors
— which is exactly why only a form can write those settings correctly.
`AddressSettingsShape` is the same translation with a name, so a form, a
kernel test, a config action and an agent all go through it.

An implementation must be pure shape: no services, no current user, no
site state. The two halves are expected to round-trip, and where they
cannot the implementation says so in its own documentation.

## Secrets at the codec

A surface key declared secret
([Declaring a surface](declaring-a-surface.md#secrets)) is encrypted on
its way into storage and decrypted on its way back out, so plaintext
never reaches storage from any caller. Two small pieces do it:

| Piece | What it is |
| --- | --- |
| `SecretCodecInterface` | `encrypt()`, `decrypt()`, `isEncrypted()`. A service, so a deployment can replace it. |
| `EncryptedSettingsShape` | A `SettingsShapeInterface` decorator that wraps another shape, or none, and enciphers the surface's secret keys. |

The shipped codec, `SodiumSecretCodec`, is a **reference
implementation**: libsodium's `crypto_secretbox` with a fresh random
nonce per value, keyed by HKDF-SHA256 from the site's hash salt. It is
correct encryption and it is not key management — an attacker who can
read `settings.php` can read the secrets, and rotating the hash salt
makes every stored secret unreadable. A site holding real secrets
replaces the `data_surface.secret_codec` service with one backed by a key
manager, an HSM or a cloud KMS, and nothing else changes: no surface, no
target, no form. The envelope is `data_surface:sodium:v1:` followed by
base64 of the nonce and ciphertext, and the prefix is what lets a
migration tell ciphertext from plaintext.

The decorator works in **surface shape**: it encrypts before the inner
shape runs on the way in and decrypts after the inner shape has run on
the way out. That order is what lets it compose with any shape — the
secret flag is declared on the surface, so surface shape is the only
place the keys can be named, and an inner shape then carries an opaque
string exactly as it carried the plaintext. The one thing an inner shape
may not do is look *into* a secret value, since by then it is ciphertext.

It takes its key list at construction rather than reading a surface at
call time, because a shape has no surface and is not going to get one:
`SettingsShapeInterface` is pure shape, built once and reused for every
instance of a field type. `EncryptedSettingsShape::forSurface()` is the
one line that reads the flags off a surface and hands back the inner
shape unchanged when there are none.

### What is wired, and what is not

`FieldSettingsTarget` composes the decorator itself. It is handed the
surface on both calls that translate, so it wraps its shape when the
surface declares a secret key and the codec service is available, and
leaves everything else exactly as it was.

**The other targets do not, and that is the honest state of it.** The
state target, the two config targets, the base field override target and
the plugin configuration target write values that never pass through a
`SettingsShapeInterface`, so there is nowhere for the decorator to sit.
Generalizing it to a target-level decorator is the follow-up, and it is
not a rename: a config target holds its artifact to the config schema,
which would be validating ciphertext against a schema describing the
plaintext, and the plugin configuration target's values are read back by
the plugin itself rather than only by the pipeline. Until that is
designed, a host with a secret key in one of those destinations either
uses `FieldSettingsTarget` or calls the codec itself — and a surface
declaring a secret against an unwired target still gets the form and
`accept()` halves of the flag, which is to say the value is kept and
never echoed, but stored in the clear.

### Values written before the flag

`fromStorage()` asks the codec `isEncrypted()` and hands plaintext
through unchanged, so a key declared secret today reads the values it
already had. `toStorage()` enciphers them on the next write. Migration is
therefore the next save of each affected thing, with no update hook and
no scan — and it is not retroactive: until that save the old plaintext is
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
is to put the surface and the target on the element — and the obvious
thing is wrong, because a cached form is a serialized form array, and a
surface holds its refiners while a target holds services. That hands the
form cache responsibility for objects nobody designed to be stored.

So each element carries strings, and each static callback rebuilds:

| Host | The element carries | The callback rebuilds from |
| --- | --- | --- |
| Field formatter | `#data_surface` (plugin id), `#data_surface_field_name`, `#data_surface_current` | the entity display the host form is editing, which has the real field definition; failing that, the formatter plugin manager |
| Field type | `#data_surface` (field config id), `#data_surface_current` | the field config the host form is editing, which is also the only way to find an unsaved one; failing that, a load by id |

`#data_surface_current` is the values the form started from, and it is an
array, so it stores as it is. It is there because a key with no rendered
element has said nothing and must keep what is stored rather than fall
back to its declared default.

Every target also composes core's `DependencySerializationTrait`, as a
safety net for adopters who do put one on a form: a target's services
then store as service ids and come back as the container's own objects
rather than as dead copies. Two of them cannot be made fully safe that
way, because what they hold is not a service — a `Config` carries its own
storage, and `FieldSettingsTarget` carries a config entity. The rule for
those is the one each class documents: hand it an object you own.

## Writing a target

Worth having when a destination is reached more than once, or when the
translation between surface shape and storage shape is worth a name. The
checklist:

1. `load()` reads in surface shape and returns an empty array when
   nothing is stored.
2. `prepare()` writes nothing, touches nothing the caller handed over,
   and puts everything a commit needs into the artifact.
3. `commit()` stores the artifact it is given, and refuses an artifact
   that did not come from this target.
4. Declare any dependencies the artifact carries in the `PreparedValues`,
   in the shape `calculateDependencies()` collects them.
5. Compose `DependencySerializationTrait`, and hold no closures.
