# Transformations

Three questions have their answers spread over several pages: how a
value asked for one way is stored another; what runs because something
was saved; and where a value nobody types comes from. This page answers
each in one place, says where the mechanism lives, and quotes the
shipped code that does it. Every quote of shipped code is pinned by
`QuotedCodeDocsTest`, so it fails when the code changes and the page
does not; the one snippet of code that does not exist yet says so. The concepts are documented elsewhere and linked from each
section; nothing here is a concept of its own.

## Representation: asked one way, stored another, sent a third

A value crosses up to three layers between a caller and storage, and
each layer changes one thing about it and nothing else:

| Layer | What it transforms | Who declares it | Where it is documented |
| --- | --- | --- | --- |
| Coercion, at the door | A raw value into the declared type, before validation: `'7'` becomes `7` for an integer, `'yes'` becomes `TRUE` for a boolean. Only when the intended value is unambiguous; anything else is left for a constraint to refuse. | Nobody: one table, the Tool API's, mirrored in `Pipeline\Coercion` and run by `accept()` for every door. | [The coercion table](semantics.md#the-coercion-table) (why: [decision](decisions.md#one-coercion-table-the-tool-apis)) |
| Storage layout | The accepted document into the shape storage keeps, and back: a deadline asked as an amount and a unit, stored as seconds. | The storage class, in its own `load()` and `prepare()`; a field type, by handing `FieldSettingsStorage` a `StorageLayoutInterface`; an alter, for its own mount, with `AltersStorageLayoutInterface::storageLayout()`. | [StorageLayoutInterface](storage.md#storagelayoutinterface) (why: [decision](decisions.md#blueprints-and-layouts)) |
| Wire representation | How a value travels at one door: an operation's entity parameter as its id at the tool door, an update's body as a merge patch over HTTP. | The door: the tool bridge's `OperationInputs`, the React submodule's API controller. | [The Tool API bridge](../modules/data_surface_tool/README.md), [The write](served-contract.md#the-write) |

The resource's keys, as declared, are the one document all three layers
translate to and from. Coercion produces it from what a caller sent, a
layout writes it down, and a door's wire representation is how a caller
names it. No layer changes a value into a different value: coercion
changes a spelling, a layout changes a shape, and a door changes how an
entity is named.

### Coercion, at the door

`accept()` runs the table on every value before anything validates it.
The integer rule:

<!-- excerpt: src/Pipeline/Coercion.php -->

```php
if ($type === 'integer') {
  if (is_bool($value)) {
    return $value ? 1 : 0;
  }
  if (is_float($value) || (is_string($value) && is_numeric($value))) {
    return self::integral($value) ?? $value;
  }
  return $value;
}
```

`integral()` answers an integer only when the number has no fraction
and fits one, so `'1.9'` is left as sent and the key's constraint
refuses it, naming the value. The tool door runs the Tool API's own
subscriber first and then the same `accept()`; every rule is
idempotent, so the second pass changes nothing.

### In storage: the layout

A layout is two pure functions, `toStorage()` and `fromStorage()`, with
no services and no site state, because the same values must translate
the same way for a form, a tool, a config action and a test. The
extras demo asks for a content type's review deadline as an amount and
a unit, and stores seconds:

<!-- excerpt: modules/data_surface_demo_extras/src/ReviewDeadlineLayout.php -->

```php
public function toStorage(array $values): array {
  if (!array_key_exists(NodeTypeReviewSettings::DEADLINE, $values)) {
    return $values;
  }
  $deadline = $values[NodeTypeReviewSettings::DEADLINE];
  $amount = is_array($deadline) ? ($deadline[NodeTypeReviewSettings::AMOUNT] ?? NULL) : NULL;
  $unit = is_array($deadline) ? ($deadline[NodeTypeReviewSettings::UNIT] ?? NULL) : NULL;
  $values[NodeTypeReviewSettings::DEADLINE] = is_int($amount)
    ? NodeTypeReviewSettings::seconds($amount, is_string($unit) ? $unit : NodeTypeReviewSettings::DEFAULT_UNIT)
    : NULL;
  return $values;
```

The alter that adds the deadline does not own the content type's
storage, so it hands the layout to the data surface for its own mount,
`third_party_settings.data_surface_demo_extras`:

<!-- excerpt: modules/data_surface_demo_extras/src/Alter/NodeTypeAlter.php -->

```php
public function storageLayout(): StorageLayoutInterface {
  return new ReviewDeadlineLayout();
}
```

`StorageAdapter` applies it to that mount and nothing else:
`toStorage()` before the storage prepares, so the storage's own checks
judge what will be stored, and `fromStorage()` after it loads. The
content type's storage class never learns the alter exists. A resource
that owns its storage translates in its own storage class instead, as
the address field's settings storage does through `AddressStorageLayout`:

<!-- excerpt: modules/data_surface_address/src/Storage/AddressFieldSettingsStorage.php -->

```php
public function load(OperationContext $context): array {
  $field = $this->field($context);
  return $field === NULL ? [] : (new AddressStorageLayout())->fromStorage($field->getSettings());
}
```

Its `prepare()` calls `toStorage()` the same way. Every door answers
values in the resource's layout, a dry run's included; what a layout
produces is served to no caller, and only PHP code holding the storage
reads it on the result
(why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects)).

Each class's `use` line: [Namespaces](concepts.md#namespaces).

### A reference key: the id, and where another shape belongs

A key that refers to an entity holds the entity's id at every door
(why: [decision](decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up)).
Example 16 declares two of its keys this way:

<!-- excerpt: modules/data_surface_examples_references/src/Resource/SponsorshipResource.php -->

```php
$inputs->add('page', 'entity:node:article', t('Announcement'))
  ->setDescription(t('The article that announces the sponsor.'));
// Of any type: an entity type, then an id of it.
$inputs->add('thing', 'entity', t('Featured beside'))
  ->setDescription(t('Any content the sponsor is featured beside.'))
  ->addConstraint('DataSurfaceReferenceableType', ['interface' => ContentEntityInterface::class]);
```

Its config object stores a content entity by its uuid, which names the
same node on a site the config is deployed to: `page` as the node's
uuid, and the bare `thing` as its two parts, `{entity_type, id}`, the
`id` the uuid of the content entity it holds. The engine translates,
with the stock `ReferenceStorageLayout`, and every door still sends and
answers the id
([A reference is stored by uuid](storage.md#a-reference-is-stored-by-uuid)).
The config schema, generated from the resource, says so:

<!-- excerpt: modules/data_surface_examples_references/config/schema/data_surface_examples_references.schema.yml -->

```yaml
page:
  type: uuid
  label: 'Announcement'
  nullable: true
thing:
  type: mapping
  label: 'Featured beside'
  mapping:
    entity_type:
      type: string
      label: 'Entity type'
      nullable: true
    id:
      type: string
      label: 'ID'
      nullable: true
```

No layout of the example's is involved: the uuid is the engine's.
When storage keeps a reference in another shape, the mapping is a
layout and nowhere else. A field item keeps an entity reference as
`[['target_id' => 7]]`; a resource that asks for the id as `page` and
stores it in such an item translates in its storage class, and an alter
that mounts such a key hands the translation for its mount with
`storageLayout()`. The layout would read, as a sketch (no shipped
resource has one):

```php
final class AnnouncementLayout implements StorageLayoutInterface {

  public function toStorage(array $values): array {
    if (array_key_exists('page', $values)) {
      $values['page'] = $values['page'] === NULL ? [] : [['target_id' => $values['page']]];
    }
    return $values;
  }

  public function fromStorage(array $settings): array {
    if (array_key_exists('page', $settings)) {
      $settings['page'] = $settings['page'][0]['target_id'] ?? NULL;
    }
    return $settings;
  }

}
```

The key's constraint, its candidates and its lookup still judge the id
as asked, before the layout runs. A content entity's own reference
field is asked for as its item, `{target_id}`, by the field's structure
(`data_surface_entity`), so today a reference key and a reference field
are two shapes for one idea; ROADMAP item 20 lists that as open.

Each class's `use` line: [Namespaces](concepts.md#namespaces).

### On the wire: the tool door and HTTP

The Tool API lets each connector say how an entity crosses its
boundary. A connector that cannot carry objects, such as an LLM
function call, creates the tool with an invoker that has
`InvokerCapability::EntitiesAsHandles`; for an input typed as a content
entity, the Tool API then resolves a handle string to the entity on the
way in (`EntityHandleTransformer::resolveInput()`) and stores an entity
output as a handle on the way out. An invoker without that capability
cannot send an entity input at all, and is told to use a tool that
takes an entity type and an id.

The tool bridge declares no entity input. An operation's entity
parameter is a string, the entity's id, for every connector:

<!-- excerpt: modules/data_surface_tool/src/OperationInputs.php -->

```php
if ($parameter->takesObject()) {
  $entity_type_id = $this->operationArguments->entityTypeOf($parameter);
  if ($entity_type_id === NULL) {
    throw new \LogicException(sprintf('The "%s" operation of the %s resource takes %s, which no tool can send: it is no single entity type.', $operation->id, $resource->id, $parameter->describe()));
  }
  return new InputDefinition(
    data_type: 'string',
    label: $label,
    description: $this->t('The id of the @entity_type the operation is about.', ['@entity_type' => $entity_type_id]),
    required: !$parameter->optional,
  );
}
```

The Tool API's handle transform applies only to entity-typed inputs, so
it leaves this one as sent. The id is loaded at the boundary of the
operation, the same way a route's raw parameter is:

<!-- excerpt: src/ResourceBuild/OperationArguments.php -->

```php
$entity_type_id = $this->entityTypeOf($parameter);
if ($entity_type_id === NULL || !(is_string($value) || is_int($value))) {
  return $value;
}
$entity = $this->entityTypeManager->getStorage($entity_type_id)->load($value);
```

A reference key inside `values` is the id as well, so one representation
serves every connector, and the read tool answers the same id the write
tool takes. A handle would be the Tool API's to resolve and store; the
id is what storage holds.

Over HTTP, an update's body is the values document itself, with RFC
7396's rule, which is the module's own (a key left out keeps what is
stored, `null` clears it), so `PATCH` takes a merge patch as well as
plain JSON:

<!-- excerpt: modules/data_surface_react/src/Controller/ResourceApiController.php -->

```php
$this->assertMediaType($request, $method === 'PATCH' ? [self::MERGE_PATCH, 'application/json'] : ['application/json']);
```

Nothing is translated: `accept()` lays the patch over what storage
loads, as it does for a form.

Each class's `use` line: [Namespaces](concepts.md#namespaces).

## Reactions: what runs because something was saved

### The commit is the only stage with side effects

The pipeline runs access, accept, validate, prepare and commit, and only
commit writes ([The pipeline](pipeline.md#commit)). Its `commit()` is the
resource's storage composed along the tree: a child whose class names
storage of its own commits its part, an attached resource before the
parent and a variant's storage after it, and a child without storage is
written by its parent under its key:

<!-- excerpt: src/ResourceBuild/StorageAdapter.php -->

```php
foreach ($before as [$route, $child_prepared]) {
  $route->withParentIdentity($known)->commit($child_prepared);
}
$this->written('commit', $own);
foreach ($after as [$route, $child_prepared]) {
  $route->withParentIdentity($known)->commit($child_prepared);
}
```

That is the only code that runs because a write happened. Each storage
writes through core, so core's own events fire from it: a config
object's save, a config entity's or a content entity's presave, insert
and update. A write that changes nothing saves nothing, so it fires
nothing:

<!-- excerpt: src/Storage/ConfigObjectStorage.php -->

```php
// A write that changes nothing saves nothing: no save event, and
// nothing for a config export to differ by.
// Decision: see docs/decisions.md#absent-keeps-null-clears.
if (!$config->isNew() && $config->getRawData() === $before) {
  return;
}
$config->save();
```

A dry run stops after prepare, so it fires none of them
([Dry runs](pipeline.md#dry-runs)).

Each class's `use` line: [Namespaces](concepts.md#namespaces).

### Anything more is an ECA model

A reaction beyond the write, such as sending a mail when the
registration settings change or configuring the registration when a page
is saved, belongs to ECA. The module ships no ECA model and no ECA
plugin; ECA meets a resource at two points that already exist:

- **As a reaction to the save.** ECA's "Save config" event
  (`config:save`) and its content entity events fire on the saves the
  commit makes. A model reads what was written from the saved object,
  which holds the stored values in storage's layout.
- **As a caller.** The ECA Tool module (`eca_tool`) derives an ECA
  action per tool, so a model runs a derived tool,
  `data_surface:<resource>:<operation>`, and a model that changes a
  resource goes through the pipeline like any other door:
  access, coercion, validation, storage's checks, and the one result
  shape with its violations by path
  (why: [decision](decisions.md#one-result-at-every-door)).

Either way the model is site configuration, built and exported on the
site that wants the reaction, and the resource does not know it exists.

### What is absent on purpose

The pattern has no callback on a key, no submit handler, and nothing
that runs code at validation time other than a constraint. A runtime
refiner runs during a request, but it narrows what a key allows; it
changes no value and writes nothing.

- **A handler runs at one door.** A form's validate and submit handlers
  run only when that form is submitted. The address field type's
  settings were half translated in a validate handler, which is why only
  its form could write them correctly ([StorageLayoutInterface](storage.md#storagelayoutinterface)).
  A callback on a key would be the same: either every door runs it,
  the form, the tool, HTTP, a config action and a PHP caller, or the
  doors disagree about what a write does.
- **A read of the request runs at one door.** A refiner, an access
  class, a provider or a storage that reads the request, the route, the
  session or the current user answers one thing on the form and another
  over HTTP or through a tool, which has no request of its own. Nothing
  an operation runs reads them: the account is handed in, and with
  assertions on the guard refuses the rest
  ([No door-specific context](resources.md#no-door-specific-context)).
- **A callback makes the contract wrong.** Both schema documents
  describe what a key accepts and what is stored. Code that changes a
  value after validation stores something the contract never described,
  and code that changes it before validation makes the contract describe
  something the pipeline does not judge. The roadmap rejects both
  ([Rejected, on the record](../ROADMAP.md#rejected-on-the-record)), and
  the rework removed the build event and the output refiners that
  allowed them ([What it replaced](pattern.md#what-it-replaced)).
- **Validation must be safe to run ahead.** A dry run, the served
  contract's narrowing (`POST .../schema`) and the tool's refusal loop
  run accept and validate without writing. Code with side effects at
  validation time would run on each of them.

What remains for a reaction is the write itself, which the storage
does, and what follows the write, which ECA does.

## Computed values: what nobody types

### Outputs: emitted, never accepted

An output is what an operation answers and no caller sends, declared
with `defineOutputs()` on `DefinesOutputsInterface` ([Outputs](outputs.md)).
Example 15's listing answers a page of the venues and how many matched:

<!-- excerpt: modules/data_surface_examples/src/Structure/VenueListingStructure.php -->

```php
$outputs->addList('items', $item, t('Venues'))
  ->setDescription(t('The page of venues, lighter first, each one the account may read.'))
  ->setRequired(TRUE);
$outputs->add('total', 'integer', t('Total'))
  ->setDescription(t('How many venues the filter matched that the account may read, on every page.'))
  ->setRequired(TRUE);
```

A listing is a read only operation, which is never committed, so its
outputs are what its storage's `prepare()` answered
(why: [decision](decisions.md#who-produces-outputs)). `VenueStorage`
computes them there:

<!-- excerpt: modules/data_surface_examples/src/Storage/VenueStorage.php -->

```php
return [
  'items' => array_slice($matched, (int) ($values['offset'] ?? 0), (int) ($values['limit'] ?? 20)),
  'total' => count($matched),
];
```

An output is never refined, has no default and is never stored. For any
other operation, a resource's outputs are what its storage's `load()`
hands back once the values are committed.

Each class's `use` line: [Namespaces](concepts.md#namespaces).

### The storage's prepare: what the store keeps for itself

`prepare()` may add a value the store needs and no caller supplies.
`ConfigObjectStorage` adds the language code core requires on a config
object with translatable values:

<!-- excerpt: src/Storage/ConfigObjectStorage.php -->

```php
if ($this->typed($name, $whole)?->hasTranslatableElements()) {
  $prepared['langcode'] = $stored['langcode'] ?? $this->languageManager->getDefaultLanguage()->getId();
  $whole['langcode'] = $prepared['langcode'];
}
```

The value is the store's, not the resource's: a resource never declares
`langcode`, `load()` leaves it out, and no door answers it.
`ConfigEntityStorage` does the same with a config entity's dependencies,
calculated on the rehearsed copy. This is the right home for a value
that belongs to how a thing is stored. It is the wrong home for a value
a caller should see: one that `prepare()` derives is in no contract and
in no read.

Each class's `use` line: [Namespaces](concepts.md#namespaces).

### A computed key: a value of the resource that follows others

A key whose value is computed from other keys, and that callers read, is
declared with `compute()` ([Declaring a resource](declaring-a-resource.md#computed-keys)).
Example 15's venue computes its slug from its name:

<!-- excerpt: modules/data_surface_examples/src/Resource/VenueResource.php -->

```php
$inputs->compute('slug', 'string', t('Slug'), from: ['label'], using: Slugify::class)
  ->setDescription(t('The name in a link: lowercase letters, numbers and dashes, set when the venue is added.'))
  ->addConstraint('Regex', [
    'pattern' => '/^[a-z0-9]+(-[a-z0-9]+)*$/D',
    'message' => t('The slug may hold only lowercase letters, numbers and single dashes between them.'),
  ])
  ->addConstraint('Length', ['max' => 128]);
```

The class is a pure function of the keys `from:` names:

<!-- excerpt: modules/data_surface_examples/src/Slugify.php -->

```php
public static function compute(array $from, ?DataDefinitionInterface $key = NULL): mixed {
  $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) ($from['label'] ?? ''))), '-');
  return $slug === '' ? NULL : $slug;
}
```

It differs from the two above in what it is: an output is not stored
and no read answers it, and what `prepare()` adds is in no contract. A
computed key is stored, loaded and answered by the read like any key,
and both documents state it. Three choices settle it
(why: [decision](decisions.md#a-computed-key-is-a-pure-function-of-its-sources)):

- **Pure functions only.** The class that computes it runs under the guard a static
  refiner runs under, and the PHPStan rule flags `\Drupal::` in it, so
  the narrowing (`POST .../schema`) can run it ahead of a write and
  answer the value it would compute for the values in progress. It is
  handed the values `from:` names and the key's own definition, `$key`,
  which is declaration, not site state. A changed time reads the clock:
  it stays a storage's to add in `prepare()`.
- **When it runs.** Once the other keys are accepted, from their
  accepted values: while storage holds nothing for it, which is when the
  thing is created, and again on every write that changes a key in
  `from` only when it says `when: Compute::ON_CHANGE`. A write that
  changes no source keeps what is stored. The value is then judged by
  its own constraints and rules, as any key's is, and a refusal of it
  names the keys it is computed from, the ones a caller can change.
- **A value sent for it.** Equal to the one it holds, it is noted, code
  `computed`, and not taken, as a locked key's is, so a client that
  writes back the document it read is not refused; any other is
  refused, code `computed`: "Slug is computed from Name and takes no
  value of its own; leave it out."

Both documents state it `readOnly` with `x-surface.computed: {"from":
["label"], "when": "create"}` and no `const`, since what it holds follows
its sources; it is never in `required`. The write tool's `values` leaves
it out, and the read tool answers it, so the two shapes differ by
exactly the computed keys.

Each class's `use` line: [Namespaces](concepts.md#namespaces).

### A machine name: computed unless sent

The venue's machine name is a computed key a caller may also type. It
is computed from the name when a call leaves it out, as Drupal's own
forms suggest a machine name from a title, and kept as sent when a call
gives one:

<!-- excerpt: modules/data_surface_examples/src/Resource/VenueResource.php -->

```php
$inputs->compute('id', 'machine_name', t('Machine name'),
  from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT)
  ->setDescription(t('A unique name for the venue: lowercase letters, numbers and underscores, computed from its name unless one is given.'))
  ->addConstraint('Length', ['max' => 32]);
```

`machine_name` is core's config schema type, so the key is a string with
its pattern and a Length of 166, narrowed here to 32. The stock
`MachineName` transliterates, lowercases, replaces what a machine name
may not hold with underscores and cuts to the key's Length, as core's
machine name element does in the browser. `Compute::UNLESS_SENT` is the
third `when:`: a value sent is judged by the key's own constraints and
is never refused for being sent, so both documents state the key with
`x-surface.computed: {"from": ["label"], "when": "unless_sent"}` and no
`readOnly`, and the write tool offers it. Each create holds the identity
to one no venue has, with the stock `DataSurfaceUnique` the build adds:
"A venue named riverside already exists."
(why: [decision](decisions.md#a-machine-name-is-a-computed-identity)).

Each class's `use` line: [Namespaces](concepts.md#namespaces).
