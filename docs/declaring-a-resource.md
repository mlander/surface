# Declaring a resource or structure

This page is the reference for the class you write: its attribute, its
keys, and what a key can say beyond its type and label. Use it when
[Your first resource](first-resource.md) does not show the option you
need. [Resources](introduction/02-resources.md),
[Keys](introduction/05-keys.md),
[Rules on a key](introduction/06-rules-on-a-key.md) and
[Structures](introduction/03-structures.md) introduce its ideas.

A resource or structure is declared in one place: a class in a module's
`src/Resource/` or `src/Structure/` carrying `#[Resource]` or
`#[Structure]`. The attribute decides the kind, not the directory.
Discovery finds the class the way core finds one in `src/Hook`, the
build step (`surface.resources`) builds it in a context and seals
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
#[Structure('block.surface_examples_plugins')]
final class ExampleBlockStructure implements StructureInterface {

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

That is `ExampleBlockStructure` in `surface_examples_plugins`, shortened: a
structure, since the block that names it holds it and stores it. Each
part of a resource or structure has one home:

| What | Where |
| --- | --- |
| Its id, and which keys say which thing it is | `#[Resource('id', identity: [...])]`; a structure has an id and no identity, `#[Structure('id')]` |
| Its keys: type, label, default, constraints | `static defineInputs(BlueprintInterface $inputs)`, or properties carrying attributes ([Declared as properties](#declared-as-properties)) |
| What it emits | `static defineOutputs()`, on `DefinesOutputsInterface`; see [Outputs](outputs.md) |
| A key whose allowed values depend on another key's value | a rule, `$inputs->narrow('key')->when(Condition::...)`, in `defineInputs()`; a static `#[RefinesInput('key')]` method where the effect must be computed ([Declaring a rule](refinement.md#declaring-a-rule)) |
| How much is already known: add, edit, reuse | `#[Operation]` on the class, or on a static method returning an `OperationContext` when the context needs more |
| Where its values are stored | `#[Resource(storage:)]`, a `ResourceStorageInterface` in `src/Storage/`; a structure is stored by what holds it |
| What may refuse once the operation's permission allows | `access:` on either attribute, an `OperationAccessInterface` in `src/Access/` |
| Its parts | `attach()` and `attachVariants()` in `defineInputs()`, and `#[Variant]` on each variant |
| Which version of its stored values it describes | `version:` on either attribute, 1 until a breaking change is made after values are stored ([Evolving a resource](evolution.md#the-version)) |
| What it is called, and what it is for | `label:` and `description:` on either attribute, both optional ([What it is called](#what-it-is-called)) |

`FieldInstanceResource` in `surface_examples_field` uses every row but the
version, which nothing raises yet, and outputs, which the example formatter
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
- **One key, one group of lines.** A key's `add()` (or `attach()`,
  `narrow()` and the rest) with its chained setters, and a `describe()`
  of that key right after it, is one group, and one blank line separates
  each group from the next. A value several keys use (a help link, an
  arrangement group) is computed first, at the top of the method. In a
  method of many keys, a one-line `//` comment names the topic where it
  turns (`// Limits.`); no comment names a single key. The key constants
  above the method are one line each, in one block, with no docblock.
- **`add()` takes name, type and label** and returns the core
  definition, so the rest is plain core API: `setRequired()`,
  `setDescription()`, `addConstraint()`, `setSetting()`. The label is
  the one every door shows: the form element's title, the tool's
  property label, the schema's `title`; the description is the text
  under the element and the property's description.
  `addList()` is the same for a list of values, `addMap()` for a map,
  and `addDefinition()` the long form, for one value built by hand
  ([Maps and lists](#maps-and-lists)). The label is
  `t('...')` with a literal string; see [Translatable
  strings](#translatable-strings). The type may be a shape's class, a
  value declared once for every key that asks for it ([Named
  shapes](#named-shapes)).
- **A `#[RefinesInput]` method takes the key's definition first, then one
  parameter per sibling it watches**, matched by name: a parameter that
  names no declared key is refused, so a renamed one never silently
  stops watching. Its signature is its dependency declaration.
  One rule per method, its docblock stating the rule.
- **The class has no constructor and holds no service**, and is never
  instantiated: the build step calls its static methods on the class,
  and what rides along in a cached form is the class name. Storage
  classes, access classes and alters are autowired services and may hold
  services; an alter's refiners are static methods too, unless they
  declare `runtime: TRUE`, the one kind an instance method may be.

### The types a key takes

A key holds one value of a type the contract states a JSON Schema type
for. `add()` takes one of eight, or a shape or a config schema type that
is one of them:

| Type | JSON Schema |
| --- | --- |
| `string` | `type: string`; `format: date` where the key asks for the date widget |
| `email` | `type: string`, `format: idn-email` |
| `uri` | `type: string`, `format: uri` |
| `datetime_iso8601` | `type: string`, `format: date-time` |
| `integer`, `timestamp` | `type: integer` |
| `float` | `type: number` |
| `boolean` | `type: boolean` |

A shape's class is the scalar its `define()` returns; a config schema
type such as `machine_name`, `label`, `path`, `color_hex` or `langcode`
is the string underneath with the type's constraints. Any other type is
refused when the blueprint is filled, naming the method for what was
meant: `attach()` for a group of keys (or `addMap()` for keys only this
one holds), `addList()` for a list, the id's type and a constraint for
an entity's id, `addDefinition()` for one value built by hand.
`addDefinition()` takes one value of a type in the table, and refuses a
map or a list, naming `addMap()`, `attach()` or `addList()`; a list's
item is held to the table at every depth. So the contract states a type
for every value the runtime accepts, and is never vaguer than it
([decision](decisions.md#one-table-of-leaf-types-read-by-the-blueprint-the-emitter-and-the-reader)).

One table, `Contract\LeafTypes`, holds the rows: the blueprint refuses
by it, the emitter writes each row, and the schema reader reads a JSON
type and format back into the row's type. How each type is drawn and
coerced is keyed by the type where it is done: each widget's
`isApplicable()` and `Pipeline\Coercion`.

A key that refers to an entity is one of these, the id as its entity
type keeps it, with the `SurfaceValidReference` constraint naming the
entity type and the bundles it may be of
([Options](options.md#a-key-that-refers-to-an-entity)):

```php
$inputs->add('venue', 'string', t('Venue'), default: 'riverside')
  ->addConstraint('SurfaceValidReference', ['entityTypeId' => 'event_venue']);
$inputs->add('page', 'integer', t('Announcement'))
  ->addConstraint('SurfaceValidReference', ['entityTypeId' => 'node', 'bundles' => ['article']]);
```

A config entity keeps its id as a string, a content entity whose id
field is an integer as an integer. The build checks the type against
the entity type's id and refuses another, naming the right one. A
reference to an entity of any type is a structure of two keys, an
entity type and an id, which example 16 declares and attaches
([decision](decisions.md#a-reference-is-a-constraint-on-a-value)).

### What declares an object

A key holds one value. An object is declared only by a method that is
one JSON Schema construct:

| Method | JSON Schema |
| --- | --- |
| `attach()`, `attachList()` | a `$ref` to the structure's def; an array of them |
| `addMap()` | an inline `type: object` with its properties |
| `mixIn()` | `allOf` of the structure's def and the owner's properties |
| `attachVariants()` | `allOf` of `if`/`then`, one per value of the deciding key |
| `addEach()`, `attachEach()` | an object with one property per thing the site has |
| `addList()` | `type: array` of one value's items |

`addDefinition()` takes one value, never a map or a list
([decision](decisions.md#a-blueprint-takes-no-complex-data-types)).

### What it is called

The id is for code: the tool id, the HTTP path, the key under `$defs`.
`label:` says what the resource or structure is called and
`description:` what it is for, each written as
`new TranslatableMarkup(...)`, for a person or an agent who would
otherwise have only the id:

```php
#[Structure('registration.step3.ticket.paid',
  label: new TranslatableMarkup('Paid ticket'),
  description: new TranslatableMarkup('The ticket when registration costs money: its price and currency.'),
)]
#[Variant(of: RegistrationStep3Resource::class, at: 'ticket', for: 'paid', label: new TranslatableMarkup('Paid'))]
final class PaidTicketStructure implements StructureInterface {
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Both are optional. Where they are said:

- **The served contract.** The def of a structure or resource is titled
  by its label and described by its description; one with no label is
  titled by its id. The key under `$defs` is the id either way.
- **The catalog.** `drush surface:catalog`, its page and
  [the catalog](catalog.md) print both beside the id.
- **Variants.** A deciding key offers each value by its `#[Variant]`'s
  `label:`, the choice's word, else by the structure's label. The paid
  ticket is offered as "Paid" and its def is titled "Paid ticket"; a
  `#[Variant]` label that would only repeat the structure's is left
  out.
- **The contract panel** names a part by its label, its id after it.

Two labels are not the same thing. The label `attach()` or
`attachVariants()` gives the key, `->setLabel(t('Ticket'))`, is the
key's: the form element's title, the property's `title` beside the
`$ref`. The structure's own label is what the structure is wherever it
is held, the def's `title`. A structure that one plugin holds takes the
plugin's label and description unless it says its own
([On a plugin](#on-a-plugin)).

### Named shapes

A value asked for at more than one key, with the same type, the same
pattern and the same words for it, is declared once as a **shape**: a
class in the module's `src/Shape/` carrying `#[Shape]`, whose static
`define()` returns a plain core definition. The examples' event license:

```php
#[Shape('event.license')]
final class License implements ShapeInterface {

  public static function define(): DataDefinition {
    $pattern = ['pattern' => '/^\d{4}$/D', 'message' => t('A license number is four digits, such as 2048.')];
    $license = DataDefinition::create('string')
      ->setLabel(t('Event license'))
      ->setDescription(t('The four digits after EV- on the license, such as 2048. Required to host more than 100 people.'))
      ->addConstraint('Regex', $pattern);
    DefinitionMetadata::setExamples($license, ['2048']);
    return $license;
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

A constraint's message is a sentence a person reads, so a translator
should find it beside every label: a message option may be written with
`t()`, as the license's is; the build hands Symfony its source string,
which core's validator translates when it refuses a value ([Violation messages are objects](semantics.md#violation-messages-are-objects)).

A `Regex` reaches both JSON Schema documents as `pattern` only when it
stays inside the subset PHP and a JavaScript engine read alike: no
negated class, `\d` `\w` `\s` `\b` only without the `u` modifier, and
`$` only with the `D` modifier, which the license's pattern carries
([The served contract](served-contract.md#never-stricter-than-the-runtime)).
Any other pattern is checked on the server only and named under
`x-surface.checkedOnServer`: an email shape written
`/^[^@\s]+@example\.org$/D` is, for its negated class, while
`/^[a-z0-9._-]+@example\.org$/D` reaches both documents, and so does
`/^\d{4}$/D`, where `/^\d{4}$/uD` does not.

A key uses it in the type position of `add()`, with a label of its own:

```php
$inputs->add('license', License::class, t('Event license'));
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
  `attachList()` or `mixIn()` is refused.
- **A port may read one.** `$inputs->reads('code', Sku::class)` reads a
  value of the shape's type, and a container may wire it only to a key
  declared with that shape ([Ports](resources.md#ports)).
- **An alter of the shape tightens it at every key.** An alter naming
  the shape, `#[Alters(License::class)]`, is handed the shape itself in
  `alterInputs()`, named by its id: `narrow('event.license')` with no
  condition and its bounds, a list's subset or a pattern where it has
  none, and `describe('event.license', ...)`. It mounts no key and
  attaches nothing.
- **The contract writes it once.** Each shape is one def in both
  documents, `$defs.event.license`, and each key using it refers to it,
  saying beside the reference only what the key says differently
  ([The served contract](served-contract.md#a-shape-is-one-def)).

The test module's stock code, `Shape\Sku`, is used at two keys of the
depot that narrow it differently, read by the shelf tag's port, and
tightened by `Alter\SkuAlter`. A shape is a named definition, not a
data type plugin: a module writes no plugin for it
(why: [decision](decisions.md#a-shape-is-a-named-definition)).

### Nothing live in the blueprint

A list of allowed values that depends on the site is a constraint whose
options resolver fetches it, never a list the class assembles. "Live"
is decided by runtime dependence, not by structure: `PluginExists`,
whose values are resolved from a plugin manager, is still written down
as a literal constraint, and so is `EntityBundleExists`, handed the
entity type by a refiner. A refiner points; the resolver fetches. Look
for a stock constraint or a reference key (`SurfaceValidReference`) first; only when none names the list does a module add one with its
resolver, as the example does with `SurfaceExamplesBundleField`; see
[Which constraint to write](options.md#which-constraint-to-write).

A blueprint whose keys do depend on site state (a key offered only while a
module is installed, a label read from configuration) says so with
`BlueprintInterface::addCacheableDependency()`, so a form rendered from it
is cached no longer than those keys hold.

**What `defineInputs()` may read.** It is static and holds no service,
and the build is kept until a tag it carries is invalidated, so it reads
what the site has, never who asks or how: the module list, configuration
and the URLs of routes. It reads nothing of the request, the route being
served, the session, the theme or the account
([No door-specific context](resources.md#no-door-specific-context)).
Declare each read:

| Read | Declare |
| --- | --- |
| Whether a module is installed | the cache tag `config:core.extension` |
| A config object | the object, `addCacheableDependency($config)` |
| A URL | the `GeneratedUrl` that `toString(TRUE)` answers |

`config:core.extension` is the tag the build puts on every build it
keeps, so the build itself is rebuilt when a module is installed or
removed; declared on the blueprint, it reaches the form and both
documents too, which are cached by what the built resource declares. A
description that links to a help page only while the help module is on:

```php
$inputs->addCacheableDependency((new CacheableMetadata())->addCacheTags(['config:core.extension']));

$description = t('Maximum length of aliases to generate.');
if (\Drupal::moduleHandler()->moduleExists('help')) {
  $url = Url::fromRoute('help.page', ['name' => 'pathauto'])->toString(TRUE);
  $inputs->addCacheableDependency($url);
  $description = t('Maximum length of aliases to generate. See <a href=":help">Pathauto help</a> for details.', [':help' => $url->getGeneratedUrl()]);
}
$inputs->add('max_length', 'integer', t('Maximum alias length'), default: 100)
  ->setDescription($description);
```

## Declared as properties

A class may declare its keys as properties instead: each key a property
carrying the attribute of the blueprint verb it stands for, and the class
using `DeclaresByProperties`, which supplies `defineInputs()` and
`defineOutputs()`. The build calls `defineInputs()` as on any class; the
trait reads the properties in the order they are written and calls the
same verbs the method would. It is a second spelling of the blueprint,
never a second contract: what a verb refuses, it refuses, and the
contract is the one the calls give. Example 18's caterer, shortened:

```php
#[Resource('event.catering', storage: CateringStorage::class)]
#[Group('details', 'Details')]
final class CateringResource implements ResourceInterface {

  use DeclaresByProperties;

  #[Input('Show the caterer on the event page')]
  public bool $public = FALSE;

  #[Input('Headcount', description: 'How many people to cater for.')]
  #[Constraint('Range', ['min' => 1, 'max' => 5000])]
  public int $headcount;

  #[Input('Budget')]
  #[Constraint('Range', ['min' => 0])]
  #[Describe(group: 'details', suffix: 'EUR')]
  public ?float $budget = NULL;

  #[Input('Served at', description: 'HH:MM, on a 24-hour clock.')]
  #[Widget('time')]
  #[Rule(whenEquals: ['public', TRUE], require: TRUE, because: 'Say when food is served while the caterer is shown.')]
  public ?string $served_at = NULL;

  #[Attach(label: 'Caterer contact', reads: ['listed' => 'public'])]
  public PersonStructure $contact;

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces); the
attributes are in `Drupal\surface\Resource\Attribute`.

PHP says the key's name (the property's), its type (`string`, `int`,
`float`, `bool` or a shape's class), its default (the initializer) and
whether it must be given. The attributes say the rest:

| With calls | As a property |
| --- | --- |
| `add('capacity', 'integer', t('Capacity'))` | `#[Input('Capacity')] public int $capacity;` |
| `->setRequired(TRUE)` | a type that is not nullable, with no initializer; `required: TRUE` where it also has a default |
| `default: 0` | the initializer, `public int $weight = 0;` |
| no default, not required | `public ?string $address = NULL;` |
| `'email'`, `'machine_name'`, any type the property cannot say | `#[Input('Email', type: 'email')]` |
| a shape | the property typed with the shape's class, `public ?PhoneNumber $phone = NULL;` |
| `->setDescription(t(...))` | `description:` on the attribute |
| `->addConstraint('Length', [...])` | `#[Constraint('Length', [...])]`, repeatable, in the order written |
| `->setSetting('widget', 'time')` | `#[Widget('time')]`; any other setting `#[Setting('multiline', TRUE)]` |
| `DefinitionMetadata::setExamples()` | `#[Examples('Harbour Kitchen')]` |
| `describe('key', group:, weight:, placeholder:, prefix:, suffix:, help:)` | `#[Describe(...)]`, `group:` naming a `#[Group]` on the class |
| `ArrangementGroup::fieldset()`, `::details()` | `#[Group('id', 'Label')]`, `details: TRUE` for a details element |
| `addList('days', 'string', ...)` | `#[Input('Days', list: 'string')] public array $days = [];`, an item constraint `#[Constraint(..., item: TRUE)]` |
| `addList()` of maps | `list:` naming a plain class of `#[Input]` properties |
| `addMap('coordinates', ..., fn)` | `#[Map('Coordinates')] public Coordinates $coordinates;`, a plain class of `#[Input]` properties |
| `attach('contact', PersonStructure::class, reads: [...])` | `#[Attach(reads: [...])] public PersonStructure $contact;`, or the class on the attribute |
| `attachList('rooms', VenueRoomStructure::class, max: 20)` | `#[AttachList(VenueRoomStructure::class, max: 20)] public array $rooms;` |
| `attachVariants('ticket', by: 'pricing')` | `#[Variants(by: 'pricing')] public array $ticket;`; each variant still carries `#[Variant(of:, at:)]` |
| `addEach()`, `attachEach()` | `#[Each(from:, type:)]`, `#[AttachEach(Structure::class, from:)]` on an array property |
| `reads('listed', 'boolean')` | `#[Port] public bool $listed;`, `type: 'any'` for any type |
| `compute('slug', ..., from:, using:, when:)` | `#[Computed('Slug', from:, using:, when:)] public string $slug;` |
| `narrow('items')->when(Condition::equals('frequency', 'weekly'))->constrain(...)` | `#[Rule(whenEquals: ['frequency', 'weekly'], constrain: ['Range', ['max' => 5]])]` on `$items` |
| `mixIn(ReviewStructure::class, reads: [...])` | `#[MixIn(ReviewStructure::class, reads: [...])]` on the class |
| `defineOutputs()` | `#[Output(...)]`, which says what `#[Input]` says, on the class implementing `DefinesOutputsInterface` |

`#[RefinesInput]` methods, `#[Operation]`, `#[Resource]`, `#[Structure]`,
`#[Variant]`, alters, access and storage are as on any class.

- **Required.** `required:` when given; else a property whose type is
  not nullable and that has no initializer is required. The initializer
  is the default, and a nullable property with `= NULL` has no default. A
  key that must be sent and starts from a default says both:
  `#[Input('Frequency', required: TRUE)] public string $frequency = 'weekly';`.
  A computed key is never required by what its property says.
- **Texts.** An attribute argument cannot call `t()`, so the texts the
  reader names (a label, a description, a placeholder, a prefix, a
  suffix, a help text, a group's title, a rule's reason) are written as
  bare strings, which it wraps in `TranslatableMarkup`. A text inside a
  constraint's options (a message, a choice's label) is handed to core
  as it is, and is written `new TranslatableMarkup('...')`. The
  trade-off: a bare string is translated where a translation exists, but
  string extraction does not find it; `new TranslatableMarkup('...')` is
  found.
- **Order.** The properties, in the order written, each one's
  `#[Describe]` right after it; then each `#[MixIn]`, its members after
  every property; then each `#[Rule]`, in the order of the properties
  carrying them. A class that mixes in before its own keys keeps
  `defineInputs()`.
- **What stays a method.** What an attribute argument cannot hold: a list
  computed by a call (`Venues::rooms()`), a key declared in a loop, a
  condition `#[Rule]` does not spell (it spells `whenEquals:`,
  `whenPresent:`, `whenAbsent:` and `whenIn:`, all required to hold when
  several are given; `not`, `anyOf` and a bound stay `narrow()` or a
  `#[RefinesInput]` method). `addDefinition()`, `resolve()`,
  `extendChoices()` and `addCacheableDependency()` have no attribute: the
  long form has none by design, a resolve and a widening are an alter's,
  and a cacheable dependency is a derived resource's, which has no class.
- **One spelling per class.** A class declares its keys with
  `defineInputs()` or with properties, never both; a derived resource,
  which has no class, keeps its callables.
- **What it refuses.** Beside what the verbs refuse: a public property
  with no attribute saying what it is, a `#[Describe(group:)]` naming no
  `#[Group]`, a property type that is no key type and no shape with no
  `type:`, an `#[Attach]` or `#[Map]` naming no class, an item constraint
  on a key that is no list, and an `#[Output]` on a class that does not
  implement `DefinesOutputsInterface`. Each message names the class, the
  property and the fix. A static or protected property with no attribute
  is left alone, so a plugin that is its own structure keeps its own.
- **The reader reads no value.** Only types, initializers and attributes,
  so reading a class twice calls the same verbs, which `addMap()` needs
  when it runs the declaration again to fill a map that needs a frame.
- **Coding standard.** Drupal's asks a docblock on every property and a
  lowerCamel name. On a key declared as a property the attribute is its
  documentation and the name is the key's, snake_case as every key is,
  so a module leaves `Drupal.Commenting.VariableComment.Missing` and
  `Drupal.NamingConventions.ValidVariableName.LowerCamelName` off for
  those classes, as this module's `phpcs.xml.dist` does.

Example 18 declares the same keys both ways, over one config object:
`CateringByMethodResource` with calls at `/surface-examples/18/method`,
and `CateringResource` as properties at `/surface-examples/18`, so the
two can be read side by side
([the example](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-18-declared-as-properties)).

`tests/modules/surface_property_test` declares every attribute twice,
once with calls and once as properties, and `PropertyDeclarationTest`
holds the two to the same documents
([decision](decisions.md#a-class-may-declare-its-keys-as-properties)).

## On a plugin

A plugin keeps rendering and names the structure its configuration is,
a class of its own:

```php
#[Block(
  id: 'surface_examples_plugins',
  admin_label: new TranslatableMarkup('Surface API example'),
)]
#[UsesStructure(ExampleBlockStructure::class)]
final class ExampleBlock extends TypedBlockBase {

  public function build(): array {
    $configuration = $this->getConfiguration();
    // ...
  }

}
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Nothing else is needed. The block has no `defaultConfiguration()`, no
`blockForm()`, no `blockValidate()` and no `blockSubmit()`:
`TypedBlockBase` builds the structure the definition names, in the
host's context, and stores what the pipeline accepts in the block's
configuration. The same holds for a condition (`TypedConditionPluginBase`,
writing `evaluate()` and `summary()`), an action (`TypedConfigurableActionBase`,
writing `execute()` and `access()`) and a formatter
(`TypedFormatterBase`, writing `formatValue()`). Any configurable
plugin with no base class of this module's lists `SurfacePluginForm`
under its `forms` key. [Generated forms](forms.md) has the table.

Or the plugin is its own structure: `#[UsesStructure]` with no
argument, and the plugin class implements `StructureInterface` with the
static methods a structure class has, beside the plugin's own code:

```php
#[FieldFormatter(
  id: 'surface_examples_plugins_string',
  label: new TranslatableMarkup('Surface API example formatter'),
  field_types: ['string'],
)]
#[UsesStructure]
final class ExampleFormatter extends TypedFormatterBase implements StructureInterface, DefinesOutputsInterface {

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
id is `<host type>:<plugin id>`, `field_formatter:surface_examples_plugins_string`,
unless the class also carries `#[Structure]`; the catalog lists it as
used by that same plugin; an alter names it by the plugin class. The
example module keeps both spellings side by side: its block names
`ExampleBlockStructure`, its formatter is its own. A separate class reads
better for a large structure, one with children or operations, or one
more than one plugin shares; the plugin's own reads better for a small
plugin whose settings are only ever its own, because the settings sit
beside the code that reads them.

A field type implements `FieldSurfaceProviderInterface` and uses
`SurfaceFieldTypeTrait`. Its static defaults are one line, because
`defaultFieldSettings()` is asked of the class:

```php
#[FieldType(id: 'surface_gated', /* ... */)]
#[UsesStructure(GatedFieldSettingsStructure::class)]
class SurfaceGatedItem extends StringItem implements FieldSurfaceProviderInterface {

  use SurfaceFieldTypeTrait;

  public static function defaultFieldSettings(): array {
    return static::surfaceDefaultFieldSettings(static::class) + parent::defaultFieldSettings();
  }

}
```

A field type whose storage layout differs from how a caller is asked
for its settings overrides `getSurfaceStorage()` and hands
`FieldSettingsStorage` a layout, as `SurfaceAddressItem` does.

What a host supplies, and why the structure does not:

- **The context.** A plugin instance is the whole of what it describes,
  so the host builds in `new OperationContext('configure')` (or
  `field_settings` for a field type): its own verb, which is no declared
  operation, and nothing known.
- **The storage.** Only the host holds the instance: the plugin's
  configuration array, or the field config Field UI is editing. A
  formatter's settings are stored by the display, so its
  `getSurfaceStorage()` throws.
- **Static defaults.** `defaultSettings()` and `defaultFieldSettings()`
  read `#[UsesStructure]` off the class and take
  `ResourcesInterface::defaults()`: the structure's own declaration alone,
  with no alter, context or refiner.

`#[UsesStructure]` is copied into the plugin definition, so the catalog
lists which plugins use a structure without instantiating one, and
whatever a plugin uses is never generated as a tool of its own.

A structure a plugin holds says nothing the plugin already says: with
no `label:` of its own, it is called what the plugin definition calls
the plugin, a block's `admin_label` and any other plugin's `label`, and,
with no `description:` either, it takes the definition's `description`
where it has one. `ExampleBlockStructure` is "Surface API example" in
the catalog and as a def, read from `ExampleBlock`'s attribute, and the
formatter that is its own structure is "Surface API example
formatter". Only where one plugin alone holds the structure: two
plugins sharing one would each call it something else, so it is named
by its id. Resolved at discovery, from the plugin definitions
(`ResourcePlugins::hostNames()`); nothing is copied into the structure
class.

## Operations and identity

A context's **id** is either an operation id (`create`, `update`, `reuse`)
or a host's own verb (`configure`, `field_settings`). It **never
carries identity**. An id that names the thing it acts on is a
vocabulary nobody can enumerate, and a discovery document cannot
list it.

Identity is the keys `#[Resource(identity:)]` names, and a context says
which of them it knows. An identity key the context knows is locked to
that value; one it does not know stays open. That is the whole
difference between add and edit, and it is why an operation, a route and
a tool address a resource the same way: by its id and an operation id,
`surface:node.type:update`, with the operation's parameters saying
which content type.

A parameter is identity the caller already knows before the call; the
body is what the call decides. So an operation takes identity as
parameters named for the identity keys, never as a composite id the
caller has to assemble, and every door names each key the same way: the
route, the OpenAPI document, the tool and the React page. An add names
the parent it adds to and leaves the keys it chooses open in the body;
an edit names the whole thing. The field instance, identity `entity_type`,
`bundle`, `field_name`, `field_type`, the names core's field config
stores them under:

```php
public static function create(string $entity_type, string $bundle): OperationContext
public static function update(string $entity_type, string $bundle, string $field_name): OperationContext
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Its create is `POST /surface-api/field.instance/node/article`, the field's
machine name and type in the body; its edit is `PATCH
/surface-api/field.instance/node/article/body`, the field type following
from the field. A parameter named for an identity key is a path segment,
in identity order; any other is in the query string
([decision](decisions.md#a-parameter-is-identity-the-caller-knows)).

A derived resource's operation names its parameters, `['id']`: each
the name of a key the resource declares, which takes that key's
definition and no type of its own. The operation receives the key's
value; the storage loads what it names
([Derived resources](resources.md#derived-resources),
[decision](decisions.md#a-parameter-names-a-key)).

## Names

Four naming rules, so that a reader who has only a grep finds the rest
of the story.

**A resource class ends in `Resource`, a structure class in
`Structure`, and the id is dotted.** `NodeTypeResource` is `node.type`,
`FieldInstanceResource` is `field.instance`, `ExampleBlockStructure` is
`block.surface_examples_plugins`. A plugin that is its own structure keeps its
plugin's class name, and its id is its plugin's,
`<host type>:<plugin id>`, unless it carries `#[Structure]`. An alter
ends in `Alter`, a storage class in `Storage`, an access class in
`Access`, and a deriver is named for what it makes, in the plural
(`ContentEntityResources`, `FieldItemStructures`). A shape class is
named for the value, with no suffix, as a data type is (`License` is
`event.license`).

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

**The catalog names a plugin `<host type>:<plugin id>`**:
`block:surface_examples_plugins`, `field_formatter:surface_examples_plugins_string`,
`field_type:address`. The host type keeps two plugins of different
kinds that share an id apart.

**An operation is a verb.** See above.

### Key names

A key is a string wherever it is named, `add('venue', ...)`,
`narrow('room')`, `Condition::equals('venue', ...)`, and an editor can
neither rename a string nor find its uses. So the class names each key
once, as a constant, in declaration order, one line each in one block
with no docblock (the label on the `add()` line says what the key is),
and every later mention is the constant:

```php
public const VENUE = 'venue';
public const ROOM = 'room';
public const CAPACITY = 'capacity';

// ...
$inputs->add(self::ROOM, 'string', t('Room'));
$inputs->narrow(self::CAPACITY)->when(Condition::present(self::ROOM))->require();
#[RefinesInput(self::CAPACITY)]
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

A refiner's parameter is the one exception: `string $room` watches the
key whose name matches the parameter's, so it is spelled out and must
match the constant's value; a rename changes both. The generator writes
this convention ([Key names](generating.md#key-names)), and the first
two pages and examples 1 and 2 follow it; the later examples name keys
as strings, which works the same
(why: [decision](decisions.md#a-key-is-named-once-as-a-constant)).

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
call is not allowed in an attribute argument, so `#[Operation(label:,
description:)]` and a plugin attribute's label are the one place the
constructor stays.

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

So the split is by context, and can fall inside one file: the example
formatter's static `defineInputs()` calls `t()`, its plugin attribute
constructs `new TranslatableMarkup`, and `ExampleFormatterAlter`, an alter
of it, calls `$this->t()` in `alterInputs()`; the compliance alter of
example 3 calls `$this->t()` there and `t()` in its static refiners. `SurfaceBuilder`, the engine under the
build step, is a value object made with `new`, so its one labeling
method is static and calls `t()`.

## Maps and lists

A map is `addMap()`, a list `addList()`; `addDefinition()` takes one
value and refuses either. Keys built by hand go into the map's callable
one by one, each with `addDefinition()`:

```php
$inputs->addMap('field_overrides', t('Field overrides'), static function (BlueprintInterface $overrides): void {
  foreach (self::fieldOverrideDefinitions() as $field_name => $definition) {
    $overrides->addDefinition($field_name, $definition);
  }
});
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

`AddressFieldSettingsResource` is the worked example. A map whose
properties are another class's keys is a child instead: `attach()` or
`attachVariants()`.

### An anonymous map

A map whose keys only this resource holds is `addMap()`: a key, a
label, and a callable handed a blueprint for the map's keys. Example 8's
desk says where it stands:

<!-- excerpt: modules/surface_examples/src/Resource/InfoDeskResource.php -->

```php
$inputs->addMap('location', t('Where the desk stands'), static function (BlueprintInterface $location): void {
  $location->add('lat', 'float', t('Latitude'), default: 43.6532)
    ->addConstraint('Range', ['min' => -90, 'max' => 90]);
  $location->add('lng', 'float', t('Longitude'), default: -79.3832)
    ->addConstraint('Range', ['min' => -180, 'max' => 180]);
});
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Each document writes it inline, `type: object` with its properties, and
no def; the form draws it as a fieldset, the tool takes it as a map in
`values`, and storage holds it under its key. What its keys start from
is the map's default.

Every blueprint method works inside the callable: `add()`, `addList()`,
`addDefinition()`, a nested `addMap()`, `describe()`, and `attach()`,
`attachList()`, `attachVariants()`, `mixIn()`, `narrow()` and
`resolve()`. A map whose keys need a frame of their own (a child, a
rule, a resolve) is a structure with no id, attached at the key and
still written inline, the structures it holds as references to their
defs. The callable may run more than once: at once, and again each
time that structure is built, when the build runs the owner's
declaration again to fill it. So it has no side effect and declares the
same keys each time, as `defineInputs()` does; the build refuses a
callable that declares other keys the second time, comparing the keys it
declared before it asked for the frame.

The cost of no id: no alter addresses the map itself, nothing else
uses it, and its access is its owner's. Anything two resources share,
or anything an alter should reach, is a structure, `attach()`ed. An
alter's map, and an output, hold plain keys alone: an alter attaches a
structure at its own mount, and outputs hold no attached structures
([decision](decisions.md#an-anonymous-map)).

A list of values is `addList()`, as a single value is `add()`: a key,
an item, a label and a default, the item a type name, a shape's class or
a definition of its own. It returns the list, described fluently
afterwards with the core setters:

```php
$inputs->addList('keywords', 'string', t('Keywords'), default: []);
$inputs->addList('days', $day, t('Days open'))
  ->setRequired(TRUE);
```

The blueprint builds the list, so a resource or structure class never
writes core's constructor, and never calls `ListDataDefinition::create()`,
which asks the typed data manager for the item. A list of structures is
a collection, `attachList()`
([Collections](resources.md#collections)).

## Defaults and examples

Core's data definitions carry neither a default value nor examples yet.
Both are written onto the definition here, under the definition array
keys the core draft proposes, through `DefinitionMetadata`:

| What | Definition array key | Written with |
| --- | --- | --- |
| Default value | `default_value` | the `default:` argument of `add()`, `addList()` and `addDefinition()`, or `DefinitionMetadata::setDefaultValue()` |
| Examples | `examples` | `DefinitionMetadata::setExamples()` |

Each accessor delegates to the definition's own core method when one
exists, so the class becomes a pass-through the day core lands its
version. Read them back with `hasDefaultValue()`, `getDefaultValue()`,
`defaultOf()` (which assembles a complex definition's default from its
property definitions) and `getExamples()`.

Passed to `add()`, `addList()` or `addDefinition()`, `NULL` declares no default,
which is what core definitions already say. Written with
`DefinitionMetadata::setDefaultValue()`, `NULL` is a declared default
and is distinct from declaring none. What consumers do with a declared
one: the data surface's `getDefault()` and
`getDefaultValues()` read it, and `accept()` merges it under the stored
values; an example is stated by both documents and drawn as no
placeholder, which is the key's declared
[arrangement](forms.md#arranging-the-keys).

Two consequences worth knowing before you declare one. A map's declared
default merges *over* the defaults its properties declare rather than
replacing them, and a list key absent from a payload keeps the stored
list rather than falling back to its default. Both rules, and why, are in
[Value semantics](semantics.md).

### A site's own defaults

A declared default is the module's. A site that wants a key to start
from another value sets it in configuration, `surface.defaults`,
not in an alter: an alter may not change an owner's default. Each entry
names the resource (or a plugin's structure, by the id the catalog
lists), a key path from it, and the value as a form posts it:

```
drush config:set surface.defaults defaults.0 \
  '{resource: registration.step3, key: capacity, value: "40"}' --input-format=yaml
drush config:set surface.defaults defaults.1 \
  '{resource: registration.step3, key: contact.phone, value: "+1 555 0100"}' --input-format=yaml
drush config:delete surface.defaults defaults.1
```

The value is text. The build reads it through [the coercion table](semantics.md#the-coercion-table), as a form's values are read:
`40` is an integer for an integer key, `true` or `false` a boolean, a
JSON list `["a","b"]` a list, a JSON object a map, and an empty value
none. Quote it in YAML, so `false` stays the word and not a boolean
that config stores as the empty string. A path names a key the resource
declares, a key inside a map it declares or a structure it attaches
(`contact.phone`), or a key an alter mounts
(`third_party_settings.<module>.<key>`).

The build writes the site's value over the declared default of what it
builds, so the form, both documents and the tool listing of a create
start from it. A key that holds a stored value keeps it: a default is
what a key with nothing stored starts from, and the read tool answers
what is stored. The declaration a config schema and an install file are
written from keeps the module's, so `drush surface:config-schema
--write` never writes a site's value into a module, and so do a
plugin's static defaults (`defaultConfiguration()`, `defaultSettings()`),
which its host asks of the class with no build.

An entry the resource cannot honor refuses the build, naming the key,
and the catalog lists the refusal:

- a path that names no key: "surface.defaults: registration.step3
  has no key "contact.fax". ...";
- a value the key does not accept, in the key's own rule: "surface.defaults
  sets "venue" on registration.step3 to "nowhere", which the key does
  not accept: The value you selected is not a valid choice.";
- a key inside variants, a collection or a map of keys from the site,
  each starting from its own defaults; a whole attached structure (set
  each of its keys); a secret; and a computed key.

Every build carries the config object's cache tag,
`config:surface.defaults`, so a change reaches every door at the
next request (why: [decision](decisions.md#a-site-sets-a-default-in-configuration)).

One page can start a key from a value of its own: a route's
`_surface_defaults`, an operation context's `withDefaults()`, is
written over the site's for that page alone, with the same reach and the
same refusals ([A route that fixes or fills keys](forms.md#a-route-that-fixes-or-fills-keys)).
A value a link sends for a key its route lists under
`_surface_query`, or one the code building the form hands it, is
no default: it is what the element starts from on that page's first
draw, and no document states it
([Keys a link or the code starts](forms.md#keys-a-link-or-the-code-starts)).

## Locking

A locked key's value is fixed. It is the degenerate refinement: the
value space narrowed to exactly one value. A class never locks a key
itself; an identity key the context knows is locked, to the value
the context knows, and stays locked whatever is submitted for it.

A locked key stays advertised. It renders disabled in a generated form,
and submitted input for it is ignored: not an error, just not a way to
move the value. `SurfaceInterface::isLocked()` answers it.

Locking is context rather than an intrinsic property of a definition,
which is why it lives on the data surface rather than on the
definition: the same machine name key is locked in the update operation
and open in the create operation, and one class declares both.

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
| The storage layout | `EncryptedStorageLayout` encrypts the value on its way to storage and decrypts it on the way back. See [Storage](storage.md#secrets-at-the-codec). |
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
| locked | `readOnly: true`, with `const` where it holds a value |

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

## Computed keys

A key whose value follows other keys, and that nobody types, is declared
with `compute()`: typed and labeled as `add()` declares a key, and
computed by a class from the keys `from:` names. Example 15's venue
computes its slug from its name:

```php
$inputs->compute('slug', 'string', t('Slug'), from: ['label'], using: Slugify::class)
  ->addConstraint('Regex', ['pattern' => '/^[a-z0-9]+(-[a-z0-9]+)*$/D']);
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

The class implements `ComputesValueInterface`, whose one method is static,
`compute(array $from, ?DataDefinitionInterface $key = NULL): mixed`:
handed the value of each key `from:` names, and the key's own
definition, it answers the value. It is a pure function of those
values and of what the key declares, held to it as a static refiner is:
with assertions on it runs with the container guarded
(`PipelineGuard::guardCompute()`), and the module's PHPStan rule flags a
`\Drupal::` call in it. A value that needs a service, the clock or the
request, such as a changed time, is no computed key: a storage's
`prepare()` adds it. The one exception is the stock `MachineName`
below, which may ask for core's `transliteration` service.

- **When.** `when: Compute::ON_CREATE`, the default, computes it while
  storage holds nothing for it, which is when the thing is created, and
  keeps it after: renaming a venue leaves its slug, and every link to
  it, as it was. `when: Compute::ON_CHANGE` computes it again on every
  write that changes a key in `from`. A write that changes none of them
  keeps what is stored either way. `when: Compute::UNLESS_SENT`
  computes it as `ON_CREATE` does only when a call leaves it out, or
  sends it null or empty, and keeps a value sent: the machine name below.
- **Judged like any key.** The pipeline computes it once the other keys
  are accepted, then validates it by its own constraints and rules, and
  storage writes it. A refusal of it names the keys it is computed from,
  the ones a caller can change: "The slug riverside-hall is held
  already, by riverside: a slug is held once across the venues. This is
  computed from Name."
- **Never typed.** Both documents state it `readOnly` with
  `x-surface.computed: {"from": ["label"], "when": "create"}` and never
  in `required`; the write tool's `values` leaves it out and the read
  answers it; the form draws it fixed; `POST .../schema` answers the
  value it would compute for the values in progress. A value sent for it
  equal to the one it holds is noted, code `computed`, and not taken, so
  a client that writes back the document it read is not refused; any
  other is refused with the same code
  (why: [decision](decisions.md#a-computed-key-is-a-pure-function-of-its-sources)).

A computed key is a resource's own key, at its top: `compute()` is on the
owner's blueprint only, and refused in a structure, a structure mixed in
and outputs.

### A title and its machine name

Drupal's title and machine name pair is a computed key computed unless
sent, of core's config schema type `machine_name`, by the stock
`MachineName`:

```php
$inputs->add('label', 'string', t('Name'))->setRequired(TRUE);
$inputs->compute('id', 'machine_name', t('Machine name'),
  from: ['label'], using: MachineName::class, when: Compute::UNLESS_SENT);
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

- **The type.** A type typed data does not know is read from the config
  schema: `machine_name` is a string with the pattern `^[a-z0-9_]+$`
  and a Length of 166, both the key's own and stated in both documents.
  The key may narrow them, `->addConstraint('Length', ['max' => 32])`,
  never widen them, and the generated config schema writes `type:
  machine_name` with what it narrows.
- **Left out, computed; sent, kept.** `MachineName` transliterates the
  title, lowercases it, replaces each run of characters outside
  `[a-z0-9_]` with one underscore, cuts it to the key's Length and trims
  the underscores at its ends, as core's machine name element does in
  the browser: "Café Déjà Vu" is `cafe_deja_vu`. A value sent is judged
  by the key's constraints and kept. Both documents state the key with
  `x-surface.computed: {"from": ["label"], "when": "unless_sent"}` and
  no `readOnly`; it is never in `required`, and the write tool offers it.
- **Held once.** Where the key is the identity a create names and the
  storage can say whether a thing is stored under it
  (`DeletableStorageInterface::exists()`, which `ConfigEntityStorage`
  answers), the build adds the stock `SurfaceUnique` to it: "A venue
  named riverside already exists.", code `taken`, named under
  `checkedOnServer`. No resource writes a uniqueness rule of its own.
- **Drawn as core's element.** The form draws it as a `machine_name`
  element whose source is the title's element, so the browser suggests
  the name as the title is typed and a person may edit it. On an update
  the identity is known, locked, and drawn disabled with its value.

Example 15's venue and the content type example declare it
(why: [decision](decisions.md#a-machine-name-is-a-computed-identity)).

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
the tool door turns the flag off explicitly when it derives one
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
