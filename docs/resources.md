# Resources and structures as classes

This page is the full reference for what a resource or structure class
can declare beyond its keys: operations, other modules' changes, the
structures it holds, access, and the tools made from it. Use it when a
build page links a section here, or when you need an option no build
page shows. [Operations](introduction/09-operations.md),
[Resources made of structures](introduction/11-resources-made-of-structures.md),
[Other modules](introduction/13-other-modules.md) and
[Access](introduction/15-access.md) introduce its ideas.

A resource or structure is a class of its own: one static method that
declares its keys, and one named static method per key whose allowed
values depend on another key. A resource is a thing with identity and
storage that operations read and write; a structure is a group of
keys that something else holds. Other modules change either with
classes of their own, and nothing is registered by hand. The build step,
`surface.resources`, turns the class and a context into a sealed
`SurfaceInterface`, which the pipeline, forms, widgets and storage
read.

This page is the reference for each part. [The pattern](pattern.md) is
the short version with the reasoning, [How it fits](how-it-fits.md)
walks one build, and [Decisions](decisions.md) records each point the
pattern left open.

## Which way to compose

A resource or structure is made of others in six ways. Each answers a
different question, keeps the keys in a different place and is stated
differently in the schema; the examples module shows every one on a
page of its own.

| The question you are asking | What it gives | The example that shows it | On this page |
| --- | --- | --- | --- |
| Is this group of keys a thing of its own? | `attach($key, Child::class)`: a fixed child, its keys stored as a map under the key, behind the wall; the schema refers to the child's def at the key. | The contact, `/surface-examples/3`; the press contact, `/surface-examples/8` | [Children](#children) |
| Are these keys shared members that are part of this thing? | `mixIn(Structure::class)`: a mixin, its members the owner's own keys, stored flat beside them; the schema is `allOf` of the structure's def and the owner's properties. | The review, `/surface-examples/8` | [Mixins](#mixins) |
| Does a structure need a value its container holds? | `reads($port, $type)` in the structure, wired by `attach(..., reads: [$port => $key])`: a port, the value kept at the container's key and stored by nothing in the structure; the def lists its ports, and what depends on one is stated on the container's `allOf`. | The person's `listed`, `/surface-examples/8` and `/surface-examples/8/desk` | [Ports](#ports) |
| Does one key's value choose which further keys apply? | `attachVariants($key, by: $sibling)` and a `#[Variant]` per value, or `from:` a provider that lists them from the site, each with its schema: variants, the chosen variant's keys stored under the key; the schema is an `if`/`then` on the sibling for each variant. | The ticket, `/surface-examples/3`; a component's props, `/surface-examples/12` | [Variants](#variants), [from the site](#variants-from-the-site) |
| Does a group of keys repeat? | `attachList($key, Child::class, min:, max:)`: a collection, a list under the key whose every item is the child's keys; the schema is an array whose `items` refer to the child's def, its Count as `minItems` and `maxItems`. | The volunteers, `/surface-examples/8/desk` | [Collections](#collections) |
| Is there one key per thing the site has? | `addEach()` or `attachEach()` with a provider: keys from the site, a map under the key with one entry per thing the provider lists at every build; the schema states every entry, the map a def of the owner's. | The ticket types, `/surface-examples/9` | [Keys that come from the site](#keys-that-come-from-the-site) |

The rule of thumb: nest for a thing of its own, mix in for shared
members that are part of this thing, and read a port for a value the
container holds. Use variants when a key chooses the keys, and a
collection when groups repeat. Use keys from the site when the site,
not the code, decides how many there are. Whichever way, the key takes
the label the parent gives it, and the child's def in the schema the
label the child gives itself, `#[Structure(label:)]`
([What it is called](declaring-a-resource.md#what-it-is-called)).

## Where things live

A module has four predictable directories, scanned in every enabled
module the way core scans `src/Hook`:

| | Owns | Reaches into |
| --- | --- | --- |
| **Resources and structures** | `src/Resource/`, `src/Structure/` | `src/Alter/` |
| **Shapes** | `src/Shape/` | `src/Alter/` |

- A class in `src/Resource/` or `src/Structure/` carrying `#[Resource]`
  is a resource, and one carrying `#[Structure]` is a structure. The
  attribute decides the kind, not the directory: either directory
  works, and the two are read as one list. Neither kind is ever
  instantiated: its declaration and refiners are static, called on the class,
  so it has no constructor and holds no service.
- A plugin carrying `#[UsesStructure]` with no argument is its own
  structure, found through its plugin definition rather than a
  directory ([Plugins](#plugins)).
- A class in `src/Shape/` carrying `#[Shape]` is a shape: one scalar
  value, declared once and used at many keys ([Named shapes](declaring-a-resource.md#named-shapes)).
- A class in `src/Alter/` carrying `#[Alters]` is an alter. It is
  registered as an autowired service, so it may hold services.
- `#[Operation]` on a resource's or structure's class, or on a static
  method of it, is an operation; on a class in `src/Alter/`, or a static
  method there, with `of:`, another module's operation of it.
- A class carrying `#[DerivesResources]`, by convention in
  `src/Resource/`, is a deriver: it makes resources and structures from
  site state at discovery, one per bundle or per field, each with no
  class of its own ([Derived resources](#derived-resources)). It is
  registered as an autowired service.

Two more kinds of class are named rather than discovered: a resource's
storage class (`#[Resource(storage:)]`) and the run-time access class of
either kind (`access:` on `#[Resource]` or `#[Structure]`). Both are
registered as autowired services when the class naming them is found.
Their directory is a suggestion: `src/Storage/`, `src/Access/`.

The API is in `Drupal\surface\Resource`; the attributes in
`Drupal\surface\Resource\Attribute`.

## The pattern

| | Blueprint | Values |
| --- | --- | --- |
| **Owner** | `static defineInputs($inputs)`, optionally `static defineOutputs($outputs)` | static `#[RefinesInput('key')]` methods |
| **Another module** | `alterInputs($inputs)` | `#[RefinesInput('key')]` methods |

The example block's configuration, `ExampleBlockStructure` in the example module,
is the first one written this way. It is a structure: the block holds
it, and the block's host stores it.

```php
#[Structure('block.surface_examples_plugins')]
final class ExampleBlockStructure implements StructureInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
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

  #[RefinesInput('field')]
  public static function fieldOfBundle(DataDefinitionInterface $field, string $entity_type, string $bundle): DataDefinitionInterface {
    return $field->addConstraint('SurfaceExamplesBundleField', [
      'entityTypeId' => $entity_type,
      'bundle' => $bundle,
    ]);
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **`defineInputs()` and `alterInputs()` get a blueprint to fill, and nothing else**: no
  values, no context, no services. The owner's are static, so the
  language holds them to that; the build step calls them on the class
  and never makes an instance. The owner gets `BlueprintInterface`; an
  alter gets `BlueprintAdditionsInterface`, which can add but not change or
  remove what the owner declared. Adding a key twice is refused. The
  one change anyone may make to a key already declared is `describe()`:
  its label, its description and its arrangement (where it is drawn,
  and its placeholder and help), which change nothing about what is
  accepted.
- **`add()` takes name, type and label** and returns the core
  definition, so the rest is plain core API. `addList()` is the same
  for a list of values, and `addDefinition()` the long form, for a map.
  A label is `t('...')`, with a literal
  string: a static method has no instance for the translation service
  to be injected into, and `t()` returns the same lazy
  `TranslatableMarkup`.
- **A `#[RefinesInput('key')]` method takes the key's definition first,
  then one parameter per sibling it watches**, matched by name: a
  parameter that names no declared key is refused at build, so a
  renamed one is never silently left unwatched. It runs once every watched
  sibling has a value, and again when one changes. A method that
  watches nothing runs once, at build. The owner's refiner is static,
  and one that is not is refused at build; so is an alter's, unless it
  declares `runtime: TRUE`, the one kind an instance method may be,
  because an alter is a service and may hold what a runtime rule reads
  ([refinement](refinement.md#enforcing-the-kind)).
- **A refiner never calls a service.** A list that depends on a value
  is a constraint whose options resolver fetches it, handed the value as
  an option. The refiner points; the resolver fetches. Core's
  `EntityBundleExists` is one; the example brings `SurfaceExamplesBundleField`
  for a bundle's fields.
- **The framework checks every refinement is narrower**, so a refiner
  cannot widen, retype, or turn off required. The two type changes it
  accepts are away from `any` and to a derivative of the type
  (`entity` to `entity:node` to `entity:node:article`); see [the narrowing table](refinement.md#the-narrowing-table).

## Reading a class

- Everything on it is static: `defineInputs()`, refiners, operations. No
  constructor, no properties, no `$this`.
- `defineInputs()` is a flat list. No `if`, no loop.
- Name, type and label sit on one line.
- It is ordered like the thing it describes: identity first, the
  thing's own keys next.
- `defineInputs()` never mentions a sibling. Anything that reads another
  key's value is a `#[RefinesInput]` method, one rule per method, its
  docblock stating the rule and its signature stating what it reads.
- Everything is named by class: the resource or structure an alter or
  an operation belongs to, a storage class, an access class. Never an
  instance or a string.

## Operations: create, update, and the rest

A resource never mentions create or update. The difference is how much is
already known, and that is a **context**, an `OperationContext`:

- `#[Resource(identity: [...])]` names the identity keys. An identity
  key the context knows is locked to that value; one it does not know
  stays open. That is the whole difference between create and update. A
  resource with no identity keys is a singleton.
- The ways a resource is asked for are `#[Operation]` attributes. Each
  states the operation's static facts, read at discovery without
  running anything: its id, its `label`, a `description` of what a call
  does to what (the tool's first sentence and both documents' top
  `description`), its `permission`, whether it `creates`, and how a
  call behaves (below).
- **On the class**, the default: an operation that needs nothing more
  than those facts has no method. `#[Operation('configure', label: ...,
  permission: ...)]` beside `#[Resource]`, repeatable, means the context
  `new OperationContext()` with the attribute's id and `creates`
  stamped on it. Every shipped singleton is written this way
  (why: [decision](decisions.md#an-operation-on-the-class)).
- **On a static method**, the exception: a method only when the context
  needs more, parameters (what the operation needs is its own
  signature: a route or a tool supplies them by name), identity it
  knows, a constraint, defaults or a child. The method returns an
  `OperationContext` and may leave the id and `creates` out; the
  attribute's are stamped on it, and a context that names another id,
  or says otherwise about creating, is refused: at discovery when the
  method needs nothing, else when it is first asked
  (why: [decision](decisions.md#a-contexts-id-is-the-operation-id)). With no
  `description:`, the method's docblock summary is its description.
- The context is `new OperationContext(known: [])`: the identity keys
  it knows, each with its value. The plugin hosts build their own,
  `new OperationContext('configure')`, under a verb that no operation
  declares. `withConstraint()`, `withDefaults()` and `withChild()`
  return a copy that says more.
- An operation stands on its resource's or structure's own class; one
  on any other class is refused at discovery. Two declarations of one
  id are refused, naming both.
- A context may also narrow a key (`withConstraint()`, checked
  narrower); a Choice of one value with `'surface_fixed' => TRUE`
  fixes it: the key holds that value, made required where it was
  optional, drawn as it and stated `const`. It may give keys the values
  they start from in this build (`withDefaults()`), over the declared
  default and the site's; only a context that creates takes them, since
  on an update the stored value stands, so a context that does not
  create and carries defaults is refused when the resource is built,
  naming the operation. Both name a key by path, with
  [a site default's](declaring-a-resource.md#a-sites-own-defaults)
  reach: a path into a structure attached at a fixed key (`title.value`)
  is handed to that child's own context. Neither may name an identity
  key the context knows, which is the operation's to say: a context that
  fixes or fills one is refused when the resource is built. A route uses
  both to fix or fill keys on one page
  ([forms](forms.md#a-route-that-fixes-or-fills-keys)). It may hand a
  child a context of its own, or name the child's own operation to
  invoke (`withChild()`, see [Child operations](#child-operations)).
  What depends on where you are is the operation's; what depends on
  what was entered is a refiner's.
- An operation states how a call behaves, for a client deciding
  whether to ask before calling and whether a retry is safe:
  `#[Operation(readOnly: TRUE)]` for one that changes nothing,
  `destructive: TRUE` for one that may destroy what is there (delete
  it, or overwrite it beyond what was sent), both `FALSE` unless said.
  Whether the same call twice leaves what the first left is derived: an
  operation that does not create is idempotent, one that creates is not,
  a read only one is; say `idempotent:` only where you know better: an
  operation that appends is not, and a create whose caller names the
  thing (`node.type:create`, `field.instance:create`) is, a second identical
  call finding the name taken. A tool carries them to the Tool API,
  which MCP reads as a tool's annotations, and both schema documents
  state them at the top, under `x-surface.operation`
  (why: [decision](decisions.md#an-operation-states-how-it-behaves)). A read
  only operation writes nothing at any door: its pipeline accepts,
  validates and prepares, stops where a dry run does, and refuses to
  commit what it prepared. An update
  destroys nothing: it changes the keys it is sent, and a key left out
  keeps its value. `deletes: TRUE`, beside `destructive: TRUE`, makes an
  operation a delete: it takes no values, its storage removes the thing
  its context names (`DeletableStorageInterface`), its tool takes the
  parameters and a dry run, and HTTP serves it as `DELETE` (the
  placement's `delete`; why:
  [decision](decisions.md#a-placed-block-is-a-resource)).

The content type resource is the worked example:

```php
#[Resource('node.type',
  label: new TranslatableMarkup('Content type'),
  description: new TranslatableMarkup('A content type: its name, machine name, description and help text, the label of its title field, and the defaults its new content starts from.'),
  identity: ['type'],
  storage: NodeTypeStorage::class,
  access: NodeTypeAccess::class,
)]
#[Operation('create',
  label: new TranslatableMarkup('Add a content type'),
  description: new TranslatableMarkup('Creates a content type under a machine name the caller chooses, or one computed from its name; a machine name already in use is refused.'),
  permission: self::PERMISSION,
  creates: TRUE,
  idempotent: TRUE,
)]
final class NodeTypeResource implements ResourceInterface {

  #[Operation('update',
    label: new TranslatableMarkup('Edit a content type'),
    description: new TranslatableMarkup('Changes a content type, named by its machine name, which stays as it is.'),
    permission: self::PERMISSION,
  )]
  public static function update(NodeTypeInterface $type): OperationContext {
    return new OperationContext(known: ['type' => $type->id()]);
  }

  // defineInputs(): name, type, description, title_label, ...

}
```

The update is a method because its context needs more: it knows the
machine name. The create needs nothing more, so it is the attribute
alone on the class: the build holds the machine name it creates under
to one no content type has, with the stock `SurfaceUnique`
([A title and a machine name](recipes.md#a-title-and-a-machine-name)).
A settings object's one `configure` is the attribute alone too, as on
[the first resource](first-resource.md):

```php
#[Resource('first.newsletter',
  label: new TranslatableMarkup('Newsletter settings'),
  storage: NewsletterStorage::class,
)]
#[Operation('configure',
  label: new TranslatableMarkup('Configure the newsletter'),
  description: new TranslatableMarkup('Sets who the newsletter is sent from, how often it goes out and how many recipients one send reaches.'),
  permission: 'administer surface_examples_first',
)]
final class NewsletterResource implements ResourceInterface {
```

and the field instance resource has three, each knowing more than the
last: `create($entity_type, $bundle)`, `reuse($entity_type, $bundle,
$field_name)` (a create for the field and, through `withChild('storage',
FieldStorageResource::update($entity_type, $field_name))`, an update for
its storage) and `update($entity_type, $bundle, $field_name)`, each
taking the field by its parts, never by a composite id such as
`node.article.body`
([decision](decisions.md#a-parameter-is-identity-the-caller-knows)).
The storage's update operation narrows cardinality not to shrink once
the field has data, with `withConstraint()`, and knows the storage's
identity, the entity type, the field name and the field type, which
locks its settings to that type's.

Each class's `use` line: [Namespaces](concepts.md#namespaces).

```php
$resources = \Drupal::service('surface.resources');
// By parameter name or position; an entity parameter takes the entity
// or its id, loaded by the entity type whose class satisfies the type.
$context = $resources->operation(NodeTypeResource::class, 'update', ['type' => 'article']);
$surface = $resources->build(NodeTypeResource::class, $context);
$access = $resources->access(NodeTypeResource::class, $context);
$storage = $resources->storage(NodeTypeResource::class, $context, $surface);
```

A generic caller passes an empty context: every identity key stays
open, and it is still the same resource.

### An operation that takes keys of its own

Most operations take the resource's keys: a create sends them, an update
changes some. An operation about the things together takes other keys:
a listing's page and filter, a reorder's ids and weights. It names the
structure they are with `structure:` on its `#[Operation]`, and that
structure's inputs are what it takes and its outputs what it answers
(why: [decision](decisions.md#a-listing-is-a-read-only-operation-with-outputs-a-reorder-is-an-update-over-the-collection)):

```php
#[Operation('list',
  label: new TranslatableMarkup('List the venues'),
  description: new TranslatableMarkup('Answers a page of the venues, lighter first, each one the account may read, and how many the filter matched.'),
  permission: self::VIEW,
  readOnly: TRUE,
  structure: VenueListingStructure::class,
)]
#[Operation('reorder',
  label: new TranslatableMarkup('Reorder the venues'),
  description: new TranslatableMarkup('Gives each venue named its new weight, all in one write; a venue left out keeps its own, and an unknown or repeated venue is refused.'),
  permission: self::ADMINISTER,
  structure: VenueReorderStructure::class,
)]
```

- **What stays the resource's.** Its permission, its access class, its
  storage and how the operation behaves are the resource's: the build
  writes the structure's keys, refiners, rules and children where the
  resource's would be, and nothing else changes. The structure is a
  `#[Structure]` with no identity and no storage; one that is not is
  refused when the operation is first built.
- **No subject.** Such an operation names no thing, so it needs no
  parameter and is addressed at the collection; its storage reads the
  context's id to know which it is. Its keys are no keys the storage
  holds, so none is marked always held, and the build does not hold
  them to the config schema the storage writes to.
- **A read answers its outputs.** A read only operation is never
  committed, so its outputs are what its storage's `prepare()` answered
  under an output's name (why: [decision](decisions.md#who-produces-outputs)): `VenueStorage` reads the
  venues there and answers the page and the total. Its tool answers them
  beside the one result shape; on HTTP, `GET` on the collection runs it
  and answers them alone ([a listing](served-contract.md#a-listing)).
- **An update writes what it names.** The reorder is an update over
  the collection: its storage loads every venue's id and weight, and
  its `prepare()` refuses an id that names no venue (`not_found`) or is
  named twice (`taken`), by its place in the list, and rehearses the rest;
  `commit()` saves them. Its read tool answers the current order, and on
  HTTP it is a custom method, `PATCH /surface-api/event.venue:reorder`.
- **Refused** on an operation that creates or deletes, which is about
  the thing's own keys.

Each class's `use` line: [Namespaces](concepts.md#namespaces).

### Routes from operations

A route names a resource class (or a resource's id) and an operation,
and its parameters are the operation method's, by name; an upcast entity
parameter arrives as the entity. A route default whose name has no
leading underscore is a route parameter too, as core counts it, so
`to: 'friends'` pins the operation's `$to` on that page, and one that
nothing the route serves reads is refused. `SurfaceOperationForm`
builds the context with the operation, renders the data surface, loads
current values from the composed storage and submits through the
pipeline to it. The route's
requirement is the operation's access, which a route naming a resource
and an operation has without saying it:

```yaml
surface_examples_node_type.update:
  path: '/admin/structure/types/manage/{type}/surface-edit'
  defaults:
    _form: 'Drupal\surface\Form\SurfaceOperationForm'
    _surface_resource: 'Drupal\surface_examples_node_type\Resource\NodeTypeResource'
    _surface_operation: 'update'
    _surface_cosmetics: 'surface_examples_node_type.form_cosmetics'
  options:
    parameters:
      type:
        type: 'entity:node_type'
```

A locked identity key renders as a disabled element holding the value
the operation knows, and extraction keeps that value whatever is
posted. The cosmetic layer (`SurfaceFormCosmeticsInterface`), named
by the route's optional `_surface_cosmetics`, is told the
operation id as its `$operation` and the raw value of the first of the
operation's parameters that is a variable of the route's path. A route
may fix, fill or start the operation's keys on its page alone, and a
value it fixes or fills may be `'{name}'`, a reference to a bounded
variable of its path, read raw; a derived resource's id may hold such a
variable too, so one route serves every bundle
([A route that fixes or fills keys](forms.md#a-route-that-fixes-or-fills-keys)).
[Generated forms](forms.md#the-operation-form) has the rest.

## Access

Two tiers, a gate between them when the operation borrows access from
another thing ([A gate borrowed from another
thing](#a-gate-borrowed-from-another-thing)), and another module's alter
may answer after both ([An alter's answer](#an-alters-answer)):

- **The operation's permission**, on `#[Operation(permission:)]`: the
  static part, answerable with nothing loaded. A `%key` in it is filled
  from the identity the context knows
  (`administer %entity_type fields`); a placeholder the context does
  not know is refused. The permission is the module's own, declared in
  its `MODULE.permissions.yml` as any permission is (or one another
  module declares, such as `administer site configuration`); an
  operation names it and declares nothing. A route serving the operation
  asks for this answer with the requirement
  `_surface_operation_access`, which every route naming
  `_surface_resource` and `_surface_operation` has without
  saying it (`OperationRouteSubscriber`), and the tool asks the same.
- **The access class**, `access:` on `#[Resource]` or `#[Structure]`,
  an `OperationAccessInterface` that may hold services, asked only once
  the permission allows: what depends on the thing itself.
  `NodeTypeAccess` asks the node type entity whether one may be created,
  or this one updated, so this module never grants more than core's own
  form; `FieldInstanceAccess` refuses a locked storage. It answers for
  the account it is handed and reads nothing a door has
  ([No door-specific context](#no-door-specific-context)).

`ResourcesInterface::access()` is that answer, and every caller reads it:
the route requirement `_surface_operation_access`, the form's
floor, the content type listing's operation link, and the generated
tool. The answer is about the one operation asked for, so a caller that
hands it to the pipeline makes it decisive
(`SurfaceAccess::decisive()`): no opinion is a refusal, as it is on
a route.

A structure has no permission of its own. A permission says who may
make the call at a door, and only a resource opens doors; a structure
is only ever attached, so the call that reaches it is its container's
operation, gated by that operation's permission. What a structure may
do is answer access for its own keys, with `access:` on `#[Structure]`:
locked or left out for the account asking, in its own frame, reading a
permission if it wants, as example 10's fees do. The sentence to keep:
a permission decides whether you may ask; an access answer decides
what you may see and change once you have asked; a structure can only
ever do the second.

### Three answers

An access class may return core's `AccessResultInterface`, as every one
written before did, or a `Resource\AccessAnswer`, which has three
answers: **open** (the keys may be changed), **locked** (they are shown
and fixed) and **omitted** (they are not there). Core's answer is read
as one of them: allowed is open; forbidden is omitted at a child and
denied at the top; neutral leaves a child open and is a refusal at the
top, as above. Two answers about the same keys combine to the narrower,
and either carries its cacheability, which reaches the built data
surface, both contracts and the form.

| Answer | At the top (an operation asked on its own) | At a child, however invoked |
| --- | --- | --- |
| open | allowed, as today | as today |
| locked | allowed, built locked whole: every element disabled and no Save, `readOnly` at the top and on every key in both contracts, a submit refused by name at every door, the read tool answering (why: [decision](decisions.md#locked-at-the-top)) | the parent's key locked: fieldset drawn disabled, every key `readOnly` in both contracts and locked in the tool's `values`, posted values ignored ([Value semantics](semantics.md#locked-and-omitted-children)) |
| omitted | denied (route 403, tool access denied) | the key left out of the built data surface, both contracts, the form, the tool's `values` and the read tool's output; a posted value is an unknown key; a write keeps what is stored under it |

Access composes: the parent's operation access gates the whole data
surface as above, and each child's access then decides that child's
keys ([Child operations](#child-operations)). One child may refuse the
whole parent, never allow: a variant chosen by a locked deciding
key, which the parent cannot be written without (why: [decision](decisions.md#a-chosen-variant-may-refuse)).

### Static and runtime access

An answer is one of two kinds, read from what its cacheability says it
depends on and never declared
(why: [decision](decisions.md#access-is-static-or-runtime-read-from-its-cacheability)):

- **Static**: it varies by the account's permissions or roles and
  nothing else (cache contexts `user.permissions`, `user.roles`,
  `user.roles:<role>`; no cache tag; a permanent max-age). The account
  alone decides it, so it is stated ahead of time for the account that
  asks. The operation's permission is the first one: one permission as
  written is static (`OperationDefinition::staticPermission()`), which
  is why the schema route refuses an account it does not admit and a
  tool declares it; one with a `%key` names the subject's permission and
  is runtime.
- **Runtime**: anything else, an entity's or a config object's tag, the
  `user` context, max-age 0 for a State read, or nothing at all, since an
  answer with no cacheability promised nothing. It waits for the
  subject.

Write the cacheability your answer really has and the kind follows:

```php
public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
  $read = (new CacheableMetadata())->addCacheContexts(['user.permissions']);
  if ($account->hasPermission('inspect exhibit')) {
    // Static: the account alone.
    return AccessAnswer::locked('May be inspected, not changed.')->addCacheableDependency($read);
  }
  $exhibit = $this->configFactory->get('surface_resource_test.exhibit');
  $read->addCacheableDependency($exhibit);
  if ($exhibit->get('status') === 'sealed') {
    // Runtime: a stored value, under its config tag.
    return AccessAnswer::locked('The exhibit is sealed.')->addCacheableDependency($read);
  }
  return AccessAnswer::open()->addCacheableDependency($read);
}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Ask the account first, so its answers read nothing else.

What each kind does, by door:

| | Static | Runtime |
| --- | --- | --- |
| The build (`build()`), the form, the pipeline, the schema for these answers | applied | applied |
| The build for the account alone (`buildStatic()`) | applied | asked, set aside: the key kept as the static answers leave it, and marked |
| The full schema | locked: `readOnly`, with `const` where held; omitted: not there | there and writable, `x-surface.live` |
| The tool listing | locked: a locked property; omitted: not there | there, its description saying a call may find it locked or left out; when the answer is the resource's own, at the top, the tool's description says it once for every key instead |

So the full schema and the tool listing, which clients cache, are the
account's: a static lock or omission is stated for the account that
asks, and the full schema's cacheability carries the contexts the
answers read. A static answer is asked once per context and account
kind and kept (the latest 256 of them, so a long-lived worker does not
keep one per entity it was asked about). The kind is held, not trusted: with assertions on, a
static answer is asked again with the global container guarded and must
answer the same having read no site state, and a test trait holds the
full schema's marks to the build for sampled accounts
(`StaticAccessAgreementTrait`).

Composition follows the frames: a child's access answers in its own
frame, a mixed-in structure's for its members in the owner's (the
stricter standing), a variant answered at runtime is offered and the
variants' key marked, and a port carries no access. A static lock on a
parent over a runtime answer in its child leaves the child's key locked
with the parent and marked. At the top, a runtime answer set aside
marks every key, since the whole may be found locked. The exhibit in
the test module is the worked example (`StaticRuntimeAccessTest`).

An alter's answer has its kind read the same way, each answer by
itself: built for the account alone, the static answers of the owner
and its alters apply together and each runtime one is set aside, so a
static lock from the owner beside a runtime answer from an alter leaves
the key locked and marked. A permission-based answer of an alter
removes or locks keys in the full schema and the tool listing for the
account; a state-based one is settled at call time
([An alter's answer](#an-alters-answer)).

**Narrowing by account is not access.** "An editor sees fewer venues"
narrows the values a key allows, which is a refiner's: it reads the
account, so it is a runtime refiner, and the full schema keeps the key
wide and marked `live`. Access only says whether the keys may be
changed, seen, or neither. The refiner takes the account as its last
parameter, typed `AccountInterface`, and is handed the account the
operation runs as; it never reads the current user itself
([No door-specific context](#no-door-specific-context)).

```php
#[RefinesInput('room', runtime: TRUE)]
public static function roomsForAccount(DataDefinitionInterface $room, AccountInterface $account): DataDefinitionInterface {
  if ($account->hasPermission('use the vault')) {
    return $room;
  }
  return $room->addConstraint('LabeledChoice', ['choices' => array_diff_key(self::ROOMS, ['vault' => TRUE])]);
}
```

### A gate borrowed from another thing

A resource of your own may act on things another module owns: a bulk
retitle writes node titles with its own keys, storage and permission.
Whether the account may change each node is the node's owner's answer,
and every module that alters node access adds to it. A gate asks that
answer instead of copying the check:

```php
#[Resource('ai.retitle', storage: RetitleStorage::class)]
#[Operation('retitle',
  label: new TranslatableMarkup('Retitle nodes'),
  permission: 'retitle nodes',
  gate: ['entity:node:update', 'field:node.title:edit'],
)]
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

`gate:` takes one gate or a list, each in one of two forms:

- **Another resource's operation, by id**:
  `entity.content:node.page:update`. The answer is that operation's
  whole answer for the thing, as when it is asked on its own: its
  permission, its own gates, its access class and every alter that
  answers access for it, combined with `andIf()`. The thing is handed
  to that operation as its one required entity parameter; an operation
  that needs nothing is asked with nothing.
- **Core's access on an entity or a field**: `entity:node:update` asks
  the entity type's access control handler, `access()`, and
  `field:node.title:edit` its `fieldAccess()` for that field's items,
  with the cacheability core returns. This form works on a site with no
  derived resource for the entity type. Field access does not include
  entity access, which is why node's own form asks both, and a gate list
  may name both.

What the gate is asked about:

- **The thing the context knows.** An identity key declared as a
  reference to the gate's entity type (`$inputs->add('node',
  'entity:node')`), or the `entity_type` and `id` a content entity
  resource knows. The gate is asked after the operation's permission and
  before its access class, at every door that asks
  `ResourcesInterface::access()`: a refusal is a 403 on a route and an
  access refusal from a tool.
- **Each item of a collection.** When no identity key names the thing
  and an item of a collection has a key that refers to one, the gate is
  asked once per item, for the entity that key names. A refusal lands on
  that key's path in that item, `/items/0/node`, with the gate's reason,
  and the other items pass. A bulk call is refused whole if any item is
  refused, as any refusal refuses a call, and the gates run before the
  storage's `prepare()`, so a storage only ever sees items every gate
  allowed.

A refusal says what may not be done to what, then the owner's reason
when it gave one: "You may not update volunteer briefing for the night
shift: The 'edit any page content' permission is required." A gate whose
thing nothing names refuses; a gate naming an operation, a resource or
an entity type the site does not have is refused at discovery, naming
it, and a field gate takes `view` or `edit` alone.

**Narrow only.** The operation's own permission is asked first, and is
static. The resource's own access class is asked after the gates and
can only narrow: its answer is combined with theirs, so it refuses what
they allow and never allows what they refuse.

**The kind** of each gate's answer is read from its cacheability, as
any access answer's ([Static and runtime
access](#static-and-runtime-access)). A gate asked about the thing the
context knows is static when core's answer read the account's
permissions alone, and is then stated for the account, and runtime when
it read the thing, which marks every key `x-surface.live` in the full
schema and adds the tool's sentence that a key may be found locked or
left out once the thing is known. A gate asked per item is settled per
thing: the full schema marks the collection `live` and the tool listing
says on it that whether it may be changed is decided once the thing is
known. The tool listing declares only the operation's own permission.

The worked example is example 17, a bulk retitle of nodes
([its README](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-17-retitle-in-bulk-access-borrowed)),
and the fixture the test module's notes (`GatesTest`).

## Storage

`#[Resource(storage:)]` names a `ResourceStorageInterface` with three
verbs: `load()`, `prepare()` and `commit()`. It loads by the identity
the context knows, so the context never carries an entity, and creates
or updates by the context's `creates`. `ResourcesInterface::storage()`
adapts it to the pipeline's `SurfaceStorageInterface` and composes
it along the tree:

- A child whose class names storage is loaded from, prepared for and
  committed to it, in the context its parent hands it, plus the
  identity the parent accepted that it did not already know. An
  attached child (a field's storage) is committed **before** its parent,
  which is built on it; a variant (a field's settings) **after**,
  since it lives on what the parent writes.
- A child without storage, every structure among them, is stored by its
  parent under its key.
- An alter whose keys are asked for in one layout and stored in another
  implements `AltersStorageLayoutInterface`. Its layout is applied to that
  module's mount, `third_party_settings.<module>`, and nothing else:
  `toStorage()` before the storage class prepares, `fromStorage()` after
  it reads. `NodeTypeAlter` in the extras module asks for a review
  deadline as an amount and a unit; `NodeTypeStorage` writes the seconds
  core's form writes, and never knows the extras module exists.

## Prepare

`prepare(OperationContext $context, array $values, AccountInterface
$account): array` rehearses the write. It builds what `commit()` would
store, in storage's own layout, holds it to every check storage itself
would make (the config schema, which for a config entity is also its
entity validation) and has no side effects. It returns that array, and
`commit(OperationContext $context, array $prepared, AccountInterface
$account)` writes exactly it. Both are handed the account the write is
made as, which a storage that records who wrote reads there
([No door-specific context](#no-door-specific-context)).

- **A dry run stops after prepare** and reports what it returned. The
  pipeline's result carries it as `PreparedValues`; the adapter's
  `preview()` turns it into plain arrays (a resource with routed
  children reports its own under `own` and each child's under
  `children`, by the key it sits at), for a PHP caller; a derived tool's
  dry run answers the accepted `values` instead, as the read tool would,
  since what storage rehearsed holds what the caller may not see
  (why: [decision](decisions.md#every-door-projects-what-the-read-tool-projects)).
- **A real submit prepares first**, so `commit()` is never the first to
  find out storage would refuse. A refusal is a
  `StorageViolationsException` carrying violations filed under the
  input keys that own them, which the pipeline returns like its own;
  a routed child's are filed under the key it sits at. Every level is
  prepared before anything is thrown, so one answer names them all.
- What each storage class here checks: `NodeTypeStorage` builds the
  unsaved node type (a copy of the stored one, or a new one) and each
  base field override that would move, and holds them to `node.type.*`
  (fully validatable: a name with a line break is refused, as core's
  form refuses it) and to the override's schema; empty description and
  help are stored as none, as core's form stores them.
  `FieldStorageStorage` and `FieldInstanceStorage` build the unsaved
  storage and field (a field being added on a storage being added beside
  it is checked against an unsaved stand-in built from the same
  identity) and hold them to `field.storage.*` and `field.field.*`;
  `FieldStorageStorage` also refuses a field type other than its
  field's, and, once the field has data, a settings change that would
  alter its database columns, which the SQL storage would otherwise
  throw on at save. `AddressFieldSettingsStorage` holds the settings, in
  the layout the field stores, to `field.field_settings.address`.
- What a prepare returns is what is stored: the node type's prepared
  array is the config the commit writes, uuid included.

## No door-specific context

Everything an operation needs comes through it: the parameters and
identity its context knows, the values, the account, and site state
through services whose answers carry their cacheability. Nothing the
build or the pipeline runs on an operation's behalf reads the request,
its route, the session, the form being built, the theme a page renders
in, or the current user, because code that does behaves one way on the
form and another over HTTP or through a tool, and the doors stop
agreeing. The doors themselves (the operation form, the HTTP API, the
tool, the React page) read the request: that is their job, and each
turns it into a context, values and an account
(why: [decision](decisions.md#nothing-an-operation-runs-reads-the-request)).

| What runs | It may read | It gets the account |
| --- | --- | --- |
| A static refiner, a computed key's deriver | the keys it watches or is computed from, and nothing else | never |
| A declared rule | nothing: it is data the engine evaluates | never |
| A runtime refiner | site state through a service with cacheability | as its last parameter, typed `AccountInterface` |
| An access class, an alter answering access | site state with cacheability; a static answer the account alone | `access($context, $account)` |
| An alter's keys and refiners | site state with cacheability | as a runtime refiner does |
| A provider of keys or variants from the site, a deriver of variants or resources, an options resolver | site state with cacheability; never anything varying by the account | never |
| A storage's `load()` | what it stores | never: what is stored is no account's |
| A storage's `prepare()`, `commit()`, `prepareDelete()`, `delete()` | what it stores, and site state its checks read | as its last argument |

Refused to all of them: `request_stack`, `current_route_match`,
`router.request_context`, `session`, `session_manager`,
`tempstore.private`, `form_builder`, `path.current`,
`path_alias.manager`, `theme.manager` and `current_user`, by
`\Drupal::request()`, `::routeMatch()`, `::currentUser()`,
`::formBuilder()`, `::theme()`, `::service()` or injection alike.

**The account is an argument.** A door makes the account it runs for
the current user, and the build reads it once and hands it on: to an
access class as it always did, to each storage write verb, and to a
runtime refiner that asks for it. `ResourcesInterface::storage()` takes
an account for a write made as another than the current user, as
`access()` does, and a content entity written through
`ContentEntityStorage` is that account's and its new revision by it:

```php
$storage = $resources->storage('entity.content:node.page', $context, $surface, $account);
$pipeline->submit($surface, $values, $storage, access: $resources->access('entity.content:node.page', $context, $account));
```

**Held in three ways.** With assertions on, each such call runs with the
global container guarded (`Pipeline\PipelineGuard`): the class's own
code asking for one of those services throws, naming the service, the
class, the method and why, and an instance built with one injected is
refused when it is first used. Core's code it calls is core's. The
module's PHPStan rule (`tests/src/PHPStan/DoorSpecificRule.php`) flags
the same reads before anything runs. And
`Tests\surface\Traits\DoorAgreementTrait` sends one document
through the pipeline, the tool, the HTTP API and the form and holds
them to one answer, for an adopter's resources through
`ResourceTestBase` ([Testing a resource](testing-a-resource.md)) and
for this repository's in `EveryDoorAgreesTest`.

## Tools

With the `tool` module installed, an operation that can be asked on its
own is a tool, `surface:<resource id>:<operation id>`, derived by
`ResourceOperationToolDeriver` from the static layer alone:

| Tool | Inputs |
| --- | --- |
| `surface:node.type:create` | `values` (every key, `type` unique), `dry_run` |
| `surface:node.type:update` | `type` (the content type's id), `values` (every key but `type`), `dry_run` |
| `surface:field.instance:create` | `entity_type`, `bundle`, `values`, `dry_run` |
| `surface:field.instance:reuse` | `entity_type`, `bundle`, `field_name`, `values`, `dry_run` |
| `surface:field.instance:update` | `entity_type`, `bundle`, `field_name`, `values`, `dry_run` |
| `surface:field.storage:update` | `entity_type`, `field_name`, `values`, `dry_run` |

- **Inputs** are the operation's parameters, by name (an entity
  parameter as the entity's id, resolved when the tool runs; a scalar
  parameter named for one of the resource's keys described as that key,
  with its label, meaning and allowed values), then `values`, the
  resource's keys less the identity keys the operation knows, then
  `dry_run`. What `values` requires follows the operation's kind
  (`create`, `update` or `read`, read off its attribute and stated in
  both documents). An operation that creates requires what the
  resource requires and lists the declared defaults. One that changes a
  thing that exists requires nothing, at any depth, and lists no
  default: each key the resource requires says "Required; a value
  already stored stands when left out.", since a listing is cached by
  its clients and the Tool API refuses a required key left out before
  the tool runs. The pipeline still refuses an update
  of a thing that holds no value for a required key, in the key's own
  words
  (why: [decision](decisions.md#required-is-the-resources-and-an-update-tool-requires-nothing-at-its-door)).
  The definition is static, which is what operations make possible: an
  operation that needs nothing is the exact contract before anyone
  calls, and one that needs an existing thing refines `values` to its
  real context (a field's settings become its type's, each key
  defaulting to what is stored now) through the Tool API's own input
  refiners once its parameters arrive.
  A key another narrows says so in its description, once, by the path
  a caller sends it under: "Depends on venue." The Tool API holds
  `values` to its shape before the tool runs (types, the keys it
  requires, none on an update, its nesting), and the pipeline holds it to every rule,
  against the definitions the values sent narrow, so a refusal says
  what is allowed for them
  (why: [decision](decisions.md#a-refusal-names-what-it-depends-on)), by path
  and by what to send: "room: The value you selected is not a valid
  choice. This applies when Venue is riverside (Riverside Hall). Choose
  riverside_main (Main hall) or riverside_east (East room)."
- **Title and description** are the operation's. The title is its
  label; where operations of several resources share one, each tool
  adds its resource, and the read tool's is "Current values:" and the
  write tool's. The description is the operation's description, or its
  label where it says none, then one line of call rules for its kind,
  with no literal quotes, which an MCP bridge would escape:
  - create: "Create: a key left out takes its default, and every key
    marked required must be sent."
  - update: "Update: send only the keys to change; a key left out keeps
    its value, null clears it, and values {} changes nothing. The read
    tool returns the current values."
  - read: "Read only: nothing is written; a key left out is checked at
    its current value."
- **Behavior** is the operation's: the `Read` kind for a read only
  operation, the `Write` kind for every other, and destructive when it
  says so.
- **Access** is the operation's, decisively, then a variant its locked
  deciding key chose may refuse through its own access class.
- **Execution** is the operation, the build, and one pipeline submit to
  the composed storage; a dry run stops after prepare.
- **Outputs** are the accepted `values`, as the read tool would answer
  them (no key left out for the caller, no secret), `committed`, and the
  resource's own outputs, as its storage reads them back after the
  write; a dry run answers the same `values` and no outputs.

Two rules say what is not a tool, and the catalog applies the same
two (`ResourceCatalog::standalone()`):

1. **A permission nothing can name.** An operation whose permission has
   a `%key` placeholder that none of its parameters can supply (one
   named for it, or an entity its operation reads identity off) could
   never be allowed, so it is no tool. `field.storage`'s `create` needs
   nothing and asks `administer %entity_type fields`: only a field
   adding its storage can ask it, as that field's child.
2. **Something a host or a parent stores.** A class with no storage of
   its own (every structure), or one any plugin names with
   `#[UsesStructure]`, is configured through what holds it, which
   supplies the storage. It gets no tool, whatever it declares.

A resource or operation that cannot be described is left out and
logged.

Beside the write tool of an operation that changes a thing that exists
is a read tool, `surface:<resource id>:<operation id>:read`
(`ResourceOperationReadTool`, derived by the same deriver): a
`ToolOperation::Read` whose inputs are the operation's parameters,
exactly as the write tool declares them, and whose one output, `values`,
is what the composed storage loads, in the shape the write tool's
`values` takes, less the identity keys the operation knows and less
every secret, at any depth. Its access is the write tool's. Which
operations those are is `ResourceCatalog::reads()`: the updates, read
off the attribute, an operation that neither creates nor is read only.
So `node.type:update`, `field.instance:update` and `field.storage:update`
read, and `node.type:create`, `field.instance:create` and
`field.instance:reuse` do not.

## Catalog

`surface.resource_catalog` (`ResourceCatalog::describe()`) lists
every discovered resource and structure with its id, kind, class (none
for a derived one, whose `deriver` names the base), identity, storage,
access class, the plugins whose configuration it is
(`<host type>:<plugin id>`, from the plugin definitions, through
`surface.resource_plugins`), operations (id, label, parameters,
whether it creates, permission, the placeholders nothing supplies,
whether it can be asked on its own, and whether what it changes can be
read on its own), alters and declared variants, without building anything, whether an
operation creates included, since it is on the attribute.
`ResourceCatalog::derivers()` lists the
derivers, whose derivatives are the site's own.
[`catalog.md`](catalog.md) is both for this repository's modules,
generated by `scripts/generate-catalog.php` on a test site with no
bundles, so it lists the derivers and no derivative, and held to it by
`ResourceCatalogTest`.

## Plugins

A plugin keeps rendering; its configuration is a structure, named by
`#[UsesStructure]` on the plugin class. The host supplies the context
(`configure`, knowing nothing) and the storage (the plugin's own
configuration, or for a field type the field config Field UI is
editing), because only it holds the instance. The structure is a class
in `src/Structure/` the attribute names:

```php
#[Block(id: 'surface_examples_plugins', admin_label: new TranslatableMarkup('Surface API example'))]
#[UsesStructure(ExampleBlockStructure::class)]
final class ExampleBlock extends TypedBlockBase {

  public function build(): array { /* ... */ }

}
```

or, with no argument, the plugin class itself, which implements
`StructureInterface` with the same static methods a structure class has:

```php
#[FieldFormatter(id: 'surface_examples_plugins_string', label: new TranslatableMarkup('Surface API example formatter'), field_types: ['string'])]
#[UsesStructure]
final class ExampleFormatter extends TypedFormatterBase implements StructureInterface, DefinesOutputsInterface {

  public static function defineInputs(BlueprintInterface $inputs): void { /* ... */ }

  public static function defineOutputs(BlueprintInterface $outputs): void { /* ... */ }

  #[RefinesInput('variant')]
  public static function variantsOfCasing(DataDefinitionInterface $variant, string $casing): DataDefinitionInterface { /* ... */ }

  public function formatValue(FieldItemInterface $item, array $settings): array { /* ... */ }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

A plugin that is its own structure is found through the plugin
definitions (`ResourcePlugins::ownStructures()`), not a directory, and
discovery holds it to the same rules: it implements
`StructureInterface`, its declaration and refiners are static. Its id is
`<host type>:<plugin id>`, `field_formatter:surface_examples_plugins_string`,
unless the class also carries `#[Structure]`, whose id and access class
then apply. The catalog lists it as used by that same plugin, and an
alter names it by the plugin class. An access class it names is not
registered by the compiler pass, which never sees it; the class resolver
builds one, so it is constructor-free or implements
`ContainerInjectionInterface`.

A plugin may also name a resource, as the test module's pinned note
block names `PinnedNoteResource`; the host holds it all the same, and it
gets no tool of its own (see Tools).

Every host reads it the same way:

| Host | Base or trait | Context | Storage |
| --- | --- | --- | --- |
| Block | `TypedBlockBase` | `configure` | the block's configuration |
| Formatter | `TypedFormatterBase` | `configure` | none: the display stores it |
| Condition | `TypedConditionPluginBase` | `configure` | the condition's configuration |
| Action | `TypedConfigurableActionBase` | `configure` | the action's configuration |
| Field type | `SurfaceFieldTypeTrait` | `field_settings` | the field config's settings |
| Any configurable plugin | `SurfacePluginForm` | the form's operation | its configuration |

A host's methods take no arguments that say which structure or which
thing: `getSurface()` builds the structure the plugin names,
`getSurfaceStorage()` is the storage in the last column (a formatter
throws), and `surfaceAccess(?AccountInterface $account, string
$operation)` asks the structure's access class in the host's context,
neutral when it names none. A field type's data surface is
`getFieldSurface()`, on `FieldSurfaceProviderInterface`, which Field
UI's static `#element_validate` callback needs to find the rebuilt item
by.

- **Into the definition.** `ResourcePluginHooks` copies the attribute
  into each host's plugin definitions, under
  `UsesStructure::DEFINITION_KEY`: the class it names, or the plugin's
  own class when it names none, with one definition alter per host, run
  last so a class another module swapped in (`SurfaceAddressItem` for
  `AddressItem`) is the one read. A field item reaches it as its typed
  data definition, which core derives from the field type's. So the
  catalog lists which plugins use a structure without instantiating
  one.
- **The build.** `SurfaceHostTrait::hostedStructure()` builds the
  structure the definition names, in `new OperationContext($operation)`:
  the host's own verb, which is no declared operation, and nothing
  known. A plugin naming no structure is refused by name.
- **Static defaults.** A formatter's `defaultSettings()` and a field
  type's `defaultFieldSettings()` are asked of the class, so they read
  the attribute off the class and take `ResourcesInterface::defaults()`:
  the structure's own declaration alone, no alter, context or refiner.
- **Access.** A field type's host asks the field config entity, then
  the structure's access class, which may refuse. The gated test field
  type refuses that way, in Field UI and in the derived field tools
  alike.
- **No base class needed.** `SurfacePluginForm` serves any
  configurable plugin whose definition names a structure: it builds
  that structure in the form's operation and stores into the
  configuration array.

The example block, the example formatter, the address field type and every
plugin in the test module are written this way; the example formatter and
the test module's sticky note block are their own structure. What a
plugin holds is never a tool (see Tools).

`surface_examples_component_block` is the case of a plugin whose settings
are variants from the site: its block, "Component", names a structure of
example 12's two lines, a component chosen from those the site has and
its props read from the schema the component declares
(`ComponentProps`, the main module's provider), and renders the
component with the props it holds. The component is not required, since
the host builds the block from its defaults before anything is chosen.
A placed block is a config entity with a machine name, and
`surface_examples_block` declares it as a resource, `block.placement`, whose
plugin chooses its settings: for this plugin, the same structure, as
declared ([Variants from the site](#variants-from-the-site)). The tool
bridge derives its tools, which place, configure, read and remove a
block by its name. Its README, and example 13 at
`/surface-examples/13`, show both
(why: [decision](decisions.md#a-block-embeds-a-component-through-its-structure),
[decision](decisions.md#a-placed-block-is-a-resource)).

## Alters

An alter says which resource or structure it applies to with `#[Alters]`
on its class, optionally naming the operations it applies in. It names
the owner by its class; an id is only for a [derived
resource](#derived-resources), which has no class, and an id of an owner
that has one is refused at discovery. What it
adds is mounted under its own module's name, at
`third_party_settings.<module>.<key>`, so the owner's storage and schema
never have to know a contributor's keys. It adds no outputs: what a call
answers is its operation's, declared by the owner, and a reaction to a
save belongs to ECA; an alter that still has `alterOutputs()` is refused
at discovery. Its
`#[RefinesInput]` methods tighten the owner's keys, running after the
owner's own, and may tighten the keys the alter itself added, named as
the alter named them. They may watch the owner's keys and the keys the
alter itself added, the latter named the same way and handed as they
stand, NULL while unanswered, so the parameter takes NULL. A rule an
alter declares with `narrow()` reads and narrows the same keys
([Declaring a rule](refinement.md#declaring-a-rule)). A watched mounted
key is known everywhere else by its path,
`third_party_settings.<module>.<key>`. Another module's mounted key is
watched by no alter (why: [decision](decisions.md#an-alter-watches-its-own-mounted-key)).

An alter may also attach a structure, at its own mount and nowhere
else, `attach('third_party_settings.<module>', Structure::class, reads:
[...])`: the mount's keys are then the structure's, built in its own
frame with its own refiners, rules, alters and access class, and the
alter adds none of its own beside them. That makes the alter an
adapter: it wires the structure's [ports](#ports) to the owner's keys,
and keeps the glue for this one owner. The examples' compliance alter
attaches the compliance structure, whose stewards follow a `capacity`
port, and wires the port to example 3's capacity; its own rule reads
the structure's `license` at its mount and caps the owner's capacity at
a hundred while it is empty, so the capacity's dependencies, its AJAX
trigger and its `dependsOn` name the license by its path. The glue may
read the structure's keys and never narrows them
(why: [decision](decisions.md#an-alter-attaches-at-its-own-mount)).

The one widening an alter may make is `extendChoices()`: more values on
a key whose owner declared a list of allowed values. The values are the
alter's module's contribution, advertised under its name, and an
`#[RefinesInput]` method of the same alter on that key is that
contribution's refiner: it is handed the alter's values only, the
owner's methods never see them, and what is offered is the union.
`ExampleFormatterAlter` offers a ribbon variant on the example formatter and
keeps it to upper case.

Mounted keys need config schema, since the owner's storage holds every
write to it. On a config entity core describes
`third_party_settings.<module>` already, and the mounting module declares
its part as for any third-party setting. On a simple config object the
owner's schema declares `third_party_settings` as a sequence typed by the
module key, and the mounting module's declares its part, both written
from the resources when generated; `ConfigObjectStorage` refuses a mount
no schema describes ([Storage](storage.md#keys-another-module-mounts)).

`ExampleBlockAlter` in the extras example module does the three things most
alters do: it adds a badge, rewords the headline's help text with
`describe()`, and caps the number of items while the block is a grid.

```php
#[Alters(ExampleBlockStructure::class)]
final class ExampleBlockAlter implements AltersInputsInterface {

  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    $inputs->add('badge', 'string', $this->t('Badge'), default: 'star');
    $inputs->describe('headline', description: $this->t('Shown above the featured content, beside its badge.'));
  }

  #[RefinesInput('limit')]
  public static function shortInGrid(DataDefinitionInterface $limit, string $presentation): DataDefinitionInterface {
    // ...
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

`describe()` takes the owner's key by its name, the alter's own key by
its name, and another alter's key by its mounted path,
`third_party_settings.<module>.<key>`. A module's mount itself,
`third_party_settings.<module>`, is the fieldset its keys are drawn in:
titled with the module's name until its alter names it, once it has
added a key. The `third_party_settings` map around the modules' mounts
only groups them, so a form and the served contract draw nothing for it
([Generated forms](forms.md#other-modules-keys)).

An alter may arrange a key it does not own, `describe('capacity',
group: ..., help: ...)`: put it in a
group, weigh it, give or reword its placeholder, prefix, suffix and
help, over what the owner arranged, never emptying any of them
([Arranging the keys](forms.md#arranging-the-keys)).

On its own mount, and only there, an alter may also say where that
fieldset is drawn, with `after:`, naming a top-level key of the owner's
blueprint, a plain key or an attached part. The fieldset is then drawn
directly after that key instead of last, among the other modules'; when
the owner put that key in an arrangement group, inside the group, right
after the key ([A placed fieldset](forms.md#a-placed-fieldset)). The
examples' compliance alter ends with

```php
$inputs->describe('third_party_settings.surface_examples_compliance', label: $this->t('Compliance'), after: 'capacity');
```

so the license sits right under the capacity it lifts. Placement is
where the fieldset is drawn and nothing else: its values are still
stored, posted, validated and advertised at
`third_party_settings.<module>.<key>`, the config schema and the Tool
API see no change, and the served contract says it with one keyword,
`x-surface.after`, leaving the schema's nesting and order alone
(why: [decision](decisions.md#placement-is-a-describe-option)). `after:` on
anything but the calling alter's own input mount (an owner's key,
another module's mount, an output mount, which is never drawn) is
refused, as is a key the owner's blueprint does not declare at its top
level.

Nothing an alter declares removes: it cannot take a key or a value away
from the owner's declaration. A site policy that hides an owner's key
for everyone is a later concept, not a verb here. What an alter may do
is answer access, below, which locks or leaves out keys for an account
or once the thing is known, as any access answer does, and rule an
owner's key out while a key of its own holds a value, which narrows.
Example 14's module that manages keys rules the webhook's pasted key out
once a managed key is chosen at its mount,
`narrow('api_key')->when(Condition::present('key_id'))->doesNotApply()`,
and says what the webhook's code reads in its place, below
(why: [decision](decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter)).

### Standing in for a key at use

An alter that offers a key in place of an owner's says two things: what
is asked, and what the code that uses the resource reads. Example 14's
alter says both, one line each:

```php
$inputs->narrow('api_key')
  ->when(Condition::present('key_id'))
  ->doesNotApply();
$inputs->resolve('api_key', from: ['key_id'], using: ManagedKeyValue::class);
```

The first rules the pasted key out once a managed key is chosen. The
second resolves `api_key` at use: the code that uses the webhook reads
its values with `Resources::resolved()`, and there `api_key` holds what
`ManagedKeyValue::compute(['key_id' => ...])` answers, the managed key's
value. The webhook's code, `WebhookSigner`, names no managed key:

```php
$values = $this->resources->resolved('event.webhook');
return hash_hmac('sha256', (string) json_encode($payload), $values['api_key']);
```

What is stored: `api_key` as pasted, and `key_id` at the alter's mount,
`third_party_settings.surface_examples_keys.key_id`. What is not: the
managed key's value, which is read each time `resolved()` is called and
never written back. While a key in `from:` holds nothing (NULL, the empty
string or no value) the resolve is skipped and the stored value stands;
a name the class knows no value for resolves to NULL, and the stored
value does not stand in for it. Uninstall the alter's module and
`resolved()` answers the pasted key as stored.

The contract does not carry a resolve. Neither document, the tools, the
form nor the read states it: what is asked is unchanged, and the read and
every door leave a secret out as before. `resolved()` is for PHP code and
answers secrets. The class implements `ComputesValueInterface`, as a
computed key's does, and is handed the stored value of each key in
`from:` by the name the alter gave it; it runs at use and never ahead, so
it may read the store the value is kept in, never the request or the
account. A key is resolved once: a second resolve of it, by the owner or
another alter, is refused naming both. A resolve is a resource's for now,
at its top level and its alters' mounts; one in a structure is refused
(why: [decision](decisions.md#a-key-may-stand-for-another-at-use)).

Each class's `use` line: [Namespaces](concepts.md#namespaces).

### An alter's answer

An alter that implements `OperationAccessInterface`, the access class's
interface, answers access for the resource or structure it names:
`#[Alters]` on the class says it is an alter's answer, with the method
on the alter itself, `access(OperationContext $context, AccountInterface
$account)`, returning an `AccessAnswer` or core's result. It is asked
after the owner's own answers (the operation's permission, then the
access class), each alter in discovery order, and the stricter answer
stands (`AccessAnswer::andIf()`): an alter can lock or leave out the
keys of what it alters, its own mounted keys among them, and can never
open what the owner or an earlier alter locked or left out. Open, or
core's neutral, changes nothing. The operation's permission stays the
owner's; an alter that wants a further permission answers locked or
omitted for an account without it, with the `user.permissions` context.

It answers wherever the thing appears, in the frame it is built in: at
the top (locked builds it locked whole, omitted refuses the caller), as
a child, at each site of a structure attached twice, as one
variant, for a mixed-in structure's members in the owner's frame, for a
collection's item and at every entry of keys from the site; only in the
operations its `#[Alters]` names. Each answer's kind is its own,
read from its cacheability ([Static and runtime
access](#static-and-runtime-access)). The examples' workflow module is
the case on a site: two alters answer for example 10's program and its
fees, with no change to the program.

```php
#[Alters(FeesStructure::class)]
final class FeesWorkflowAlter implements OperationAccessInterface {

  public function access(OperationContext $context, AccountInterface $account): AccessAnswer {
    $read = (new CacheableMetadata())->addCacheContexts(['user.permissions']);
    if (!$account->hasPermission('see event fees')) {
      // Static: the account alone.
      return AccessAnswer::omitted('Only an account that may see event fees sees them.')->addCacheableDependency($read);
    }
    // Runtime: a stored value, read now.
    $read->setCacheMaxAge(0);
    // ...
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

An alter of a shape that implements it is refused at discovery: a shape
is one value with no access of its own. So is an access class, named on
`access:`, that carries `#[Alters]`: it would answer twice, once in each
role. An alter answers for a whole
resource or structure, never for one key of it; a key that needs an
answer of its own is a structure
(why: [decision](decisions.md#an-alter-may-answer-access-and-the-stricter-stands)).

### A whole alter

The examples' compliance alter is the shape most alters have, whole: its
`use` lines, a constructor the container calls with the services it
asks for (it is an autowired service, so the arguments are found by
type), `StringTranslationTrait` for `$this->t()`, the glue in
`alterInputs()`, and a runtime refiner reading State, the one kind of
refiner that may be an instance method. A test fails if this page and the
module differ.

<!-- code: modules/surface_examples_compliance/src/Alter/RegistrationComplianceAlter.php -->

```php
<?php

declare(strict_types=1);

namespace Drupal\surface_examples_compliance\Alter;

use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface_examples_compliance\Structure\ComplianceStructure;

/**
 * Example 4: others get a say.
 *
 * Another module's class, naming example 3's resource: an adapter. It
 * attaches the compliance structure at its own mount, so the license and
 * the stewards are stored under this module's name, and wires the
 * structure's capacity port to example 3's capacity. The rest is glue for
 * this one owner: it titles the fieldset and draws it right after the
 * capacity its license lifts, rewords one of the owner's labels, stops the
 * owner's capacity at a hundred without a license, a rule declared as
 * data, and leaves out a room the site has closed, which only the site
 * knows when it is asked. Example 3 is not changed and does not know this
 * module exists.
 */
#[Alters(RegistrationStep3Resource::class)]
final class RegistrationComplianceAlter implements AltersInputsInterface {

  use StringTranslationTrait;

  /**
   * The State key listing the rooms that are closed, by room id.
   */
  public const CLOSED_ROOMS = 'surface_examples_compliance.closed_rooms';

  /**
   * Constructs the alter, which the container builds as a service.
   *
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The State, where the closed rooms are listed.
   */
  public function __construct(TranslationInterface $string_translation, protected readonly StateInterface $state) {
    $this->stringTranslation = $string_translation;
  }

  /**
   * {@inheritdoc}
   */
  public function alterInputs(BlueprintAdditionsInterface $inputs): void {
    // The structure's capacity is example 3's.
    $inputs->attach('third_party_settings.surface_examples_compliance', ComplianceStructure::class, reads: ['capacity' => 'capacity']);
    $inputs->describe('title', label: $this->t('Public event title'));
    $inputs->describe('third_party_settings.surface_examples_compliance', label: $this->t('Compliance'), after: 'capacity');
    // Without a license, no more than a hundred, whatever the room seats.
    // The license is the structure's, which this alter reads at its mount.
    // A license its own pattern refuses is no license: a rule never sees
    // an invalid sibling.
    $inputs->narrow('capacity')
      ->when(Condition::absent('license'))
      ->constrain('Range', ['max' => 100])
      ->because($this->t('Up to 100 without an event license.'));
  }

  /**
   * No room the site has closed, which only the site knows when asked.
   *
   * A runtime refiner: what it answers is read from State when the
   * values are held to it, so no schema can state it ahead of time. It
   * watches the venue so it narrows the venue's rooms, which the owner's
   * refiner has already listed; the full schema still offers every room
   * per venue, and the pipeline refuses a closed one. The one refiner
   * here that is an instance method, which only a runtime refiner may
   * be: it reads the State this alter was built with.
   *
   * @see docs/decisions.md#static-by-default-runtime-by-declaration
   */
  #[RefinesInput('room', runtime: TRUE)]
  public function openRooms(DataDefinitionInterface $room, string $venue): DataDefinitionInterface {
    $rooms = $room->getConstraints()['LabeledChoice']['choices'] ?? [];
    $closed = array_intersect_key($rooms, array_flip((array) $this->state->get(self::CLOSED_ROOMS, [])));
    if ($closed === [] || !$room instanceof DataDefinition) {
      return $room;
    }
    $room->addConstraint('LabeledChoice', ['choices' => array_diff_key($rooms, $closed)]);
    return $room->setDescription($this->formatPlural(
      count($closed),
      'The @rooms is closed.',
      'The @rooms are closed.',
      ['@rooms' => implode(' and the ', $closed)],
    ));
  }

}
```

Its keys are stored in example 3's config object, under its module's
name, so two schemas describe them, and both are written from the
resources by `drush surface:config-schema --write`
([Storage](storage.md#a-schema-generated-from-the-resource)). The owner's
schema gains `third_party_settings`, a sequence typed by the module key,
in `surface_examples.registration_step3`:

<!-- excerpt: modules/surface_examples/config/schema/surface_examples.schema.yml -->

```yaml
third_party_settings:
  type: sequence
  label: 'Third party settings'
  requiredKey: false
  sequence:
    type: 'surface_examples.registration_step3.third_party.[%key]'
```

and the mounting module's schema gains its part, the type that key
names, each key of it free to be absent, since a mounted key is stored
only once it is sent:

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

An owner whose schema has no `third_party_settings` (written by hand
without it, or generated while no module mounting there was on) makes
every later alter on its simple config object refused until the owner's
schema changes ([Storage](storage.md#keys-another-module-mounts)). An owner
that is a config entity declares nothing, since core describes its
`third_party_settings`; the mounting module still declares its part.

## What the build checks

Each refusal names the class and method at fault:

- an identity key the class never declares;
- a `#[RefinesInput]` naming a key the class does not declare, or an
  output key;
- a watched sibling that is not a declared input;
- a removed attribute argument, named with what replaced it:
  `#[RefinesInput(watches:)]` (the parameter names),
  `#[Operation(default:)]` (the operation of the parent's kind) and
  `#[Operation(of:)]` (an operation stands on its own class), and an
  operation on a class that is no resource or structure, at discovery;
- two declarations of one operation id;
- a context constraint, or a refiner that watches nothing, that widens;
- a refiner that refines or watches a child's key, watches a
  collection, or a child's refiner that watches a key only its parent
  declares (a refiner of a collection that narrows inside its items is
  refused when the data surface is refined);
- variants chosen by a key that is not a plain input, or a variant its
  deciding key does not allow;
- a context constraint for a child's key;
- a class attached inside itself;
- a parent context naming an operation its child does not declare, or
  naming one that needs an argument nothing hands it;
- a port read under the name of a key, a refiner or rule narrowing a
  port, a required port left unwired, a wiring to a key the container
  does not declare, of another type or inside the child, a wiring of a
  port the child does not read, a cycle through a wiring, and a
  variant or a collection's item that requires a port
  ([Ports](#ports));
- an alter attaching anywhere but its own mount, beside keys of its own
  there, a collection, or a resource, a structure with children of its
  own, or one whose access is not open, at its mount;
- a structure mixed in that clashes, cycles, holds a child, stores
  itself, has outputs, is mixed in by an alter, or is wired wrong, and
  an owner naming what it may not of it ([Mixins](#mixins)).

## Children

A resource or structure attaches others at a key. Each child is its own
class, with its own keys and refiners, and alters can reach it alone.
A child is usually a structure; it may be a resource with storage of its
own, as a field's storage is.

A value asked for at several keys is no child: a child is a group of
keys behind the wall, and one value with its type, pattern and words is
a shape, declared once in `src/Shape/` and used in the type position of
`add()`, `$inputs->add('license', License::class, t('Event license'))`,
each key narrowing it as it needs and an alter of the shape tightening
it at all of them ([Named shapes](declaring-a-resource.md#named-shapes)).
The compliance structure's license is one; a shape handed to `attach()`
is refused.

- `attach('key', Child::class)` is a fixed child.
- `attachVariants('key', by: 'sibling')` attaches **variants**: children
  the sibling's value chooses between, one per value
  ([Variants](#variants)).

`attach()` and `attachVariants()` return the map definition at the key, a
core `MapDataDefinition`, the way `add()` returns its definition, so
the owner labels and describes its own child's key with the core
setters. `describe()` rewords a key for an alter that does not own it,
and arranges a key for its owner or an alter; an owner rewording its
own key with it says in two statements what one says.

What that means, end to end:

- **A child is built by the same build step**, after its parent's
  context is applied: its own keys, its own alters, its own refiners.
  It is invoked through one of its own operations when one applies
  ([Child operations](#child-operations)), and otherwise sees the
  context the parent's context hands it with `withChild()`, or the
  parent's operation, `creates` and known identity, never the parent's
  constraints, which name the parent's keys.
- **Its value is a map at the key**, advertised as a map whose
  properties are the child's keys. The child accepts, validates and
  refines it, in its own frame. Violations come back dotted under the
  parent's key (`presentation_settings.columns`); that, and an emitted
  schema, are the only places a path crosses into a child.
- **The wall.** A parent cannot refine a child's key or watch one; a
  child cannot watch its parent's keys. Two doors cross it, each named
  by the child and opened by the parent: the context, through which a
  parent hands a child what it needs as identity, the way the field
  instance resource's operations tell its settings which field they
  belong to; and a [port](#ports), a value the child declares it reads
  under a name of its own, which the parent wires to one of its keys.
  The key a child sits at is the parent's own, so a parent's refiner
  may say it [does not apply](refinement.md#when-a-key-does-not-apply),
  and nothing else about it.
- **Narrowing reads inside maps.** A refined map holds exactly the
  properties it was handed, each held to the same table, list items
  too; the one shape refinement may introduce is the variants'
  placeholder resolving to the chosen variant.
- **Storage composes along the tree.** A child whose
  `#[Resource(storage:)]` names a storage class is loaded from and
  committed to it (an attached child before its parent, a variant
  after) in its own context plus whatever identity the parent accepted
  that it did not already know; a child without one is stored by its
  parent under its key. `ResourcesInterface::storage()` builds the
  composed storage (see Storage, above).
- **Emission.** The tool bridge converts an attached child to a nested
  map input, and variants a class declares that nothing has chosen yet
  to the widest honest map: every variant's keys, none required and none
  with a default, each annotated with the values it belongs to, the
  table in the description. The Tool API cannot say a union keyed by a
  sibling. Variants the site lists state only the keys of the one the
  stored values choose ([Variants from the site](#variants-from-the-site)). The
  served contract can, and writes each child once, as a def under
  `$defs` that every place it is used refers to: the wall is where a
  reference starts ([reusable subschemas](served-contract.md#reusable-subschemas)).

An alter attaches only at its own mount ([Alters](#alters)), and an
output attaches nothing; both are refused by name otherwise.

### Variants

Variants are a tagged union whose tag is a sibling key.
`attachVariants('key', by: 'sibling')` attaches them at the key, and the
sibling, the deciding key, chooses one. They are always open. The parent
never names them: every class marked
`#[Variant(of: Parent::class, at: 'key', for: '...', label: ...)]` is the
variant for that value, and the sibling's allowed values become exactly
the values the variants are for, each offered by the variant's `label:`
(a `LabeledChoice`), else by the label the structure itself carries,
`#[Structure(label:)]` or its plugin's; a variant with neither is
offered as its value. The `#[Variant]` label is the choice's word
("Paid"), the structure's what the structure is called ("Paid ticket"),
which also titles its def
([What it is called](declaring-a-resource.md#what-it-is-called)).
A new variant brings itself, its value and its label, and the parent
never changes.

The deciding key declares no list of its own, then: the union's tags
live on its variants, written once. A list it does declare is a
narrowing of the variants' values, and keeps its own words: a variant
whose value it leaves out is not offered, and a value it names that no
variant is for is refused when the parent is built ("... lists "pot"
for "kind", and no variant at "settings" is for it."), since it would
choose nothing. Such a list closes the union to a module that adds a
variant, unless that module offers the value as well
(`extendChoices()`); leave it out to keep the union open.

A variant may declare no keys: a choice with nothing more to say (a
daily digest has no send day, a weekly one does). It renders as
nothing on the form and the React page, its value is an empty map, and
both contracts and the tool state it as an object with no properties.
`DigestResource` in the resource test module is the fixture; one class
per value, since `#[Variant]` names one value.

The example block's presentation has two variants, its two presentation
structures, which say the presentation's values and what each is
called; the field instance resource attaches its storage, a resource of
its own, and its settings are variants each field type's module adds,
as are the storage's own settings:

```php
// ExampleBlockStructure
$inputs->add('presentation', 'string', t('Presentation'), default: 'list')
  ->setRequired(TRUE);

$inputs->attachVariants('presentation_settings', by: 'presentation')
  ->setLabel(t('Presentation settings'))
  ->setDescription(t('What the chosen presentation needs.'));

// ListPresentationStructure and GridPresentationStructure, beside it
#[Structure('block.surface_examples_plugins.presentation.list',
  label: new TranslatableMarkup('List presentation'),
  description: new TranslatableMarkup('What the example block needs to render a list: whether summaries show.'),
)]
#[Variant(of: ExampleBlockStructure::class, at: 'presentation_settings', for: 'list', label: new TranslatableMarkup('List'))]

#[Structure('block.surface_examples_plugins.presentation.grid',
  label: new TranslatableMarkup('Grid presentation'),
  description: new TranslatableMarkup('What the example block needs to render a grid: how many columns, and whether summaries show.'),
)]
#[Variant(of: ExampleBlockStructure::class, at: 'presentation_settings', for: 'grid', label: new TranslatableMarkup('Grid'))]

// FieldInstanceResource, in surface_examples_field
$inputs->attach('storage', FieldStorageResource::class)
  ->setLabel(t('Field storage'));

$inputs->attachVariants('settings', by: 'field_type')
  ->setLabel(t('Field settings'));

// FieldStorageResource, in surface_examples_field
$inputs->attachVariants('settings', by: 'field_type')
  ->setLabel(t('Storage settings'));

// AddressFieldSettingsResource, in surface_examples_address
#[Resource('field.settings.address', storage: AddressFieldSettingsStorage::class)]
#[Variant(of: FieldInstanceResource::class, at: 'settings', for: 'address')]
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Until the deciding key holds a value, the key is advertised as an `any`
placeholder marked as variants (`DefinitionMetadata::variantsBy()`); once
it does, or from the start when the context locks the deciding key, it is
exactly the chosen variant's map. That is an ordinary refinement under
the rule that `any` may become anything narrower, not a mechanism of its
own. The deciding key gains a list of the variants' values, labeled as
they say, or keeps its own, narrowed to the variants offered (one an
access class withholds is not), and becomes a refinement
dependency of the key the variants sit at, so the form rebuilds on it
over AJAX and the discard cascade drops input left by another variant. A
payload carrying another variant's keys is refused by name
(`presentation_settings.show_summary belongs to the list variant, but
presentation chose grid.`); a stored value that does not fit a newly
chosen variant falls back to that variant's defaults, but a secret of
another variant, which is kept beside them for the day its variant is
chosen again (why: [decision](decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter)).

### Ports

A structure needs a value its container holds more often than it needs
the container: the stewards an event needs follow its capacity, the
aisles a room has follow its layout. The wall keeps the structure from
naming the container's key, so it declares what it reads instead, a
**port**, under a name of its own:

```php
#[Structure('registration.compliance')]
final class ComplianceStructure implements StructureInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('capacity', 'integer', required: TRUE);

    $inputs->add('license', License::class, t('Event license'));

    $inputs->add('stewards', 'integer', t('Stewards'), default: 1)->setRequired(TRUE);
  }

  #[RefinesInput('stewards')]
  public static function stewardsForCapacity(DataDefinition $stewards, int $capacity): DataDefinition {
    // ...
  }

}
```

and whatever attaches it says which of its own keys each port reads:

```php
// A resource, at a key of its own.
$inputs->attach('compliance', ComplianceStructure::class, reads: ['capacity' => 'seats']);

// An alter, at its own mount: the compliance alter on example 3.
$inputs->attach('third_party_settings.surface_examples_compliance', ComplianceStructure::class, reads: ['capacity' => 'capacity']);
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **A port is a live sibling value.** The structure's refiners watch it
  by its name, and its declared rules name it in a condition, as they
  would a sibling key: it gates a coded refiner while it holds nothing,
  a rule reads it as it stands, and a value its own key refuses is
  handed as nothing. It moves as the wired key moves: the form rebuilds
  the structure over AJAX when the key changes, a refusal names the key
  (`... when Capacity is 150.`), and the discard cascade follows it.
  Nothing stores, draws or accepts a port in the structure: it is the
  container's key, there.
- **A port has a type and may be required.** `reads($port, $type,
  required: TRUE)`; an optional port a container leaves unwired holds
  nothing, so a refiner watching it never runs and a rule reads it as
  absent. The type is a typed data type, and the wired key must have it
  (`any` reads any key); or a shape's class, `reads('code', Sku::class)`,
  and the wired key must then be declared with that shape, which it may
  have narrowed ([Named shapes](declaring-a-resource.md#named-shapes)).
- **The container wires each port** in `attach(..., reads: [port =>
  key])`, naming one of its own plain input keys. The build refuses a
  required port left unwired, a key the container does not declare, a
  key of another type, a key inside the child itself, a port the child
  does not read, and a wiring that would make a cycle in the refinement
  map, naming it. A variant and a collection's item are wired by
  nothing yet, so neither may require a port.
- **Identity is a port of a fixed kind.** What a parent hands a child
  through `withChild(known:)` is read from the container too, but
  settled when the parent is built: it locks the key the child declares,
  selects what the child's storage loads and fills its permission. A
  port is the live kind, a value the caller may still change, and locks
  nothing.
- **Emission.** The structure's def is the same at every site and names
  no container: it lists its ports (`x-surface.ports`), and a key that
  reads one names it in its `dependsOn`. What depends on a port is
  stated at the site, on the container's `allOf`, an `if` on the
  container's key and a `then` reaching into the structure where it
  sits; the site's `x-surface.reads` says which key each port reads, and
  its `dependsOn` names those keys ([the served contract](served-contract.md#a-port-is-stated-at-its-site)).

The test module's seating structure reads a layout (required) and a
number of guests (optional); the venue attaches it at `seating`, wiring
both, the hall at `chairs`, wiring the layout alone, and the hall's
`HallSeatingAlter` at its own mount, wiring both to other keys of the
hall (`PortsTest`).

### Mixins

Attaching nests a structure under a key, behind the wall. Mixing it in
merges its members into the owner's own keys instead, where the call
stands among them:

```php
#[Resource('resource_test.journal', storage: JournalStorage::class)]
final class JournalResource implements ResourceInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('title', 'string', t('Title'))->setRequired(TRUE);

    $inputs->add('visibility', 'string', t('Visibility'), default: 'staff')
      ->setRequired(TRUE)
      ->addConstraint('LabeledChoice', ['choices' => ['public' => t('Public'), 'staff' => t('Staff')]]);

    $inputs->mixIn(OwnershipStructure::class, reads: ['audience' => 'visibility']);

    $inputs->mixIn(TimestampsStructure::class);

    $inputs->add('revision', 'integer', t('Revision'), default: 1);

    // ...
    $inputs->narrow('revision')
      ->when(Condition::absent('created'))
      ->constrain('Range', ['max' => 1]);
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

The journal's keys are `title`, `visibility`, `owner`, `shared`,
`created`, `changed`, `revision`: the ownership's two and the
timestamps' two are the journal's own.

- **Nest or mix in.** Nest for a thing of its own inside this one (a
  contact, a ticket, the journal's archive); mix in for shared members
  that are part of this thing (timestamps, ownership, publishing
  flags). A mixin is an ordinary `#[Structure]`: the ledger attaches
  the same timestamps under `stamps`.
- **The owner's keys.** Stored flat beside the owner's own, described by
  the owner's config schema ([Storage](storage.md#keys-a-mixed-in-structure-brings)),
  drawn at the top of the owner's form, accepted, validated and named
  in a refusal as the owner's (`changed`, not `timestamps.changed`), and
  flat in the tool's `values` and the read tool's output. A structure
  mixed in may mix another in, whose members are then the owner's too
  (the test module's entry mixes in the record, which mixes in the
  timestamps).
- **Refined in the owner's frame.** The structure's coded refiners and
  declared rules are bound in the owner's chain, before the owner's
  own, and narrow only its own members, watching or reading only those
  and its ports. A port is declared as for a structure attached
  (`reads()`) and wired by the owner in the same spelling,
  `mixIn(..., reads: ['audience' => 'visibility'])`, to one of the
  owner's keys, of the port's type, that the structure did not bring;
  every port is wired, required or not, since the structure's refiners
  read it as that key.
- **The owner may read and narrow the members**, as its own keys: the
  journal's rule reads `created`, and another requires it once the
  journal is published. Two things it may not do: rule a member out,
  which is the structure's to say, and name a member of a structure that
  has an access class at all.
- **Alters.** The owner's alters see the members as the owner's keys. The
  structure's alters apply wherever it is mixed in, as wherever it is
  attached, on its members: the test module's `TimestampsAlter` rewords
  `created` in the journal and the ledger alike. One that would mount a
  key is refused where the structure is mixed in.
- **Access.** The structure's access class answers for its members,
  and so does an alter of the structure that answers access, after it:
  locked, they hold what is stored, shown and fixed; omitted, they are
  not there and storage keeps them. The owner's access answers for the
  rest, and where both answer for the same keys the stricter stands
  (why: [decision](decisions.md#a-mixin-is-merged-flat)).
- **One def.** Both documents write the structure once, as a def, and
  the owner's frame is `allOf` of a reference to it and the owner's own
  properties, closed with `unevaluatedProperties`
  ([the served contract](served-contract.md#a-mixed-in-structure-is-composed)).
- **Refused at build**, naming the class or the method: a member the
  owner, or another structure mixed in, declares too; a structure mixed
  into itself, directly or through another; a structure that attaches a
  child, attaches variants or a collection; a resource; one with outputs;
  a `mixIn()` from an alter; an unwired port, one wired to a key the
  owner does not declare, to one of the structure's own members, or of
  another type; an owner's refiner or rule ruling a member out, or
  naming a member of a structure with an access class; a structure's
  alter that mounts a key.

### Reusing a structure from another module

A structure declared in one module is attached by another the same way
its own module attaches it. The recipe:

1. **Depend on the structure's module** in the reusing module's
   `.info.yml`, so the class is discovered wherever it is attached.
2. **Attach it and wire its ports**, at a key of the resource's own,
   `attach('compliance', ComplianceStructure::class, reads: ['capacity' =>
   'seats'])`, or, from an alter, at the alter's own mount. Every
   required port must be wired, to a key of the container of the port's
   type. Or mix it in, `mixIn(ComplianceStructure::class, reads: [...])`,
   when its keys are part of the resource rather than a thing of their
   own ([Mixins](#mixins)).
3. **Describe its keys in config schema**, since the container's
   storage holds them: the structure stores nothing itself. A container
   stored in a simple config object has them written for it, under the
   key the structure sits at, and at the mount for an alter, by the
   convention for keys another module mounts
   ([Storage](storage.md#keys-another-module-mounts)), when its schema is
   generated (`drush surface:config-schema --write`). A schema
   written by hand describes them the same way; a mapping type of the
   structure's keys, declared once, can serve every site.
4. **That is all.** The structure brings its refiners, its declared
   rules, its access class and its operations, and keeps its def id in
   both documents: one def, however many containers attach it. The
   alters of the structure apply wherever it is used; a container
   cannot opt out of them yet (open; why:
   [decision](decisions.md#a-port-is-a-live-sibling-value)).
   A change to the structure's keys changes every container's stored
   layout, which is Phase D of the roadmap, with an explicit contract to
   diff.

### Child operations

A child is invoked the way a resource asked on its own is: through one
of its own `#[Operation]` methods, so its permission, its access class,
its alters' `operations:` and the identity its context knows work the
same at both levels. Which one is decided when the parent is built, the
first of these that applies:

1. **The parent's context names it.** `withChild('contact', operation:
   'enter', known: ['email' => $email])` names the child's `enter`
   operation and hands it identity. Naming an operation the child does
   not declare is refused at build, naming the parent, the key and the
   operation. A context handed whole, `withChild('storage',
   FieldStorageResource::update($storage))`, is still taken as it is: the
   parent built it, so the child invokes no operation of its own through
   it, and no operation's permission is asked of it. Its access class
   still answers, below.
2. **The child's operation with the parent's operation id.** Adding a
   field invokes its storage's own `create`.
3. **The child's operation of the parent's kind**, the one with the
   standard id of the kind of the parent's operation: `create` for a
   parent that creates, `update` (or `configure`, for a child with no
   identity keys) for one that updates, `read` or `list` for one that
   reads, `delete` for one that deletes. Copying a larder, a create
   whose id the rack lacks, invokes the rack's own `create`. (A
   `default:` on `#[Operation]` said this until 2026-10-24, and is
   refused at discovery.)
4. **Otherwise it inherits**, exactly as a child always did: the
   parent's operation, `creates` and known identity, and no operation
   of its own. Every child in the examples is this case.

The child's operation method is called with the arguments it declares,
read by name from the identity the parent handed it and then from the
parent's known identity (an entity parameter takes the entity or its
id, as on a route). The context it returns then knows that identity
too; on a clash **the child's own known keys win**. `creates` is the
child's context's, as for a resource asked on its own. An operation the
parent named that needs an argument nothing supplies is refused at
build, with the `withChild()` call that would hand it; one found by the
parent's operation id or by its kind is passed over for the next
step, since a context that knows nothing yet (what a tool's static
definition and the catalog build in) cannot invoke a child operation
that needs identity.

The child's access then decides its keys for the current account
([Three answers](#three-answers)), and two rules say what is asked:

- **The child's access class always answers**, however the child was
  invoked: named, matched, by the parent's kind, inherited, or a context handed
  whole. A module like field_permissions hides a single field through
  access, and a parent building its child's context itself must not be
  a way around that. What the answer read reaches the parent's
  cacheability, so both contracts and the form vary by it. An inherited
  child with no access class is asked nothing and stays open.
- **An operation's permission gates only an operation the build
  resolved** (named, matched or by the parent's kind): its `%key` is filled from
  the child's context, and a permission the account lacks omits the
  child; one the context cannot name, or the very one the parent's own
  operation asks, says nothing, the parent's access having asked
  already. A context handed whole asks no permission: the parent that
  built it took responsibility for entering it, as a field does for its
  storage.

Permission is about entering an operation; access is about what may be
done with what is there, and only access composes down the tree. Where
both are asked, the narrower answer stands. An alter naming an
operation of the child, `#[Alters(Child::class, operations: ['enter'])]`,
applies only where that operation is invoked. A variant is resolved
the same way, one variant at a time; a variant whose access
omits it is not offered, so its deciding value can no longer be chosen,
and nothing is derived in its place; one whose access locks it is
offered read only, built locked whole: it can be chosen, and is drawn
and stated fixed (why: [decision](decisions.md#a-variant-is-open-locked-or-not-offered)).

The test module's larder is the worked example: one spice rack resource
attached twice, invoked through its `update` at one key, matched by the
larder's own operation id, and through `restock`, which the larder's
update operation names, at the other, with an access class that answers
open, locked or omitted by permission. Its `stock` operation hands both
racks a context whole, where the access class answers the same and
`restock`'s permission is not asked (`ChildOperationTest`).

### Variants from the site

Some variants are the site's, not the code's: one per component a
site's themes provide, one per layout its configuration lists. No class
can be written for each, and each says what it holds already, in a
JSON Schema. `attachVariants()` names a provider that lists them, each
with its schema, and the reader writes each schema into a variant
([Reading a schema](reading-a-schema.md)). The provider is the one
interface [keys from the site](#keys-that-come-from-the-site) name too,
`SiteListInterface`, and its list the one list type, `SiteList`; each
verb reads what it needs of an entry and refuses the rest:

```php
// SpotlightResource, example 12
$inputs->add('component', 'string', t('Component'), default: 'surface_examples:card')
  ->setRequired(TRUE);
$inputs->attachVariants('props', by: 'component', from: ComponentProps::class)
  ->setLabel(t('Props'));

// ComponentProps, the main module's, built by the class resolver
final class ComponentProps implements SiteListInterface {

  public function list(): SiteList {
    $variants = (new SiteList())->addCacheTags(['config:core.extension']);
    $components = $this->components->getDefinitions();
    ksort($components);
    foreach ($components as $id => $component) {
      $variants->add((string) $id, (string) ($component['name'] ?? $id), schema: self::props($component['props'] ?? []));
    }
    return $variants;
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **The provider** implements `SiteListInterface`: `list()` answers a
  `SiteList`, per value of the deciding key its label and the schema of
  what it chooses, `add($value, $label, schema: $schema)`, an object in
  JSON Schema 2020-12 or an older draft. An entry that says a start or a
  lock, which only a key per thing reads, is refused, and so is one with
  neither a schema nor a structure. It is built by the class resolver, and asked at every
  build, as a provider of [keys from the site](#keys-that-come-from-the-site)
  is, with the same rules for its cacheability: what it read is the
  built resource's, a list with max-age 0 is refused, and so is one that
  varies by the account.
- **Each variant is a structure read from its schema**, its id
  `<owner>.<key>.<value>` (`event.spotlight.props.surface_examples:card`),
  built by the build step as a class is: its keys, their constraints,
  its maps, children, variants and rules, what its schema says.
- **Beside the classes.** Every `#[Variant]` at the key is a variant as
  before, and wins for a value both name; the deciding key offers each
  value under the provider's label, unless it declares a list of its
  own, which narrows them as it narrows a class's.
- **Stated as variants are.** Both documents write each variant as a
  def and the key as `anyOf` of them, with one conditional per value;
  the tool lists them, the form swaps the keys over AJAX, the React page
  narrows locally, and a payload carrying another variant's keys is
  refused by name: `props.align belongs to the surface_examples:hero
  variant, but component chose surface_examples:card.`
- **Past the site list limit, deferred.** While the provider lists at
  most `surface.settings:site_list_limit` variants (20 as shipped),
  both documents state each. Past it, the full schema states the key
  open, marked `deferred`, the deciding key still listing every value;
  the full schema fetched with a value chosen,
  `GET .../schema?choose=/props=<value>`, and the schema for these
  answers state the variant chosen
  (why: [decision](decisions.md#a-list-left-out-for-size-is-deferred-not-refined)).
- **Never spelled out in a tool's listing.** Whatever the limit, the
  tool listing states the keys of the variant the stored values choose
  and no other, the deciding key still listing every value, and names
  the fetch and the narrowing tool,
  `surface:<resource>:<operation>:schema`, which answers the chosen
  variant to an agent that asks with values
  (why: [decision](decisions.md#a-budget-for-what-the-site-decides)).
- **Fewer components than the site has.** `ComponentProps` and
  `ComponentSlots` list the components `surface.settings:components_offered`
  allows: an entry with a colon is a component id (`provider:name`), one
  without a provider, meaning every component it provides; empty, as
  shipped, every component (`ComponentsOffered`). A slot that expects
  nothing in particular offers the same; one that names its components
  keeps them. Both lists carry the settings' cache tag, so the documents,
  the form and the tool listing follow a change. A stored component left
  out is never made unreadable: the chooser stands for it on its empty
  option ("- Keep ... (no longer offered) -"), a save that leaves it
  keeps the component, its props and its slots as stored, and every door
  reads them back; its props are drawn again once it is offered. The
  limit and the allowlist are two answers: one moves a long list out of
  the documents, the other shortens it
  (why: [decision](decisions.md#a-budget-for-what-the-site-decides)).
- **Storage.** The key is open in the config schema written from the
  resource (`type: ignore`): which variants a site has is not the
  code's, and the chosen variant holds the value to its own keys before
  anything is written. A provider whose every variant is a structure its
  code names says so, `ClosedSiteListInterface` and a static
  `structures()`, and the key is then written as a class's variants are,
  a type per value, whichever the site offers now. Its list names each
  value and its label alone, `add('pasted', $this->t('Pasted here'))`:
  the structure is named once, in `structures()`, and an entry naming
  one of its own, or a value `structures()` does not name, is refused.
- **One value is fixed.** A deciding key whose variants offer one value,
  and which declares no list of its own, is drawn as that value, not as a
  select of one, and both documents state it as `const`, its label under
  `x-surface.fixed`.

The test module's poster reads three layouts, one in draft-04, beside a
class that wins for the banner (`VariantsFromSiteTest`); example 12
reads every single-directory component the site has. Example 14's mailer
lists two structures of its own, a credential pasted or a key the site
manages, the second only while the module that manages keys is on
(why: [decision](decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter)).

A placed block is the case at scale. `surface_examples_block`'s
`block.placement` attaches its `settings` with
`attachVariants('settings', by: 'plugin', from:
BlockPluginStructures::class)`: one variant per block plugin the block
layout offers. A plugin whose class names its settings with
`#[UsesStructure]` is that structure, built as declared, which a
provider says with `SiteList::add($value, $label, structure: $class)`
in place of a schema; the component block's is one, its own
variants, the components' props, inside it. Any other plugin is read
from its config schema, `block.settings.<plugin id>`, written as JSON
Schema for the reader. The keys every block has (its title, whether it
shows, its theme, region, weight and status) are the resource's own, and
its visibility a collection of conditions chosen the same way
(why: [decision](decisions.md#a-placed-block-is-a-resource)).

A field type's settings are the case again, in `surface_examples_field`.
`FieldInstanceResource` attaches its `settings` with
`attachVariants('settings', by: 'field_type', from:
FieldSettingsSchemaVariants::class)`, and `FieldStorageResource` its own
from `FieldStorageSettingsSchemaVariants`: one entry per field type
offered in the UI, labeled with the field type's label, its schema
`field.field_settings.<field type>` (or `field.storage_settings.<field
type>`) written as JSON Schema, with the field type's own default
settings where their type fits. The address module's settings resource
is a `#[Variant]`, and wins for `address`. So the field tools offer a
plain `string` or `integer` field as they offer an address field, with
what the schema says and nothing more. The list counts against the site
list limit as any other: on a site offering more field types than that,
the field tools state `settings` open and `deferred`, fetched per field
type (why: [decision](decisions.md#derived-variants)).

Variants from the site nest. A schema a provider lists may hold
variants of its own, a sibling's `const` choosing among objects, and
those again: a component's slots each hold a component, whose own props
and slots are variants of the component it names, three deep
(`ComponentSlots`, beside `ComponentProps` at `slots`; why:
[decision](decisions.md#a-slot-holds-text-a-component-or-components)).
The same shape stands at many places in such a tree, so a provider hands
the defs its entries share once, `SiteList::addDefs($defs)`, and an
entry whose schema is a `$ref` into them is that def. Each def is one
read structure, built once per request and context and held at every key
it is attached at, so a site with hundreds of components builds a
structure per def, not one per place a def is used. Each level counts
against the site list limit by itself: on a site with 312 components,
the props and slots are `deferred` at every depth, and the documents hold
the chosen component's defs alone.

A long list need not be read whole to be listed. A provider that
implements `EntrySchemasInterface` lists each entry by value and label
alone and answers one entry's schema when a door reads that variant,
`entrySchema($id)`, and the defs the entries share only when one of them
refers to them, `entryDefs()`. What the two answer is what `list()` would
have said with `schema:` and `addDefs()`, so a variant reads the same
either way; an entry the list gives a schema keeps it. `ComponentProps`
and `ComponentSlots` are both: building the spotlight reads no component,
the full schema past the limit reads none, the form and `?choose=` read
the chosen one, and the pipeline reads each entry's schema, without
building it, to know which keys are secret (why:
[decision](decisions.md#the-static-build-is-cached-the-rest-is-per-request)).

### A child that cannot be listed

Variants cover a child chosen from a set of values someone can name. A
child whose keys cannot be enumerated at all is the one case for a
`#[RefinesInput]` method on an `any` key that returns the narrower
definition itself, a map included. No verb is needed for it: `any` may
become anything narrower, and the method is an ordinary refiner.

### Collections

`attachList('key', Child::class, min: 1, max: 4)` is a collection: a
key whose value is a list, every item of which is an instance of the
child. It returns the list definition at the key, a core
`ListDataDefinition` whose item is the child's map, so the owner labels
and describes it with the core setters, as `attach()` returns its map.
`min` and `max` are the list's `Count`; a NULL `max` is unlimited. The
child is named the way `attach()` names one, by class or by a derived
resource's id, and a derived resource's inputs callable may call it too.

```php
// CellarResource, in the test module
$inputs->attachList('racks', SpiceRackResource::class, min: 1, max: 4)
  ->setLabel('Spice racks')
  ->setDescription('One rack to four.');

#[RefinesInput('racks')]
public static function fewerRacksWhenSmall(DataDefinitionInterface $racks, string $size): DataDefinitionInterface {
  // A small cellar holds two racks at most: the parent narrows its list.
}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **Items are children.** Every item is the child, refined, accepted
  and validated by it in its own frame, under its own key names. The
  child is built once for the list, invoked exactly as an attached
  child is ([Child operations](#child-operations)): the operation the
  parent's context names for the key, else the one with the parent's
  operation id, else the one of the parent's kind, otherwise the parent's operation and
  known identity. Each item's context is that one with its `delta`
  added to what it knows (`Collection::contextFor()`); delta is
  position, and nothing stores it.
- **The wall, once per item.** A parent cannot refine into an item, and
  an item cannot watch a parent key. A parent's refiner may not watch
  the list either: every item is a child, and a parent does not read
  its children's values. A refiner naming a key inside an item
  (`racks.spices`) names nothing the parent declares.
- **The list is the parent's.** Its `Count` and any other constraint on
  the list itself belong to the parent: a `#[RefinesInput]` method of
  the parent on the key may narrow them, and an operation may with
  `withConstraint()`. A refiner of the list that narrows inside its item
  is refused when the data surface is refined, naming the key. A list
  starts empty.
- **Access answers once for the list.** The child's operation access is
  asked once, since field access is per field and not per delta: open,
  locked (the list key is locked, every item with it, and the form's
  add and remove controls), or omitted (the key is not in the data
  surface). A gate the operation borrows is the exception: it is asked
  per item, about the entity the item refers to
  ([A gate borrowed from another thing](#a-gate-borrowed-from-another-thing)).
- **Storage.** The parent stores the list under its key. A child that
  names storage of its own is not routed per item yet: its items are
  still the parent's to store.

A collection is not an attached child in the engine's sense: its entry
carries a `Collection` (`Entry::$list`), not an attachment, so
`isNested()` is FALSE and the key is refined and locked as the
parent's own. A list whose every item chooses its own child,
`attachListBy()`, is not built.

## Keys that come from the site

Some keys are one per thing the site has: a checkbox per form the site
has, a select per punctuation character a module may add to. The code
cannot list them, and they are no resource of their own, so the
resource declares the map they sit in and names a provider that lists
the things:

```php
// HoneypotSettingsResource, in the honeypot port
$inputs->addEach('form_settings', 'boolean', from: ProtectedForms::class, default: FALSE)
  ->setLabel(t('Honeypot Enabled Forms'));

// ProtectedForms, a service
final class ProtectedForms implements SiteListInterface {

  public function list(): SiteList {
    $keys = (new SiteList())->addCacheTags(['config:node_type_list', 'config:comment_type_list']);
    foreach ($this->forms() as $form_id => $label) {
      $keys->add($form_id, $label);
    }
    return $keys;
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **The spelling.** `addEach($key, $type, from:, default:)` declares one
  value per thing, each as `add()` declares a key: a typed data type or a
  shape, labeled with the thing's label, starting from the thing's
  default or else `default:`. `attachEach($key, Structure::class, from:)`
  holds the structure at every thing, as `attach()` holds one. Both
  return the map at the key, for the owner to label and describe.
- **The provider** implements `SiteListInterface`, the one interface
  [variants from the site](#variants-from-the-site) name too: `list()`
  answers a `SiteList`, an id and a label per thing in order, and for
  each optionally what it starts from and whether it is locked. An id
  becomes a key, so one holding a dot or a colon is refused when the
  resource is built, and so is an entry naming a schema or a structure,
  which only a variant reads. The provider is built by the class
  resolver, a service named by its class or a class implementing
  `ContainerInjectionInterface`, so it may hold the services it reads;
  the resource only names it, and stays static.
- **Each entry** is `SiteList::add($id, $label, default:, locked:,
  schema:, structure:)`:

  ```php
  $keys->add('node', t('Content'), default: TRUE, locked: TRUE);
  $keys->add('user', t('User'));
  ```

  `id` is the key the entry becomes: not empty, no dot or colon, once
  per list. `label` is the key's label. `default` is what the key starts
  from, over the `default:` the blueprint gave every entry; `NULL`
  leaves that one. A structure at every thing (`attachEach()`) says what
  it starts from itself, so an entry there with a `default` is refused.
  `locked: TRUE` holds the key to what is stored, as "A locked thing"
  below says.
  `schema` and `structure` are read only for a variant
  ([variants from the site](#variants-from-the-site)); an entry for a
  key that names either is refused.
- **An entry the stored map leaves out**, because the site added the
  thing after the map was saved, holds its default at every door that
  draws: the form, the envelope over HTTP, the React page, and what the
  pipeline accepts and validates. A locked entry that starts checked is
  drawn checked. A save leaves the entry unstored until a caller sends
  another value, since [a default storage never held is not written](decisions.md#absent-keeps-null-clears), and the read tool,
  which answers what storage holds, leaves it out; no door reads it as
  `null` (why: [decision](decisions.md#defaults-follow-the-definitions-under-a-stored-value)).
- **When.** The build step asks the provider every time the resource is
  built and declares one key per thing in a child sealed at the key, as
  an options resolver is asked for its list. The owner's storage stores
  the map under the key. A thing the site no longer has is no key:
  nothing asks for it, and a storage that writes the map whole, as a
  config object's does, drops it on save.
- **Cacheability.** What the provider read is declared on the list, as on
  a render array: the cache tags of the configuration or the entities it
  lists (`addCacheableDependency($config)` takes them from a config
  object). It becomes the built resource's, so the form, both documents
  and the schema route are cached by it, and the tool door clears
  the tool listing when one of those tags is invalidated: a node type
  added is a checkbox at the next request, at every door. A list with
  max-age 0 is refused when the resource is built, and so is one that
  varies by the account: which keys there are is the site's, and who may
  change one is access.
- **Past the site list limit.** A map of more keys than
  `surface.settings:site_list_limit` (20 as shipped) is stated open
  in the full schema and the tool listing, marked `deferred`, its keys
  named under `x-surface.deferred.entries` by id and label; the full schema
  fetched with an entry chosen, `GET .../schema?choose=/limits=<id>`,
  states that key, the schema for these answers states every key, and
  the narrowing tool answers it to an agent
  (why: [decision](decisions.md#a-list-left-out-for-size-is-deferred-not-refined)).
  The limit only moves a long list out of the documents; a provider that
  should list fewer things reads a setting of its own, as the component
  lists read `components_offered`
  ([Variants from the site](#variants-from-the-site)).
- **A locked thing** (`add($id, $label, locked: TRUE)`) is held to what
  is stored: drawn fixed on the form, `readOnly` in both documents, locked
  in the tool listing, and a value posted for it is not taken, which the
  tool and the React submit say in a note ("Drill press is locked and
  kept as stored; the value sent was not taken."; why:
  [decision](decisions.md#locked-at-the-top)). A thing holding a
  structure is that structure locked.
- **The keys are ordinary keys.** The full schema states every one; the
  map is a def of the owner's (`resource_test.workshop.benches`), and the
  structure at the things is one def each refers to. A rule of the
  owner's may rule the whole map out, as honeypot's does while every
  form is protected, and names nothing inside it, as for an attached
  child. A rule on every thing is the structure's own, declared once:
  the test module's workshop holds a bench at every bench its
  configuration lists, and a bench out of use has no hours, at each.
  A shape's alters and a structure's apply at every thing.
- **Refused**, naming the provider or the key: a provider that implements
  no `SiteListInterface`; an entry no key can be, or one with a schema or
  a structure; a structure handed to `addEach()` or a shape
  to `attachEach()`; a list that cannot be cached or varies by the
  account; at the things, a structure that names storage or identity, or
  requires a port its owner leaves unwired, and a thing that says what a
  structure starts from; either verb from an alter or inside a structure
  mixed in.

A deriver ([below](#derived-resources)) is for what is a resource or
structure of its own: operations, storage, identity, or a use at many
sites. Keys that only one resource asks for, one per thing, are this
(why: [decision](decisions.md#keys-that-come-from-the-site)). Where each
thing chooses a group of keys of its own, a schema per thing, the
spelling is [variants from the site](#variants-from-the-site):
`attachVariants($key, by: $sibling, from: Provider::class)`.

### Ports on an entry

A structure at every thing may need a value the owner holds for that
same thing: a ticket type's limits apply only while that type is on
sale, which the type's checkbox says. The structure declares a
[port](#ports) as anywhere, and the owner wires it to a sibling map from
the same provider; the entry `<id>` reads the sibling's entry `<id>`:

```php
// TicketSalesResource, example 9
$inputs->addEach('on_sale', 'boolean', from: TicketTypes::class, default: FALSE);
$inputs->attachEach('limits', TicketLimitStructure::class, from: TicketTypes::class, reads: ['selling' => 'on_sale']);

// TicketLimitStructure
$inputs->reads('selling', 'boolean');
$inputs->narrow('per_order')->when(Condition::equals('selling', FALSE))->doesNotApply();
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **The port is read as anywhere.** The structure's declared rules and
  coded refiners read it by its name; it moves as the sibling's entry
  moves, so the form rebuilds that entry over AJAX when its checkbox
  changes, and a refusal names the sibling's entry (`Per order does not
  apply when on_sale.student is off.`).
- **What depends on it is stated per entry**, on the owner's `allOf`: an
  `if` on `on_sale.<id>` and a `then` reaching into `limits.<id>`, a
  locked entry's too ([the served contract](served-contract.md#a-port-is-stated-at-its-site)). The
  schema for these answers settles each entry, and the React page
  narrows each one from the full schema with no request.
- **Refused** when the structure does not read the port; when the
  sibling is no map of keys from the site, is the map itself, holds a
  structure at every entry, or lists its keys from another provider;
  when an entry has no match in the sibling; when the sibling's entry is
  of another type or shape than the port reads; and when a port the
  structure requires is left unwired.
- **Inside**, the map reads the sibling map through a port of its own,
  named `:<sibling>`, which no entry's id can be; no document lists it
  (`Port::$through`).

## Derived resources

A resource or structure is a class because its keys are a property of
the class. Some resources take their keys from the site instead: one per
bundle, one per field, read from field definitions and form displays
that change while the code does not. A deriver makes those, the way a
plugin deriver makes plugin derivatives.

```php
#[DerivesResources('entity.content', overriddenBy: FieldItemStructure::class)]
final class ContentEntityResources implements ResourceDeriverInterface, KnowsDerivativeListTagsInterface {

  public function derivatives(array $overrides): array {
    foreach ($this->derivation->bundles() as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle => $label) {
        $bound = [$entity_type_id, $bundle];
        $derivatives["$entity_type_id.$bundle"] = new DerivedResource(
          inputs: new ResourceCallable(self::class, 'defineInputs', $bound),
          identity: ['entity_type', 'bundle', 'id'],
          operations: [
            new DerivedOperation('create', $this->t('Add @bundle', ['@bundle' => $label]), new ResourceCallable(self::class, 'create', $bound)),
            new DerivedOperation('update', $this->t('Edit @bundle', ['@bundle' => $label]), new ResourceCallable(self::class, 'update', $bound), ['id' => 'entity:' . $entity_type_id]),
          ],
          storage: ContentEntityStorage::class,
          access: ContentEntityAccess::class,
          cacheTags: $this->derivation->bundleCacheTags($entity_type_id, $bundle),
        );
      }
    }
    return $derivatives;
  }

  public function listCacheTags(): array {
    return ['entity_field_info', 'entity_bundles', 'config:entity_form_display_list'];
  }

  public function defineInputs(BlueprintInterface $inputs, string $entity_type_id, string $bundle): void { /* ... */ }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **The deriver** is a class carrying `#[DerivesResources(base:)]` and
  implementing `ResourceDeriverInterface`, kept in `src/Resource/`. The
  compiler pass registers it as an autowired service, so it may hold
  services. The registry asks it at discovery, and caches each
  derivative as an entry of its own.
- **A derived resource** is a `DerivedResource`: everything the
  attribute and the class say, said as values. Its kind follows from
  what it names: storage or identity makes it a resource, neither a
  structure, which its parent stores (a content entity's field). Its id
  is `<base>:<derivative>`; the derivative holds no colon. Its inputs are
  a `ResourceCallable`, a class, a method and the bound arguments that
  say which derivative it is, called with the blueprint first:
  value-independent, as `defineInputs()` is, and serializable, as a
  closure is not. A static method is called on the class, an instance
  method on the class resolver's instance, so a deriver's own methods
  may read site state. Its operations are `DerivedOperation` values (id,
  label, permission, default, parameters named and typed,
  `entity:<entity type>` for an entity), each built by a
  `ResourceCallable` handed the resolved arguments by name. Its refiners
  are the static `#[RefinesInput]` methods of the classes it names,
  static or runtime as each declares. Its storage and access class are
  built by the class resolver: a service, a class implementing
  `ContainerInjectionInterface`, or one with no constructor arguments,
  as for a plugin that is its own structure.
- **It has no class.** `ResourceDefinition::$class` is NULL, and every
  caller asks for it by `name()`, its id (a class-based one's name is
  its class). The build step, the alters, the routes
  (`_surface_resource: 'entity.content:node.page'`), the catalog,
  the derived tools and read tools, the React app and its API treat it
  as they treat a class (why: [decision](decisions.md#a-derived-resource-has-no-class)).
- **Cacheability.** A derived resource names the cache tags of what it
  was read from, and only those (its bundle's form display and fields,
  not every field on the site); its discovery entry carries them, so
  saving a field clears that field's entries and no other.
  `KnowsDerivativeListTagsInterface` says what decides which
  derivatives there are (a bundle created, any form display saved),
  which the index carries; reading the list again keeps every entry
  whose tags did not fire. `ResourceRegistryInvalidator` clears what the
  registry already read in this request, and the tool door
  derives again the tools of the resources that read a tag. The tags
  belong in the build's cacheability too
  (`$inputs->addCacheableDependency()`), which is what the schema route
  and the tools are kept by
  (why: [decision](decisions.md#what-is-paid-once-and-what-per-request)).
- **Alters.** `#[Alters]` names a derived resource or structure by its
  id, or every one a deriver makes by the base id:
  `#[Alters('entity.content')]`.
- **Overrides.** `#[DerivesResources(overriddenBy:)]` names an attribute;
  the registry hands the deriver every class in an enabled module's
  `src/Resource` or `src/Structure` carrying it, as what replaces its
  derivation where someone wrote one by hand. `surface_examples_entity`'s
  `#[FieldItemStructure(type:)]` is one.

`surface_examples_entity` derives a resource per content entity bundle and
a structure per field it carries; [Content entities](entities.md) says
what is derived from what, and where core's APIs fell short.

## Not built yet

A collection whose items choose their own child (`attachListBy()`),
input shapes, and options sources with their alters are deferred; [the pattern](pattern.md#deferred) records the position each is held to.
Until options sources exist, a live list is a constraint with an
options resolver.
