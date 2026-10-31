# Storage

Use this page when a resource's values live anywhere other than one
simple config object, or when you need to know exactly when they are
written. [Storage](introduction/10-storage.md) introduces the idea in
plain words.

A resource says what a thing accepts. Its storage says where that thing
is kept. Keeping them apart is what lets one resource describe a node
type whether a form, a config action, a test or an agent is doing the
submitting, and what lets one storage class serve both the operation
that adds a thing and the operation that edits it.

This page is about the second half: a resource's own storage, the two
it may extend (a simple config object's and a config entity's), the
engine's five storage classes, the prepare-versus-commit split, dry runs,
what may ride on a form, and the one small interface storage uses when
the input layout and the storage layout differ. [The pipeline](pipeline.md)
is the other half, and the stage names below are its.

A settings page needs none of this page but the next section: its
resource names a subclass of `ConfigObjectStorage` and is done ([A
simple config object](#a-simple-config-object), and [Your first resource](first-resource.md) for the whole module). A thing with a
machine name kept as a config entity names a subclass of
`ConfigEntityStorage` ([A config entity](#a-config-entity)).

## A simple config object

The most common resource is a settings page, kept in one simple config
object. The engine's `ConfigObjectStorage` stores it; a module names its
object on a subclass, in its `src/Storage/`, and the resource names the
subclass:

```php
final class NewsletterStorage extends ConfigObjectStorage {

  public const CONFIG = 'surface_examples_first.newsletter';

}
```

```php
#[Resource('first.newsletter',
  label: new TranslatableMarkup('Newsletter settings'),
  storage: NewsletterStorage::class,
)]
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
- **A missing object reads as empty**: every key starts from its
  default, and the first write creates the object. The module still
  ships it in `config/install`; on a site that installed the module
  before the object shipped, a post-update hook calling
  `surface_install_missing_config('<module>')` creates it from that
  file and leaves every existing object alone.
- **A config schema must describe the object**: a missing schema is a
  `\LogicException` naming the object, since the storage exists to hold
  values to it. The schema and the install file are written from the
  resource ([below](#a-schema-generated-from-the-resource)).
- **`mayClear()`** says which keys the object may hold nothing for, by
  core's own rule: any key, unless the schema is fully validatable
  (`constraints: {FullyValidatable: ~}` at its root), where only a key
  that says `nullable: true` may. A key that may not is always held: a
  tool's null for it is refused naming its default, both schemas leave
  null out of its type, and its select offers no `- None -` once a
  valid choice is selected
  (why: [decision](decisions.md#a-key-its-storage-always-holds)). A generated
  schema is fully validatable, so `nullable: true` is what decides it.
- **`widerThanSchema()`** says where the resource advertises more than
  the schema allows, and the build refuses such a resource
  ([below](#a-schema-the-module-did-not-generate)); a generated schema is
  never narrower.
- **`layout()`** says how the object keeps a key it stores otherwise
  than it is asked for: none by default. `load()` reads through the
  layout and `prepare()` writes through it
  ([StorageLayoutInterface](#storagelayoutinterface)).
- **`STORED_AT`** says where the object keeps a key deeper than its own
  name, by dotted path, beside keys the resource does not declare: none
  by default. Every method above reads and writes the key there and
  keeps the rest ([below](#a-key-stored-under-a-path)).
- **A key that refers to a content entity is stored as the entity's
  uuid**, and every door still sends and answers the id
  ([below](#a-reference-is-stored-by-uuid)).

### A schema generated from the resource

The resource is the one description of the config object; its config
schema and the values it is installed with are written from it, until
core can read a data definition as the schema
(why: [decision](decisions.md#a-config-object-is-described-by-its-resource)):

```bash
drush surface:config-schema --module=my_module
drush surface:config-schema --module=my_module --write
```

The first writes nothing: it prints how each file differs from what the
resources declare, as a unified diff, and fails when one does, so a
check can run it. The second writes them; commit what it writes. Without
`--module` it names every installed module owning a resource stored in
a simple config object, or writing an entry for one. An object another
module provides (its name's first part is that module) keeps its
owner's schema, which the resource is held to, and no entry is written
for it (why: [decision](decisions.md#the-generator-writes-what-the-first-page-shows)).

A module named need not be installed. Its resources, structures, shapes
and alters are read from its `src/`, as discovery reads an installed
module's, and its alters, storage and access classes are built as the
container would build them; nothing is installed and nothing is cached.
So the order for a new module is: write the classes, write the files,
install once, and the install reads the schema and the install file it
needs. A module that mounts keys on another is named with it, and one
run writes both modules' files, the owner's schema typing the mount,
with nothing printed between them:

```bash
drush surface:config-schema --module=my_module,my_module_extras --write
drush pm:install my_module my_module_extras
drush surface:config-schema --module=my_module,my_module_extras
```

The last line is the drift check: after a change to a resource or an
alter, it fails until the files are written again. For each such
resource the command writes:

- **The object's entry** in `config/schema/<module>.schema.yml`, a
  `config_object` that is fully validatable, its label kept from the
  entry it replaces (the resource has none), and its mapping in
  declaration order.
- **Each key** typed from its definition (`string`, `email`, `integer`,
  `float`, `boolean`, `uri`, `timestamp`, or the primitive its typed
  data class holds), labeled with the label its owner gave it, whatever
  an alter reworded. A key the storage keeps otherwise than it is asked
  for is written as stored: what the storage's layout says of it, or
  `uuid` for a key that refers to a content entity
  ([translated keys](#a-key-stored-otherwise-than-it-is-asked-for)). A multi-line string is a `string` too: config
  schema's `text` is a string marked translatable, and a resource cannot
  say yet that a key is translatable, so nothing is written as `text`.
- **`nullable: true`** where null clears, by the three-way rule: on an
  optional key, a single value or a list, unless it is a secret or the
  storage always holds it; and on a required single value installed
  holding nothing. A map holds its keys. The storage subclass names the
  optional keys it always holds, by dotted path, beside the object:

  ```php
  final class DoorsStorage extends ConfigObjectStorage {

    public const CONFIG = 'surface_examples.doors';

    public const ALWAYS_HELD = ['badges', 'greeters'];

  }
  ```
- **The bounds and patterns a schema can carry**: `Length`, `Range`,
  `Regex`, `Count` and `NotBlank`, their messages left to the resource.
  A list of values (`Choice`, `LabeledChoice`) is not written: a value a
  list no longer offers is kept as [stale](semantics.md#stale-values-the-third-state)
  and must not make the object refuse a write to another key. A key a
  rule may rule out carries none: the rule holds only where the key
  applies.
- **A child** as a `mapping` of its keys, a collection as a `sequence` of
  them, keys from the site as a `sequence` of what each entry is (each
  key of an entry free to be absent, since an entry is stored as it was
  accepted), and variants as a type their deciding key names,
  `<config>.<key>.[%parent.<deciding key>]`, each variant's mapping
  written in the schema file of the module that declares it, with the
  secrets of the other variants, which a switch keeps, as keys that may
  be absent. Variants from the site are `type: ignore`: which there are
  is the site's, so no schema written once can name them, and the chosen
  variant holds the value to its own keys before anything is written
  ([Variants from the site](resources.md#variants-from-the-site)); the
  check that a resource offers nothing its schema refuses reads `ignore`
  as refusing nothing. A closed provider (`ClosedSiteListInterface`)
  names every structure it may list, so its variants are written as a
  class's are, each a type of its own, whichever the site offers when
  the file is written (example 14's credential).
- **The install file**, `config/install/<config>.yml`: every key the
  object stores, in declaration order, the value the file already gives
  it (what a site starts from, which may differ from the default), the
  declared default for a key the file does not give, and nothing (`~`,
  or `[]` for a list) for one with neither.

The other entries in the file, and the comments above an entry, are
kept as they are; each entry written says so on the line above it. A
test holds the shipped ones to it, `ConfigSchemaDriftTest`, which also
writes the test modules' files (they are never installed on a site) when
run with `SURFACE_CONFIG_SCHEMA_WRITE=1`.

What fully validatable asks, which the generated schema does: a type on
every key, since config validation lets data no schema describes through
without a word; every key stored ("'notes' is a required key.") unless
it says `requiredKey: false`, so the install file carries every key;
`nullable: true` on each key that may hold nothing, and only there; a
sequence stores `[]`, and `nullable: true` on one only lets a schema
written for an object that was not fully validatable keep what it said.
To check a stored object, ask core's typed config:
`\Drupal::service('config.typed')->get('<name>')->validate()` answers
no violation. The test module's opening hours, whose recipes are
[Common settings recipes](recipes.md), always hold the after-hours flag,
the keywords and the holidays, which `OpeningHoursStorage::ALWAYS_HELD`
names, and may hold nothing for the notes and for the door code, which
is installed holding nothing:

<!-- yaml: tests/modules/surface_resource_test/config/schema/surface_resource_test.schema.yml#surface_resource_test.opening_hours -->

```yaml
surface_resource_test.opening_hours:
  type: config_object
  label: 'Opening hours'
  constraints:
    FullyValidatable: ~
  mapping:
    opens:
      type: string
      label: 'Opens at'
      constraints:
        Regex:
          pattern: '/^([01]\d|2[0-3]):[0-5]\d$/D'
    closes:
      type: integer
      label: 'Closes at, in minutes after midnight'
      constraints:
        Range:
          min: 721
          max: 1439
    days:
      type: sequence
      label: 'Days open'
      sequence:
        type: string
        label: 'Days open'
    notes:
      type: string
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
        label: 'After-hours days'
    keywords:
      type: sequence
      label: 'Keywords'
      sequence:
        type: string
        label: 'Keywords'
    holidays:
      type: string
      label: 'On public holidays'
```

The values are stored as the resource accepted them, key for key: an
attached structure is the map at its key, and the variants at a key
are the map of the variant the deciding key chose. Two keys are not:
one the storage's layout translates, and one that refers to a content
entity, stored by uuid ([below](#a-reference-is-stored-by-uuid)).

A simple config object records no dependencies; core has no place for
them ([core gaps](core-gaps.md#smaller-gaps-outside-the-sequence)). So
an export or an uninstall does not know what a config object's
references name, and the module does not invent a record of it.

### A schema the module did not generate

Core's `node.type.*`, or an adopter's schema written by hand, says what
storage refuses, and a resource stored to it must not advertise more. A
storage holding what it writes to a config schema implements
`Resource\HeldToConfigSchemaInterface`, as `ConfigObjectStorage` does
and as the node type example's `NodeTypeStorage` does for `node.type.*`,
most simply with `Storage\HeldToConfigSchemaTrait`, and the build asks it
once per resource and context. It holds each key to what the schema
states for it:

- **Type**: the same primitive (an integer where the schema says
  `float`), a map where it says a mapping, a list where it says a
  sequence; a key the schema does not describe is refused too.
- **Nullability**: under a fully validatable root, a key the schema does
  not call nullable must hold a value, required or always held. A storage
  that knows which keys those are says so (`KnowsClearableKeysInterface`),
  as `ConfigObjectStorage` and `NodeTypeStorage` do from the schema.
- **Constraints**: `Regex` (either way it matches), `Length`, `Range`,
  `Choice`, `Count`, `NotBlank` and `Email`, each implied by the
  resource's own constraint of that name, or by a choice list every
  value of which passes it. `NotBlank` with `allowNull` is implied by
  the three-way rule itself, since an empty string is nothing, which
  clears. Any other constraint is not read, a pattern other than the
  schema's (or the schema's with `D` added) is read as wider, and a type
  named by its data otherwise than by the deciding key or `[%key]` is
  not followed.

Where the resource is wider, the build refuses it, naming each key:

```
The schema_test.narrowed resource advertises more than
surface_schema_test.narrowed, the config schema
Drupal\surface_schema_test\Storage\NarrowedStorage holds what it
stores to, so storage would refuse what every door offers. Where:
"code": the schema refuses a value that does not match /^[a-z0-9_]+$/,
which the resource does not; "limit": it may be more than 10, which the
schema refuses; ...
```

Narrow the resource to what the schema states: the node type resource
states core's patterns for the name (a label refuses control
characters), the description and the help (text keeps line breaks and
tabs), and its storage says from the schema that the two booleans always
hold a value. A generated schema passes by construction; one generated
before the resource changed is refused until it is written again, and
the command builds the resource without this check, since the schema on
disk is what it replaces.

#### A key stored under a path

Core's config objects keep settings that several forms edit, each at a
path: `system.performance` keeps the page cache's maximum age at
`cache.page.max_age`, beside `css.compress` and `fast_404`, which other
code reads. A resource declares the keys it edits by their own names,
and its storage says where each one is kept, in the `STORED_AT`
constant beside `CONFIG`:

```php
final class PerformanceStorage extends ConfigObjectStorage {

  public const CONFIG = 'system.performance';

  public const STORED_AT = [
    'max_age' => 'cache.page.max_age',
    'css_preprocess' => 'css.preprocess',
    'js_preprocess' => 'js.preprocess',
  ];

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **`load()`** reads each such key from its path.
- **`prepare()`** lays each value into the stored object at its path,
  so the schema judges the whole object as it will be stored, and files
  what the schema says at the path under the resource's key. It returns
  the key by its path (`cache.page.max_age`), which a dry run reports.
- **`commit()`** sets the path alone, so a key the resource does not
  declare under the same map (`css.compress`) stays as it was.
- **`mayClear()`** and the build's check read the schema at the path,
  through each nested `mapping`, so the check compares the key with the
  property it is stored in and a refusal names the key.
- **The config schema generator writes no schema** for such an object:
  it writes each key at its own name, so it refuses a storage with
  `STORED_AT` by name rather than write a wrong one. A key kept under a
  path belongs to a schema its owner wrote, which the build holds the
  resource to.

`surface_examples_performance` is the shipped case: three settings of
core's performance form, stored in core's `system.performance` and held
to core's schema as core wrote it
(why: [decision](decisions.md#a-resource-may-hold-some-keys-of-a-config-object-it-does-not-own)).

#### A key stored otherwise than it is asked for

A key the resource asks for one way and the storage keeps another (a
checkbox per type, stored as the list of the types checked) is
translated by the storage's layout, and the layout is where the check
and the generator learn the stored shape. The storage answers
`translatedKeys($built)`: the config schema of each such key as it is
stored, by its dotted path. It holds two things:

- What the storage's layout says of the keys it translates, when the
  layout implements `StoredSchemaInterface`.
- Each key that refers to one content entity type, typed `uuid`
  ([below](#a-reference-is-stored-by-uuid)).

The check skips each key it names, and the generator writes the schema
it gives in place of the asked one, labeled with the key's own label
where the schema gives none, and `nullable: true` on a key asked as one
value by the three-way rule. A key the storage keeps under another name
is still told with `configSchemaPaths()`, which answers the schema's
property for each key. The recipe
[Asked as checkboxes, stored as a list of ids](recipes.md#asked-as-checkboxes-stored-as-a-list-of-ids)
shows all of it
(why: [decision](decisions.md#a-layout-is-the-storages-one-word-on-its-shape)).

#### A reference is stored by uuid

A key that refers to a content entity (`entity:user`,
`entity:node:article`) holds its id at every door, and a config storage,
`ConfigObjectStorage` or `ConfigEntityStorage`, stores the entity's
uuid. A content entity's id differs between sites, so config holding it
would name another entity, or none, once deployed; the uuid is the same
on both. A config entity's id is its machine name, the same on every
site, so a reference to one is stored as its id. Example 16's:

```yaml
venue: riverside
contact: 098fef00-a494-4fde-af2b-8e1f5b59a6a2
page: 3ee32f45-6a1c-487f-8501-2254c794e0b7
thing:
  entity_type: user
  id: 098fef00-a494-4fde-af2b-8e1f5b59a6a2
setting:
  entity_type: user_role
  id: event_sponsorship_editor
```

- **The engine translates**, not the storage class: the stock
  `ReferenceStorageLayout`, applied by `StorageAdapter` outside any
  layout the storage declares, in the layout values are asked for. A
  storage class sees the uuid and writes nothing for it.
- **Where.** At the top, inside an attached structure or a map, in each
  item of a collection, in each entry of a map of keys from the site
  (`addEach()`, `attachEach()`), in the variant a deciding key chose
  among variants a class declares, and in what another module mounts.
  Variants the site lists are stored open (`type: ignore`) and keep the
  id.
- **The bare `entity` key** is stored as `{entity_type, id}` whatever
  type it holds: `id` is the uuid for a content entity type and the id
  for a config entity type. Its schema is two strings either way.
- **The schema** types a key that refers to one content entity type
  `uuid`, core's type with its `Uuid` constraint; the generator writes
  it so, and the check does not compare it with the integer the key is
  asked as.
- **A uuid that names nothing** (the entity was deleted, or is not on
  this site yet) reads as nothing stored, a bare key's two parts both,
  at every door. A write that holds nothing for it keeps the stored
  uuid, as a [stale](semantics.md#stale-values-the-third-state) value is
  kept, so it names its entity again once that entity is there; a new
  choice replaces it.
- **An id stored before**, or written into a module's install file by
  hand, is read as the id, while the entity exists, and written as the
  uuid at the next write. Config validation still
  names it, since the schema's `Uuid` refuses it. A module that stored
  ids converts them in a post-update:
  `surface_store_references_by_uuid(MyResource::class, 'configure')`
  reads and writes the object through its storage.
- **To keep the id**, the key says so:
  `->setSetting(References::STORED_AS, References::BY_ID)` (on a bare
  key, on the key itself). That is right only where the config never
  leaves the site, or where every site gives the entity the same id by
  other means (a migration that keeps ids).

The values a dry run reports are the storage's, so they show the uuid.

### Keys another module mounts

An alter's keys are mounted under `third_party_settings.<module>`
([Alters](resources.md#alters)), and on a simple config object core
describes no such key: the owner declares it, and each module that
mounts there describes its own part. Generated, the owner's schema gains
`third_party_settings` when a module mounting there is installed, or
named in the same run, while it is written, a sequence whose type is
named by the module key, and the mounting module's schema gains its
part, fully validatable, each key free to be absent, since a mounted key
is stored only once it is sent holding something other than its default
(a form posts every key it draws, and one left at its default is not
written). Name both modules in one run
(`--module=my_module,my_module_extras`): run for the owner alone while
the mounting module is installed and its part is not written yet, and
the owner advertises a mount no schema describes until the second run.
Example 3's, from the compliance module:

```yaml
# The owner, surface_examples.registration_step3:
third_party_settings:
  type: sequence
  label: 'Third party settings'
  requiredKey: false
  sequence:
    type: 'surface_examples.registration_step3.third_party.[%key]'
```

<!-- code: modules/surface_examples_compliance/config/schema/surface_examples_compliance.schema.yml -->

```yaml
# Generated from the registration.step3 resource by drush surface:config-schema.
surface_examples.registration_step3.third_party.surface_examples_compliance:
  type: mapping
  label: 'Compliance'
  constraints:
    FullyValidatable: ~
  mapping:
    license:
      type: string
      label: 'Event license'
      nullable: true
      requiredKey: false
      constraints:
        Regex:
          pattern: '/^\d{4}$/D'
    stewards:
      type: integer
      label: 'Stewards'
      requiredKey: false
```

A schema written by hand follows the same convention. Config validation
lets data no schema describes through without a word, so
`ConfigObjectStorage` checks the mounts itself: a module whose settings
resolve to no type is refused under `third_party_settings`, naming the
module; an owner that declares no `third_party_settings` at all is
refused by the schema under that key. A structure an alter attaches at
its mount is written the same way, its keys the mount's
([Reusing a structure](resources.md#reusing-a-structure-from-another-module)).
An alter that stores its mount otherwise than it asks
(`AltersStorageLayoutInterface`), with a layout that says its stored
schema (`StoredSchemaInterface`), is read by the check and the generator
as the storage's own layout is, each key at
`third_party_settings.<module>.<key>`.

### A config entity

A resource whose thing is a config entity (a placed block, a content
type) names a subclass of `ConfigEntityStorage`, which says the entity
type and the property each key is stored under:

```php
final class BlockPlacementStorage extends ConfigEntityStorage {

  public const ENTITY_TYPE_ID = 'block';

  public const PROPERTIES = ['id', 'status', 'weight', 'theme', 'region', 'plugin'];

}
```

A plain entry is a key stored under its own name, `'name' => 'label'` a
key stored under another, and `'kind' => ['method' => 'setKind']` a key
set through a setter the entity offers, the spelling core's config
actions use. The key mapped to the entity type's id key is its identity:
`load()` reads the entity the context knows by it, and nothing for a
context that creates.

- **`prepare()`** builds the entity it would save, unsaved: a copy of the
  stored one made from its exported array (a clone shares the plugin
  collections of the entity the request holds, and shaping settings
  onto it would reach that entity's plugins), or a new one under the id
  the values carry, refused when the id is in use (`taken`, under the
  identity key). It shapes the values onto it, calculates the
  dependencies a save would, and holds the whole entity to its config
  schema, which for a config entity is its validation, filing each
  violation under the key that owns the property. It returns the
  entity's exported array.
- **The dependencies of what its keys refer to** are added to that
  array by the engine, as core's own config entities name them: a
  config entity by its config name under `config`
  (`user.role.editor`), a content entity as `<entity type>:<bundle>:<uuid>`
  under `content` (`node:page:3ee32f45-...`). An export orders the
  entity after them, and deleting a role removes, or asks to fix, the
  entity that depends on it.
- **`commit()`** saves that array, the uuid the rehearsal handed out
  included, so what a dry run showed is what is stored. Core recalculates
  a config entity's dependencies on every save, from its plugins and
  third party settings; the save keeps the ones its references bring
  (`ReferenceDependencies`, through a presave hook). A save made any
  other way, a form of core's or `$entity->save()`, recalculates without
  them until the next save through the storage.
- **`prepareDelete()` and `delete()`** remove the entity the context
  names, for an operation that deletes
  (why: [decision](decisions.md#a-placed-block-is-a-resource)).
- **`mayClear()` and `widerThanSchema()`** read the entity's config
  schema, `<config prefix>.*`, for the keys stored under a property: the
  build refuses a resource that advertises what that schema refuses.
- **Third party settings** arrive under `third_party_settings.<module>`
  and are written with `setThirdPartySetting()`, where core keeps them.

A key the map leaves out is the subclass's: it overrides `fromEntity()`
and `toEntity()`. `BlockPlacementStorage` keeps the label and the
plugin's own settings in the block's settings array, and the visibility
as core's map of conditions; `NodeTypeStorage` reads the description
and the help through their getters and writes a content type's base
field overrides beside it.

Each class's `use` line: [Namespaces](concepts.md#namespaces).

**An entity type of your own** (example 15). A module that declares a
config entity type puts a resource over it from the start: the entity
class carries `#[ConfigEntityType]` with its keys and `config_export`,
and no form classes, no list builder and no links, since the resource's
operations are what create, update and delete one, at every door. Its config
schema is written from the resource by `drush surface:config-schema
--write`, as a config object's is: for a resource whose
`ConfigEntityStorage` names an entity type its own module declares, the
command writes the entry `<module>.<config prefix>.*`, a `config_entity`
that is fully validatable, its mapping each key the storage maps to a
property, by the property's name, by the rules a config object's keys
follow, so nothing generated is translatable, the label included.
The storage class's `ALWAYS_HELD` names the optional keys written as
never null, as `ConfigObjectStorage`'s does. No install file is written:
the things a module ships are config of their own, which no resource
describes. An entity type of core or of another module (the block, the
content type) has a schema of its own and is left alone. Example 15's:

```yaml
# Generated from the event.venue resource by drush surface:config-schema.
surface_examples.event_venue.*:
  type: config_entity
  label: 'Venue'
  constraints:
    FullyValidatable: ~
  mapping:
    id:
      type: string
      label: 'Machine name'
      constraints:
        Regex:
          pattern: '/^[a-z0-9_]+$/D'
        Length:
          max: 32
    label:
      type: string
      label: 'Name'
      # ...
```

The storage may read and write more than one thing for an operation
that names no thing: `VenueStorage` answers a listing from every venue
in its `prepare()` and writes a reorder's weights to each venue it names
in one `commit()`
(why: [decision](decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection)).

### Keys a mixed-in structure brings

A structure [mixed in](resources.md#mixins) stores nothing itself
either, and its members are the owner's keys, stored flat beside the
owner's own, so the owner's schema lists them among its own, as any key
it declares, and the generator writes them so. A schema written by hand
cannot inherit a mapping of them (a simple config object's mapping
extends `config_object` already, and a mapping takes one parent), so it
repeats the members.

A member the structure's access omits for the account writing is kept
as stored, at the owner's level or inside a child the owner stores.

### The site's defaults are not stored values

A site's own defaults live in their own config object,
`surface.defaults`, which the build reads and no storage writes
([A site's own defaults](declaring-a-resource.md#a-sites-own-defaults)).
A default is what a key with nothing stored starts from: changing one
changes a new thing and a key no write has set, never a value already
stored, and the install file written from the resource keeps the
module's defaults. Set them with `drush config:set surface.defaults`;
a config export carries them with the rest of the site's configuration.

### Config overrides change what the site runs on

A `settings.php` override (`$config['surface_examples_first.newsletter']['frequency']
= 'monthly';`) and config split change what the site runs on, not what
is stored. The form, the read tool and both documents show the stored
value, as core's own config forms do (they load the editable object,
which no override reaches), and the pipeline writes the stored value
and holds it to the schema. A key overridden on a site therefore reads
here differently from what the site runs on; that is core's behavior,
and on purpose: a form that showed the override would save it into
storage on the next submit, and the override would stop being one. To
see it, `drush config:get surface_examples_first.newsletter --include-overridden`
prints the value with overrides applied, beside the stored one plain
`drush config:get` prints.

## What storage is

Two interfaces with the same three verbs. A resource's own storage class
implements the second, and nothing an author writes implements the
first:

- `Drupal\surface\Pipeline\SurfaceStorageInterface`, what the
  pipeline writes to, over the data surface. The engine's storage
  classes below implement it, all but `ConfigObjectStorage` and
  `ConfigEntityStorage`, and `StorageAdapter` adapts a resource's
  storage to it.
- `Drupal\surface\Resource\ResourceStorageInterface`, what
  `#[Resource(storage:)]` names, over the context; its write verbs,
  `prepare()` and `commit()` (and a deleting storage's
  `prepareDelete()` and `delete()`), are handed the account the write
  is made as, and it never reads the current user
  ([No door-specific context](resources.md#no-door-specific-context)).

The pipeline's:

| Method | Does | Writes? |
| --- | --- | --- |
| `load($surface)` | Reads what is stored, in input layout. | no |
| `prepare($surface, $values)` | Turns accepted values into the storage layout and hands back a `PreparedValues`. | no |
| `commit($prepared)` | Stores the artifact. | yes |

The data surface is an argument rather than something the storage
holds. Storage describes a destination and a data surface describes
values: the same node type is the destination of the create operation and
the update operation, and storage holding the built resource for one of
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
`BlockPlacementStorage` and `NodeTypeStorage` extend `ConfigEntityStorage`,
and `FieldInstanceStorage`, `FieldStorageStorage` and
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
$resources = \Drupal::service('surface.resources');
$context = $resources->operation(NodeTypeResource::class, 'update', ['type' => 'article']);
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
| Any configurable plugin (block, condition, action) | `PluginConfigurationStorage` over itself, from `SurfaceHostFormTrait`: the one construction path for the family, which the form submit asks for rather than building inline. |
| Field types | `FieldSettingsStorage` over the field config entity the item is bound to, from `SurfaceFieldTypeTrait`. A field type whose storage layout differs overrides `getSurfaceStorage()` and hands `FieldSettingsStorage` a layout. |
| Field formatters | Nothing: they throw. |

### The refusal, and why it is a refusal

`ResourcesInterface::storage()` throws `\LogicException` for a class
that names no storage, and a host whose values have no destination of
its own throws from `getSurfaceStorage()`. Two kinds legitimately do:

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

A create operation knows no identity, because the thing it creates does
not exist to be named, and yet its destination may depend on what is
being created. The node type example is the worked example: a content
type's base field overrides belong to a bundle, and the bundle IS the
machine name being submitted.

The answer is that storage reads the identity in `prepare()`, not
before. `NodeTypeStorage` loads by the machine name the context knows,
and on a create, where it knows none, loads nothing; `prepare()` builds the
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

`SurfacePipelineInterface::submit()` takes a `$dry_run` flag: it runs
everything up to and including prepare and hands back the artifact with
`committed` false.

Config entities write through the container's config factory, so an
entity cannot be pointed at another bin for one call. An unsaved clone,
held to the schema, is as far as a dry run goes; a resource's storage
class returns that clone's exported array from `prepare()`, which a
PHP caller reads on the result; a derived tool's dry run answers the
accepted values instead (why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects)).

## The engine storage classes

| Storage | Destination | Artifact | Used by |
| --- | --- | --- | --- |
| `ConfigObjectStorage` | One simple config object, named by a subclass, laid out by its `layout()`, a key kept under a path by its `STORED_AT`. A `ResourceStorageInterface`, named by a resource. | The values to write, in storage layout, a key kept under a path by that path, references to content by uuid, plus the language code when the schema asks for one. | the examples, `surface_examples_first`, `surface_examples_performance` |
| `ConfigEntityStorage` | One config entity, named by a subclass with its properties, plus core's third party settings. A `ResourceStorageInterface` that can delete, named by a resource. | The entity's exported array, references to content by uuid, its dependencies calculated, those of what its keys refer to among them. | `BlockPlacementStorage`, `NodeTypeStorage` |
| `StateStorage` | One State key. | The accepted values. | the example form |
| `FieldSettingsStorage` | One field instance's settings. | The settings array. | the field type host |
| `BaseFieldOverrideStorage` | A bundle's overrides of an entity type's base fields. | The overrides to save, as exported arrays, and the fields whose override goes. | `NodeTypeStorage` |
| `PluginConfigurationStorage` | A plugin's configuration array. | The complete configuration. | the plugin hosts |
| `CompositeStorage` | Several of the above, in order. | One prepared set per child. | no shipped resource yet |

`ConfigObjectStorage` came back once every settings resource had
written the same class: the examples' three, and the one the first
fresh-eyes reader had to write ([the log](fresh-eyes-2026-10-18.md)).
`ConfigEntityStorage` became one a resource names when the placed block
became a resource (2026-10-23); until then it was an engine class no
host used, with the same short name as core's
(why: [decision](decisions.md#a-placed-block-is-a-resource)).

The namespace says which kind a class is. A class in `Storage\` is one
you name on `#[Resource(storage:)]` or extend: `ConfigObjectStorage`
and `ConfigEntityStorage`, and beside them what such a class works
with, the layouts, the secret codec and `SchemaViolations`. The other
five implement the pipeline's
`SurfaceStorageInterface` and live beside it in `Pipeline\Storage\`
(`use Drupal\surface\Pipeline\Storage\StateStorage;`): a plugin's
host supplies one, and a resource's storage class may delegate to one,
but `#[Resource(storage: StateStorage::class)]` is refused, since it is
no `ResourceStorageInterface`.

A few things each of them is opinionated about:

- **`ConfigEntityStorage`** maps an input key to an entity property
  name, or to `['method' => 'setX']` for a property that needs a real
  setter. The method form is a string rather than a callable on purpose:
  it is a constant on a class, and it is the spelling core's config
  actions use, so a key a resource can write is a key a recipe can
  write. A setter is asked only for a value the entity does not hold
  already.
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
  so on a create operation the storage that creates the bundle must be
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

`Drupal\surface\Storage\StorageLayoutInterface` has two methods,
`toStorage(array $values): array` and `fromStorage(array $settings):
array`. `FieldSettingsStorage` takes one, optionally, and a
`ConfigObjectStorage` subclass answers one from `layout()`; without one
the input layout is the storage layout. It is also where encryption
sits, below.

A config object's layout may implement `StoredSchemaInterface`, whose
`storedSchema()` says the config schema of each key it translates, as
stored. That is the storage's one word on its shape: `load()` and
`prepare()` translate through the layout, the build's check skips each
key it names, and the generator writes its schema for them
([A key stored otherwise than it is asked
for](#a-key-stored-otherwise-than-it-is-asked-for)). It sits beside
`StorageLayoutInterface` rather than on it, since a field type's layout
and an alter's are stored under a schema their owner writes, which no
generator reads.

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
cannot the implementation says so in its own documentation. The one
layout that reads the site is the engine's own `ReferenceStorageLayout`,
since what entity a uuid names is site state; it reads it the same way
for every door.

For how this fits with the other kinds of transformation, see
[Transformations](transformations.md).

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

`surface_examples_extras` is the worked example: `NodeTypeAlter` asks
for a content type's review deadline as an amount and a unit, and its
`ReviewDeadlineLayout` stores seconds, through `NodeTypeStorage`, which
knows nothing about it. `StorageAdapter` applies the layout for a
resource's own storage class, `ConfigEntityStorage` and its subclasses
among them; none of the engine's storage classes writes third party
settings.

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
replaces the `surface.secret_codec` service with one backed by a key
manager, an HSM or a cloud KMS, and nothing else changes: no resource,
no structure, no storage class, no form. The envelope is
`surface:sodium:v1:` followed by base64 of the nonce and
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
| Field formatter | `#surface` (plugin id), `#surface_field_name`, `#surface_current` | the entity display the host form is editing, which has the real field definition; failing that, the formatter plugin manager |
| Field type | `#surface` (field config id), `#surface_current` | the field config the host form is editing, which is also the only way to find an unsaved one; failing that, a load by id |

`#surface_current` is the values the form started from, and it is an
array, so it stores as it is. It is there because a key with no rendered
element has said nothing and must keep what is stored rather than fall
back to its declared default.

Every storage class also composes core's `DependencySerializationTrait`,
as a safety net for adopters who do put one on a form: its services
then store as service ids and come back as the container's own objects
rather than as dead copies. One of them cannot be made fully safe that
way, because what it holds is not a service: `FieldSettingsStorage`
carries a config entity. The rule for it is the one it documents: hand
it an object you own. `ConfigEntityStorage` holds none: it loads the
entity its context names, at each verb.

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

An engine storage class, a `SurfaceStorageInterface` the pipeline
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
