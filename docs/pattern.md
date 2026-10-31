# The resource pattern

This page explains why a resource is written the way it is: one class
declares the keys, a storage class keeps the values, and operations say
how they are asked for. Read it after [the introduction](introduction/index.md)
when you want the reasons behind that shape;
[Resources](introduction/02-resources.md),
[Structures](introduction/03-structures.md) and
[Sealed](introduction/16-sealed.md) define its words.

A resource is a thing with identity and storage that operations read and
write; the form, the tool and the schema are derived from it. Before
anything is saved, a person, a route, a tool or an agent can ask it what
it will accept right now, on this site, for this thing, and all of them
get the same answer.

A resource may live anywhere: in a config object, a config entity,
State, a plugin's settings or a content entity. Only its storage knows
which. The examples' registration settings are one config object, a
resource with no identity keys: a resource with no identifiers, such as
a settings object, is a singleton resource. A content type is a config
entity, a resource whose identity is its machine name. A basic page is
the same shape applied to content: `entity.content:node.page`, whose
identity is the entity type, the bundle and the id, and whose storage is
the content entity. Nothing in a resource's class says which of these
it is; its storage class does.

A structure is the other kind: a group of keys with no identity or
storage of its own, held by a parent (attached or as a variant) or a
plugin's host.

This page is the pattern: what an author writes, where it goes, and why
it is shaped that way. [Resources and structures as classes](resources.md) is the reference for each part, [How it fits](how-it-fits.md) walks one build from caller to sealed data
surface, and [Decisions](decisions.md) lists the points the pattern left
open and how the module settled them.

The worked example throughout is the field: `FieldInstanceResource` in
`surface_examples_field`, with its storage, `FieldStorageResource`, and the
address module's `AddressFieldSettingsResource` filling its settings.

## Where things live

A module has three predictable directories: two for what it owns, one
for where it reaches into something it does not own.

| | Owns | Reaches into |
| --- | --- | --- |
| **Resources and structures** | `src/Resource/`, `src/Structure/` | `src/Alter/` |

All three are scanned in every enabled module, the way core scans
`src/Hook`, so nothing is registered by hand. A class carrying
`#[Resource]` or `#[Structure]` is found in either of the first two: the
attribute decides the kind, and the directory is for the reader. A
resource or structure is never instantiated: its `defineInputs()` and its refiners
are static methods, asked of the class. An alter is registered as an
autowired service and may hold services. A plugin may also be its own
structure, with the same static methods on the plugin class; it is found
through its plugin definition rather than a directory
([Plugins](#plugins)).

Two more kinds of class are named by a resource or structure rather than
discovered, so their directory is a suggestion, not a rule: storage
classes (`src/Storage/`) and run-time access (`src/Access/`). Both are
registered as autowired services when the class naming them is found.

```
src/Resource/                         the authoring API, in this module
src/Resource/Attribute/               #[Resource], #[Structure], #[Operation],
                                      #[RefinesInput], #[Alters], #[Variant],
                                      #[UsesStructure], #[DerivesResources]

modules/surface_examples_field/src/
  Resource/FieldInstanceResource.php  the generic field resource
  Resource/FieldStorageResource.php   its storage, attached at `storage`
  Storage/FieldInstanceStorage.php    where its values go
  Storage/FieldStorageStorage.php
  Access/FieldInstanceAccess.php      the part of access that needs the subject
  FieldSettingsSchemaVariants.php     settings for field types with no class

modules/surface_examples_plugins/src/
  Plugin/Block/ExampleBlock.php    a plugin: #[UsesStructure], and build()
  Structure/ExampleBlockStructure.php         its configuration, as a structure
  Structure/ListPresentationStructure.php  the block's presentation variants
  Structure/GridPresentationStructure.php
  Plugin/Field/FieldFormatter/ExampleFormatter.php
                                           a plugin that is its own structure

modules/surface_examples_address/src/
  Resource/AddressFieldSettingsResource.php  a variant at the field's settings
  Storage/AddressFieldSettingsStorage.php    and stores them itself

modules/surface_examples_node_type/src/
  Resource/NodeTypeResource.php       a content type, with create and update

modules/surface_examples_extras/src/
  Alter/ExampleBlockAlter.php            adds a key, rewords one, narrows one
  Alter/ExampleFormatterAlter.php        offers one more value on a fixed list
  Alter/NodeTypeAlter.php             adds keys stored in another layout
```

## The pattern

A resource or structure is a class with one static method that declares
its members, and one named static method per key whose allowed values
depend on another key. An alter is a class with the same two jobs and a
narrower tool; it is a service, so its `alterInputs()` is an instance
method.

| | Blueprint | Values |
| --- | --- | --- |
| **Owner** | `static defineInputs($inputs)`, optionally `static defineOutputs($outputs)` | static `#[RefinesInput('key')]` methods |
| **Another module** | `alterInputs($inputs)` | `#[RefinesInput('key')]` methods |

- **`defineInputs()` and `alterInputs()` get a blueprint to fill, and nothing else**: no
  values, no context, no services. On a resource or structure they are
  static, so the language holds them to that: the build step calls them
  on the class and never makes an instance. The owner gets
  `BlueprintInterface`; an alter gets `BlueprintAdditionsInterface`, which is
  the same minus `attachVariants()` and `reads()`, and whose `attach()` places a
  structure at the alter's own mount and nowhere else, while
  `attachList()` refuses by name for now: an alter's keys are mounted
  under its module, and a collection inside a mount is not built
  (why: [decision](decisions.md#an-alter-attaches-at-its-own-mount)). An alter can add keys, reword a label or
  description with `describe()` (and, on its own mount, say where its
  fieldset is drawn), and offer more values on a fixed choice list with
  `extendChoices()`. It cannot remove a key or change its type. Inputs
  and outputs use the same two interfaces.
- **A `#[RefinesInput('bundle')]` method takes the key's definition
  first, then one parameter per sibling it watches**, matched by name
  to that sibling's key: one that names no declared key fails at build,
  so a renamed parameter never silently stops watching. Its signature is its dependency declaration, the way an
  operation method's is. `ExampleBlockStructure::fieldOfBundle()` watches
  the entity type and the bundle. It runs once every watched sibling has
  a value (a key its alter mounted is never waited for), and again when
  any of them changes. It returns the definition tightened with plain
  core API, and the framework checks the result is narrower, so a
  refinement cannot widen, retype, or add or remove a map property. It
  receives only what it named, so it is a pure function of its
  arguments, unless it declares itself a runtime refiner
  (`runtime: TRUE`): a rule no schema can state, such as a lookup or
  uniqueness, which nothing runs ahead of time
  ([refinement](refinement.md#static-and-runtime-refiners)). **A static
  refiner is a static method**, the owner's and an alter's alike, so it
  holds no service; an alter's runtime refiner may be an instance
  method, because an alter is a service that may hold what it reads.
- **Labels are `t('...')`.** A static method has no instance for the
  translation service to be injected into, and the global `t()` returns
  the same lazy `TranslatableMarkup`, which string extraction finds.
  `new TranslatableMarkup` is kept for attribute arguments, where a
  function call is not allowed; an alter, a service, calls `$this->t()`
  outside its static refiners.

```php
#[Resource('field.instance',
  identity: ['entity_type', 'bundle', 'field_name', 'field_type'],
  storage: FieldInstanceStorage::class,
  access: FieldInstanceAccess::class,
)]
final class FieldInstanceResource implements ResourceInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    // Which field this is.
    $inputs->add('entity_type', 'string', t('Entity type'))->setRequired(TRUE);

    $inputs->add('bundle', 'string', t('Bundle'))->setRequired(TRUE);

    $inputs->add('field_type', 'string', t('Field type'))->setRequired(TRUE);

    $inputs->add('field_name', 'string', t('Machine name'))->setRequired(TRUE);

    // The field itself.
    $inputs->add('label', 'string', t('Label'))->setRequired(TRUE);

    $inputs->add('description', 'string', t('Help text'));

    $inputs->add('required', 'boolean', t('Required field'), default: FALSE);

    // Its parts.
    $inputs->attach('storage', FieldStorageResource::class)
      ->setLabel(t('Field storage'));

    $inputs->attachVariants('settings', by: 'field_type')
      ->setLabel(t('Field settings'));
  }

  /**
   * The bundle must belong to the chosen entity type.
   */
  #[RefinesInput('bundle')]
  public static function bundleOfEntityType(DataDefinitionInterface $bundle, string $entity_type): DataDefinitionInterface {
    return $bundle->addConstraint('EntityBundleExists', ['entityTypeId' => $entity_type]);
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

(The real class adds descriptions, a `PluginExists` on the entity type
and the machine name's own `Regex` and `Length`, and three operations,
below.)

## Reading a class

- **Everything on it is static.** `defineInputs()`, `defineOutputs()`,
  every `#[RefinesInput]` method and every `#[Operation]`. What it declares is a
  property of the class: nothing it says takes a value, a context or a
  service, and a static method cannot reach for one. No constructor, no
  properties, no `$this`.
- **`defineInputs()` is a flat list.** No `if`, no loop. Wanting one
  means a second class, a child, or an operation.
- **Name, type and label sit on one line.** `add()` takes those three
  and returns the core definition, so the rest is plain core API.
  `addList()` is the same for a list of values, and `addDefinition()`
  the long form, for a map. The label is
  `t('...')`, a literal string.
- **It is ordered like the thing it describes.** Identity first, the
  thing's own keys next, its parts last.
- **`defineInputs()` never mentions a sibling.** A constraint written
  there is fully known with no values. Anything that reads another
  key's value is a `#[RefinesInput]` method.
- **One refiner, one rule, one name.** `bundleOfEntityType()`,
  `fieldOfBundle()`, `shortInGrid()`. The docblock states the rule; the
  signature states what it reads.
- **A refiner trusts what it is handed.** A sibling value its own key
  refuses is never passed in: the engine treats it as unanswered
  (why: [decision](decisions.md#a-refiner-never-sees-an-invalid-sibling)).
  So a refiner asks whether a license is there, not whether it is well
  formed.
- **A Regex carries a message.** The pattern cannot be read by the
  person it refuses, so its `message` is what they are told, in the
  error and wherever the key's allowed values are explained
  (why: [decision](decisions.md#a-regex-is-explained-by-its-message)). A
  Regex without one is a smell: the panel can only show the pattern.
- **Everything named by class.** Children, variants, storage, access,
  the resource or structure an alter or operation belongs to: always
  `X::class`, never an instance or a string, so it is one click away.

## Children

A resource or structure attaches others at a key. Each child is its own
class, with its own keys and refiners, and alters can reach it alone.
A child is usually a structure; it may be a resource with storage of its
own, as the field's storage is.

- `attach()` is a fixed child. The field attaches its storage.
- `attachVariants()` attaches variants: children a sibling key chooses
  between. The field's `settings` are chosen by `field_type`, and so are
  the storage's own `settings`.
- `attachList()` is a collection: a list whose every item is the
  child, its Count the parent's
  ([Resources and structures](resources.md#collections)).
  `attachListBy()`, a list whose every item chooses its own child, is
  **deferred**; see [Deferred](#deferred).

Both return the map definition at the key, as `add()` returns its
definition, so the owner labels and describes its own child's key with
the core setters.

A child is invoked the way a resource asked on its own is: through one
of its own operations, the one its parent's context names, else the one
whose id is its parent's, else its default, and otherwise in its
parent's operation and identity, as before. That operation's permission
and the child's access class then decide whether the child is open,
locked or left out for the account
([Resources and structures](resources.md#child-operations)).

Variants are always **open**. The parent never names its children.
Each child marks itself with `#[Variant]`, naming the parent, the key
and the value it is for, so a new field type brings its settings with it
and the field resource never changes:

```php
#[Resource('field.settings.address', storage: AddressFieldSettingsStorage::class)]
#[Variant(of: FieldInstanceResource::class, at: 'settings', for: 'address')]
final class AddressFieldSettingsResource implements ResourceInterface {
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

The deciding key's allowed values become exactly the values the
variants are for. Variants may also come from the site: `from:` names a
provider (`SiteListInterface`) listing one per thing, each read from a
description that already exists. `FieldSettingsSchemaVariants` lists a
plain `string` or `integer` field's settings from
`field.field_settings.<field type>`, and
`FieldStorageSettingsSchemaVariants` its storage settings from
`field.storage_settings.<field type>`, so a string's `max_length` can be
set when the field is added. A declared variant always wins.

Underneath, the key is an `any` placeholder that the framework narrows
to the chosen variant's map once the deciding key has a value: an
ordinary refinement under the rule that `any` may become anything
narrower, not a new mechanism.

**The escape hatch.** A child whose keys cannot be listed at all is
the one case for a `#[RefinesInput]` method on an `any` key returning
the narrower definition itself, a map included. No verb is needed;
the test module's `DynamicChildStructure` is the example.

**The wall.** A child never reads its parent's values, and a parent
never refines or watches a key inside its child. When a child needs
something from its parent, the parent hands it over as identity in the
child's context, which is how the storage learns which field it belongs
to. That keeps every child buildable and testable on its own.

## Operations: create, reuse, update

A resource never mentions create or update. The difference between them is
not which keys there are. It is how much is already known.

- `#[Resource(identity: [...])]` names the **identity keys**: the input
  keys that say which thing this is. An identity key is settable until
  the context knows it, and locked from then on. It is on the attribute
  rather than in `defineInputs()` because identity is the address: what
  a catalog lists, what a tool or route must supply. A resource with
  no identity keys is a singleton.
- The ways a resource is asked for are **`#[Operation]` attributes**:
  an id, a label, a description, the static part of its access, and
  whether it creates. One that needs nothing more stands on the class
  and has no method (a settings object's `configure`); one whose
  context needs more is a **static method on the class**:
  `FieldInstanceResource::create()`, `::reuse()`, `::update()`. Each returns
  an `OperationContext` and knows more than the last; what it needs is
  its own signature. Whether it creates is on the attribute, stamped on
  the context, where the storage reads it.
- **A parameter is identity the caller knows.** An operation takes the
  identity keys it knows as parameters of the same names, never as a
  composite id: `create(string $entity_type, string $bundle)` names the
  bundle a field is added to, `update(string $entity_type, string
  $bundle, string $field_name)` the field, and every door names them
  alike, in the path in identity order
  ([decision](decisions.md#a-parameter-is-identity-the-caller-knows)).
- **On the owner's class only.** An operation stands on its
  resource's or structure's own class, found the way a hook method is,
  no interface needed; one on any other class is refused at discovery.

That makes operations listable ([the catalog](catalog.md)),
addressable (`field.instance:update`), and generatable: one route or one
tool per operation, its inputs being the parameters plus the resource's
open keys.

| Keys | `create()` | `reuse()` | `update()` |
| --- | --- | --- | --- |
| Entity type, bundle | locked | locked | locked |
| Field type, machine name | open | locked | locked |
| Label, help text, required | open | open | open, current values loaded |
| Storage (attached resource) | its own create | its own update | its own update |
| Field settings | chosen by field type | fixed by the known type | fixed by the known type |
| Storage settings | chosen by the storage's field type | fixed by the known type | fixed by the known type |

An operation can hand a child its own context (`withChild()`), or name
one of the child's operations for it (`withChild('storage',
operation: 'update', known: [...])`): reusing a storage is a create for
the field and an update for its storage.
An operation can also narrow (`withConstraint()`): the storage's update
operation, when the field has data, constrains cardinality not to
shrink. The rule: **what depends on where you are is the operation's;
what depends on what was entered is a refiner's.**

A caller that uses no operation passes a bare context: every identity
key stays open, and it is still the same resource.

## Allowed values

A key says what it allows on its own definition, as a constraint. The
blueprint has no verb for options.

| The list is | Constraint | Example |
| --- | --- | --- |
| Fixed | core `Choice`, or `LabeledChoice` for labels | `ExampleBlockStructure`'s `presentation` |
| Live, from site state | a constraint whose options resolver fetches the list | `SurfaceExamplesBundleField` on the example block's `field` |
| Already modelled by core | the core constraint | `EntityBundleExists` on `bundle`, attached by a refiner because it needs the entity type |

**A refiner never calls a service.** A list that depends on a value is
a constraint with the value as an option. The refiner points; the
options resolver fetches ([Options and resolvers](options.md)). That is
what lets a resource or structure stay a class of static methods. It is
also what lets the
[served contract](served-contract.md#exact-where-it-can-be) run a
refiner ahead of time for every value a listed sibling offers, so the
static JSON Schema is exact without a round trip wherever the answers
are finite.

A list can be changed in two places. Tightening one key of one class is
an alter's `#[RefinesInput]` method. Widening one key of one class is
`extendChoices()`, for fixed choice lists only; it is the one widening
verb. Changing a list everywhere it is used is an options alter, which
is deferred with options sources. Removing is deliberately absent.

## Alters

An alter says which resource or structure it applies to with
`#[Alters]` on its class, optionally naming the operations it applies
in. Static, like the variant and operation attributes, so a catalog
can say who alters a class without running anything.

```php
#[Alters(ExampleBlockStructure::class)]
final class ExampleBlockAlter implements AltersInputsInterface {

  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'), default: 'star');
    $inputs->describe('headline', description: $this->t('Shown above the featured content, beside its badge.'));
  }

  /**
   * In a grid, at most twenty items.
   */
  #[RefinesInput('limit')]
  public static function shortInGrid(DataDefinitionInterface $limit, string $presentation): DataDefinitionInterface {
    // ...
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- What an alter adds is mounted under its module,
  `third_party_settings.<module>.<key>`, so the owner's storage and
  schema never have to know it. Its `#[RefinesInput]` methods may
  refine and watch those keys by the names it added them under, and
  never another module's; a watched one is handed as it stands, NULL
  while unanswered.
- `describe()` rewords and arranges a key anyone declared: label,
  description and arrangement only, because that changes nothing about
  what is accepted.
- `extendChoices()` offers more values on a key whose owner declared a
  fixed list. The alter's `#[RefinesInput]` method on that key says what
  its own values mean and is handed only them, so it can never take the
  owner's away (`ExampleFormatterAlter`).
- An alter that only tightens implements no interface: the attribute is
  what makes it an alter.
- An alter may answer access, implementing `OperationAccessInterface`,
  the access class's interface, its role read from `#[Alters]`: it
  is asked after the owner's answer and the stricter stands, so it locks
  or leaves out and never opens
  (why: [decision](decisions.md#an-alter-may-answer-access-and-the-stricter-stands)).

[Refinement and contributions](refinement.md) has the rules.

## Storage

`#[Resource(storage:)]` names a `ResourceStorageInterface` with three
verbs:

- `load($context)` reads the current values, by the identity the
  context knows, so the context never carries an entity.
- `prepare($context, $values)` rehearses the write with every check
  storage would make (the config schema, a field type the storage could
  not take, a schema change a field with data could not take) and no
  side effects, and returns what would be stored, in storage's own
  layout. A dry run stops here and reports it.
- `commit($context, $prepared)` writes what prepare returned.

Whether to create or update is the context's `creates`. Storage composes
along the tree: the field's storage, an attached resource, has its own
storage class, committed before the field; the address settings have
their own, committed after it; a child with none, a structure or a
derived variant, is stored by its parent under its key.
[Storage](storage.md) has the engine side.

## Access

Two tiers, and alters touch neither.

- **Static**: the permission on each `#[Operation]`, answerable with no
  subject. A `%key` in it is filled from the identity the context knows
  (`administer %entity_type fields`).
- **Run time**: `access:` on `#[Resource]` or `#[Structure]`, an
  `OperationAccessInterface` that may hold services, asked once the
  permission allows. `FieldInstanceAccess` refuses a locked storage;
  `NodeTypeAccess` asks the node type entity.

An answer is open, locked or omitted (`AccessAnswer`); core's allowed
is open and its forbidden omitted. At the top, open lets the caller in
and anything else keeps them out. At a child, however it was invoked,
the answer is about the child's keys: changeable, shown and fixed, or
not there at all, the way field access treats a field. Its access class
always answers; the permission of its operation is asked only where the
build resolved one, since a parent that hands a context whole took
responsibility for entering it. A variant chosen by a locked
deciding key may refuse the whole resource through its access class,
never allow.

## Outputs

A resource or structure that answers with something implements
`DefinesOutputsInterface`: `defineOutputs()` takes the same blueprint as inputs,
so `defineInputs()` reads as "what this asks for" and most classes never
mention outputs. Outputs are never refined: a refinement narrows what
may be sent, and nobody sends an output. A resource's output values are
what its storage loads back after the write. The example formatter answers
with its rendered `text` and `classes`. [Outputs](outputs.md) has the
rest.

## Plugins

A plugin keeps rendering, and names the structure its configuration is
with `#[UsesStructure]`. Two spellings, side by side in the example module.

**Naming a structure class.** The configuration moves to a structure in
`src/Structure/`, and the plugin names it:

```php
#[Block(id: 'surface_examples_plugins', admin_label: new TranslatableMarkup('Surface API example'))]
#[UsesStructure(ExampleBlockStructure::class)]
final class ExampleBlock extends TypedBlockBase {

  public function build(): array { /* ... */ }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

**The plugin is its own structure.** `#[UsesStructure]` with no
argument, and the plugin implements `StructureInterface`: its static
`defineInputs()` and refiners sit beside the code that uses the values, and the
host builds the data surface from the plugin class without constructing
it.

```php
#[FieldFormatter(id: 'surface_examples_plugins_string', label: new TranslatableMarkup('Surface API example formatter'), field_types: ['string'])]
#[UsesStructure]
final class ExampleFormatter extends TypedFormatterBase implements StructureInterface, DefinesOutputsInterface {

  public static function defineInputs(BlueprintInterface $inputs): void { /* prefix, casing, variant */ }

  public static function defineOutputs(BlueprintInterface $outputs): void { /* text, classes */ }

  #[RefinesInput('variant')]
  public static function variantsOfCasing(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface { /* ... */ }

  public function formatValue(FieldItemInterface $item, array $settings): array { /* ... */ }

}
```

Such a structure is found through the plugin definitions, not a
directory. Its id is `<host type>:<plugin id>`
(`field_formatter:surface_examples_plugins_string`) unless the class also
carries `#[Structure]`; the catalog lists it as used by that same
plugin; an alter names it by the plugin class,
`#[Alters(ExampleFormatter::class)]`. The separate class
reads better when the structure is large, has children or operations,
or is shared; the plugin's own reads better when the plugin is small
and its settings are only ever its own.

Either way: no `blockForm()`, `blockValidate()` or `blockSubmit()`. The
host supplies the context and the storage, since only it holds the
instance. The attribute is copied into the plugin definition, so tools
and the catalog can list such plugins without instantiating any.
Block, formatter, condition, action and field type hosts all read it,
and `SurfacePluginForm` serves any other configurable plugin.

## Deferred

Three concepts are recorded here so their position is fixed, and are
not built, or built only in part.

### Collections

`attachList($key, Child::class)`, a list whose every item is the child,
is built ([Resources and structures](resources.md#collections)); the
rest of this section is the position it was built to, and what is still
open. `attachListBy($key, by: 'plugin')` would be a list whose every
item chooses its own child, the deciding key being *inside* each item
(visibility conditions, each naming its plugin), children marking
themselves with `#[Variant]` as for `attachVariants()`.

The contract is an ordered list, as typed data lists and field items
already are: delta is position, reordering is sending the items in
another order, and nothing stores a delta. Storage that must address
one item (image effects keyed by uuid with a weight) gets that from an
identity key the child declares; the storage class supplies it on load,
generates it on commit for a new item, and turns order into weights in
`prepare()`. The caller never sees a weight. A child's refiners run per
item in its own frame. Rules across items (no duplicates, at most five)
are constraints on the returned list definition, declared by the
parent. Add, remove and reorder are value changes, since the whole list
is submitted; they are not operations. Changing one item without
resending the list is a later question, probably an operation on the
child.

### Input shapes

Another way to enter the same value, converted to one canonical value
(the widget alter layer on the `variants` branch), is a separate
concept. It waits until children have settled, so that one idea
arrives at a time.

### Options

`OptionsList` naming an options source class in `src/Options/` (a
class that may hold services and answers a list for some arguments),
and options alters in `src/OptionsAlter/` changing one list everywhere
it is used. Until then a live list is a constraint with an options
resolver, as above. Two points are recorded for when it is built: an
options alter may remove an entry, under the stale rule the module
already has (a stored value no longer offered renders as no longer
available, is kept on an untouched save, and is refused as a new
value); and a site policy that hides an owner's key is the same later
concept. The naming of sources and resolvers is undecided.

## Questions the pattern settled

1. **Should an alter declare what it alters?** Yes: `#[Alters]` on
   the class, with optional `operations`. An alter that names its class
   by interface rather than by class is the one thing lost, and no
   example needed it.
2. **A child depends on its parent's class.** `#[Variant]` names
   `FieldInstanceResource::class`, so the address module references the
   field resource's module. That direction is right: it is the
   dependency the address module already has on fields, and a class
   name is what makes the parent's key one click away.
3. **Create-only keys that are not identity.** No new verb.
   `withConstraint()` with a one-value `Choice` that says it fixes the
   key (`surface_fixed`) is a lock in every way that matters: the
   key holds the value, the form renders it fixed, the pipeline refuses
   anything else, both documents state it `const`.
4. **May an options alter remove entries?** Yes, under the stale rule
   (see [Options](#options)), once options alters exist.
5. **Parent and child.** Keep the wall, with one door: identity in the
   child's context.
6. **The build step.** It is `Resources::build()`, written against the
   module's existing builder; [How it fits](how-it-fits.md) walks it.

## What it replaced

| The pattern | Before the rework |
| --- | --- |
| A class in `src/Resource/` or `src/Structure/` | a static `declareSurface()` on the host, or a provider service |
| `#[RefinesInput('key')]` methods, dependencies in the signature | `refineDataDefinition($name, $definition, $values)` with a `match`, dependencies declared separately |
| `attach()`, `attachVariants()` | `mount()`, `mountVariants()` |
| `#[Variant]` on the child | a resolver service, or a table the parent holds |
| `#[Resource(identity:)]` and `#[Operation]` methods | the builder's `lock()`, called by each provider per operation |
| A class in `src/Alter/` with `#[Alters]` | a build event subscriber holding the full builder |
| `#[Resource(storage:, access:)]` | the provider triple: `getSurface()`, `surfaceAccess()`, `getSurfaceStorage()` |
| `defineOutputs()`, never refined | `setOutputDefinition()` plus output refiners |
| `#[UsesStructure]` on the plugin, naming a structure or being one | `declareSurface()` on the plugin class itself |

The rework kept the module's builder and changed what an author writes
to reach it ([Questions the pattern settled](#questions-the-pattern-settled),
item 6). [Built on typed data](on-typed-data.md) says why a builder
sits on top of core's typed data at all.
