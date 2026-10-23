# Data Surface

A *data surface* is what one resource accepts, settled in layers and
then sealed. The owner, the resource's class, declares its keys and the
rules between them. Other modules contribute under names of their own:
they add keys at their own mount, and their rules may narrow the
owner's keys but never widen them. Declared rules and refiners settle
what applies to these values, ahead of time where the answer depends
only on the values, and per request where it depends on the site, such
as a room closed today. Access locks keys or leaves them out for the
account that asks. The build seals the result.

Every door reads that one sealed data surface and nothing else: the
generated form, the tool, MCP, ECA, HTTP with its OpenAPI document, and
the React page. That is why they agree on what is accepted, and why one
module can change all of them at once without touching any. The
reason is the alter contract: a contribution is made under its module's
own name before the seal, and after the seal nothing widens, so no door
sees anything but the sealed result and nothing a door was told stops
being true. The module exists to prove this out for
[the core issue behind it](https://www.drupal.org/project/drupal/issues/3622144).

To get one, write a class with a `#[Resource]` attribute, an operation
on it, a storage class for where its values live, and its keys,
declared with `add()`. [Your first resource](docs/first-resource.md)
builds a settings page from those four things.

## Requirements

Drupal 11.3 or 12, PHP 8.3 or newer, and
[opis/json-schema](https://github.com/opis/json-schema) (Apache-2.0),
which checks what a structure read from a JSON Schema keeps of it
([Reading a schema](docs/reading-a-schema.md#what-is-kept)); Composer
installs it with the module. No configuration of its own.

## Installation

```
composer require drupal/data_surface
drush pm:install data_surface
```

See [docs/installation.md](docs/installation.md) for the optional Address
and Tool integrations and the submodules.

## Your first resource

Most first projects are a settings page. [Your first
resource](docs/first-resource.md) builds one, its form, its tool and its
schema, from a resource class, an operation, a storage and `add()`, and
nothing else; [Your second resource](docs/second-resource.md) adds one
key that depends on another. Their code is the shipped module
`data_surface_first`. Every other idea is in [Concepts](docs/concepts.md),
with the question that makes you need it.

## Quick start: a plugin's settings

A block, a formatter or a condition has settings but no storage of its
own. Declare the structure as a class, name it on the plugin whose values
it describes, and delete the form code:

```php
#[Structure('block.my_teaser')]
final class TeaserBlockStructure implements StructureInterface {

  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('headline', 'string', t('Headline'), default: 'Featured content')
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 50]);
    $inputs->add('limit', 'integer', t('Number of items'), default: 10)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 50]);
  }

}
```

```php
#[Block(id: 'my_teaser', admin_label: new TranslatableMarkup('Teaser'))]
#[UsesStructure(TeaserBlockStructure::class)]
final class TeaserBlock extends DataSurfaceBlockBase {

  public function build(): array {
    return ['#markup' => $this->getConfiguration()['headline']];
  }

}
```

The structure class goes in the module's `src/Structure/`, where it is
discovered; nothing is registered by hand. Its declaration is static, asked
of the class, so it is never constructed, and its labels are `t()`.
A small plugin can also be its own structure: `#[UsesStructure]` with no
argument, and the same static `defineInputs()` on the block class
itself. A thing with identity and storage of its own is a resource
instead: `#[Resource]` in `src/Resource/`, naming its identity keys and
its storage class, with the operations routes and tools are generated
from ([Declaring a resource or structure](docs/declaring-a-resource.md)).
The block has no `defaultConfiguration()`, no `blockForm()`, no
`blockValidate()` and no `blockSubmit()`. The block configuration form is generated from the
definitions, with a maxlength on the headline and a number spinner
bounded at 1 and 50.

The same data surface answers a caller that never renders anything:

```php
$result = \Drupal::service('data_surface.pipeline')->submit(
  $block->getDataSurface(),
  ['headline' => 'Latest', 'limit' => '5'],
  new PluginConfigurationStorage($block),
);
$result->isValid();
```

`'5'` is stored as the integer `5`, a misspelled key comes back as a
violation rather than vanishing, and the messages are the ones the form
would have shown.

## Glossary

The module uses these twenty-three words in exactly one sense each.

- **Resource**: a thing with identity and storage that operations read
  and write; the form, the tool and the schema are derived from it.
  Declared as a class carrying `#[Resource(id, identity:, storage:,
  access:)]`. A resource with no identity keys is a singleton.
- **Structure**: a group of keys with no identity or storage of its
  own, only ever held by something else: attached to a parent, one of
  the variants at a key, or configuring a plugin, whose host supplies the
  operation and the storage. Declared as a class carrying
  `#[Structure(id, access:)]`. The six ways one is made of others are
  side by side in [Which way to compose](docs/resources.md#which-way-to-compose).
- **Shape**: a named type, in Smithy's sense, of which there are three
  kinds: a resource, a structure, and a named scalar shape, one value
  (its type, label, description, constraints, examples) declared once
  and used at many keys. The third is the one the attribute names: a
  class in `src/Shape/` carrying `#[Shape(id)]`, whose static `define()`
  returns a plain data definition, used in the type position of
  `add()`; each key may only narrow it, an alter of it tightens it at
  every key, and the served contract writes it once, as a def
  ([Named shapes](docs/declaring-a-resource.md#named-shapes)). Never the
  blueprint or the layout.
- **Data surface**: what the build step seals from a resource or
  structure in a context: an immutable group of data definitions, plus
  the map of which definitions depend on which others and the refiners
  that narrow them. Once sealed it is only read. It has two halves: what
  a host **accepts**, and what its execution **emits**, declared in the
  same vocabulary (see [Outputs](docs/outputs.md)).
- **Blueprint**: what a resource or structure declares its keys into,
  handed to `defineInputs()` and `defineOutputs()` as a
  `BlueprintInterface`; an alter gets the add-only
  `BlueprintAdditionsInterface`. Filled once at build, then sealed.
- **Layout**: how a storage lays accepted values out where that differs
  from how they are asked for, a `StorageLayoutInterface`; an alter
  hands one for its mount with `AltersStorageLayoutInterface::storageLayout()`.
- **Definition**: one core `DataDefinitionInterface` describing a single
  value: its type, label, description, constraints, whether it is
  required, and the interim default and example metadata the module
  carries until core lands its own.
- **Refiner**: a `#[RefinesInput]` method that returns a narrower
  definition for one key given the values of the siblings it watches. A
  refiner may restrict what a definition allows; it may never widen it.
- **Declared rule**: a rule between keys whose condition and effect a
  schema can state, written in the blueprint beside the keys it is about,
  `$inputs->narrow($key)->when(Condition::...)` and its effects. A
  static refiner by construction that only tightens, stated by the full
  schema as written ([Declaring a rule](docs/refinement.md#declaring-a-rule)).
- **A key that does not apply**: a key with no business being there for
  some values of another, as a send day for a monthly bulletin; not a key
  narrowed to no values. A refiner answers `DoesNotApply::here()` from a
  method whose return type names it, or a declared rule says
  `doesNotApply()`; storage keeps what the key holds
  ([When a key does not apply](docs/refinement.md#when-a-key-does-not-apply)).
- **Resolver**: a `DataSurfaceOptionsResolver` plugin that turns a
  constraint into a value/label list with its cacheability, so the list
  that validates a value and the list that is offered as form options are
  the same list, derived rather than repeated.
- **Keys from the site**: one key per thing the site has, such as a
  checkbox per form, in a map at a key: `addEach($key, $type, from:)` for
  a value per thing, `attachEach($key, Structure::class, from:)` for a
  structure per thing. The provider is a service implementing
  `KeysFromSiteInterface`, whose `keys()` answers a `KeyedList`, asked
  at every build ([Keys that come from the site](docs/resources.md#keys-that-come-from-the-site)).
- **Storage**: where accepted values are written, and the place that
  knows the distance between how a resource asks for its values and
  how the store lays them out (a layout). A resource names its storage class, a
  `ResourceStorageInterface` with `load()`, `prepare()` (rehearse the
  write, no side effects) and `commit()`; a plugin's host supplies one of
  the engine's (plugin configuration, field settings) for what it holds.
  Storage composes along the tree of attached children.
- **Host**: the plugin whose configuration a structure is, and whose
  protocol it has to satisfy: a block, a field formatter, an action, a
  condition, a field type, any configurable plugin. It names the class it
  holds with `#[UsesStructure]`, or is the structure itself when the
  attribute names none, and supplies the context and the storage,
  because only it holds the instance.
- **Operation**: one way a resource is asked for: add, edit, reuse.
  `#[Operation]` states its id, label, description, the permission that
  gates it and whether it creates. On the class when asking needs
  nothing more (a settings object's `configure`); on a static method
  returning a context when the context says more, such as which
  identity keys are already known (and locked). A structure may declare
  operations too, for what holds it to choose between. Operations are
  what routes and tools are generated from: a route naming a resource
  class and an operation is a working form with no form class behind it,
  through `Form\DataSurfaceOperationForm`, and `data_surface_tool`
  derives one tool per operation that can be asked on its own. Who may
  submit is the operation's permission and then the resource's access
  class, and the pipeline consults that answer before it reads or writes
  anything, so a form, a tool and the agent or Drush command calling it
  resolve one gate rather than several.
- **Access answer**: what the operation's permission, and then the
  access class, answer for an account, then each alter that answers
  access, the stricter standing; the access class's is an
  `AccessAnswer` (open, locked or omitted). Its kind is read from its
  cacheability and never declared: static varies by the account's
  permissions or roles alone and is stated in the full schema and the
  tool listing for that account; runtime is anything else, marked there
  `x-surface.access: "live"` and settled once the thing is known
  ([Static and runtime access](docs/resources.md#static-and-runtime-access)).
- **Context**: an `OperationContext`: where a resource or structure is
  being asked for. Its id (the operation id, or a host's own verb such
  as `configure`), whether it creates, which identity keys are known, and
  any narrowing, starting values or child contexts the operation adds.
  Built by an operation (or bare, by a plugin host), applied once by the
  build step, and never seen by a method that fills a blueprint or by a
  refiner.
- **Alter**: another module's class in its `src/Alter/`, carrying
  `#[Alters]`. It adds keys to a resource or structure (mounted under
  `third_party_settings.<module>`), rewords a label with `describe()`,
  offers more values on a fixed list with `extendChoices()`, and narrows
  with `#[RefinesInput]` methods, on the owner's keys or its own, and
  may answer access after the owner (`AltersAccessInterface`), locking
  or leaving out and never opening. It never removes a key or a value
  from the declaration, never retypes, and attaches a structure only at
  its own mount.
- **Variants**: a tagged union whose tag is a sibling key, attached with
  `attachVariants($key, by: $sibling)`; each variant is a structure for
  one value of that key, `#[Variant(of:, at:, for:)]`
  ([Variants](docs/resources.md#variants)).
- **Collection**: a list of child instances at a key, declared with
  `attachList($key, Child::class, min:, max:)`: the list and its Count
  are the parent's, and each item is the child's, refined and validated
  in its own frame ([Collections](docs/resources.md#collections)).
- **Mixin**: a structure whose members are merged into the owner's own
  keys, `$inputs->mixIn(Structure::class, reads: [...])`: stored flat,
  refined in the owner's frame, and emitted as `allOf` of its def and the
  owner's properties. Nest for a thing of its own, mix in for shared
  members that are part of this thing ([Mixins](docs/resources.md#mixins)).
- **Port**: a value a structure reads from its container, declared under
  a name of its own with `$inputs->reads($port, $type)` and wired by
  whatever attaches it, `attach($key, Child::class, reads: [$port =>
  $key])`. A live sibling value its rules watch, never stored or drawn by
  the structure ([Ports](docs/resources.md#ports)).
- **Version**: `#[Resource(version:)]` or `#[Structure(version:)]`, from
  1, counting the breaking changes made to the values stored for it, so a
  compatible change never moves it. Stamped `x-surface.version` in both
  documents, and refused above 1 until stored-value migrations are built
  ([Evolving a resource](docs/evolution.md#the-version)). Not the
  contract's own version, `x-surface.contract`, the version of the wire
  format both documents are written in
  ([the contract's own version](docs/evolution.md#the-contracts-own-version)).

## Experimental submodules

Every submodule is `lifecycle: experimental`. They are where the
architectural decisions are visible in practice, and they double as the
fixtures the tests run against, so they are supported but their APIs and
their configuration may change without a deprecation path.

- **Data Surface Demo** (`data_surface_demo`): two structures and three
  hosts, in the two spellings: `DemoBlockStructure`, a class of its own,
  drives a block and a standalone form, and the field formatter is its
  own structure.
- **Data Surface Demo - Classic** (`data_surface_demo_classic`): the
  same block and the same formatter written the classic way, by hand,
  with a parity test holding the two to the same behavior and a README
  counting what each costs. Depends on nothing from this module.
- **Data Surface Demo Extras** (`data_surface_demo_extras`): a
  third-party module extending someone else's structures and resources
  through alters, with no form alter anywhere; and, for content types,
  the same two settings added to core's own form the classic way, so the
  two can be compared.
- **Data Surface Demo - Node type** (`data_surface_demo_node_type`): one
  resource serving an add form and an edit form through two operations,
  with storage of its own, and two routes that name the generic
  operation form rather than a form class. Adds its own permission; see its README.
- **Data Surface - Address field settings** (`data_surface_address`): a
  contributed field type adopting a resource for its settings without
  being forked. Needs [Address](https://www.drupal.org/project/address).
- **Data Surface - Tool API bridge** (`data_surface_tool`): one derived
  tool per operation of every resource that names storage and that no
  plugin uses, `data_surface:<id>:<operation>`; the field resources, with
  their settings derived from config schema; the contract panel; and a
  generated comparison of the field tools against Tool Belt's. Needs
  [Tool](https://www.drupal.org/project/tool).
- **Data Surface - Content entities** (`data_surface_entity`): a
  resource derived for every content entity bundle with a form display,
  one structure per field derived from its typed data, field access as
  each child's access, and storage that validates the entity; a basic
  page added and edited through it at `/surface-entity`. Needs `node`
  and `data_surface_tool`; see `docs/entities.md`.
- **Data Surface Demo - Node type tool**
  (`data_surface_demo_node_type_tool`): no code of its own, only the
  generated comparison between `data_surface:node.type:add`, the tool
  derived from the content type resource, and Tool Belt's bundle tool and
  core's form, once another module has extended content types.
- **Data Surface - Your first resource** (`data_surface_first`): the
  code of the pages "Your first resource" and "Your second resource", a
  newsletter settings page and a store location, held to the pages by a
  test.
- **Data Surface Examples** (`data_surface_examples`): one resource
  that grows, one idea per example, at `/surface-examples`: each
  example a form beside the contract it emits, a classic twin for
  examples 1 and 2, and example 3 called as a tool with no form, with a reset
  to the shipped settings for a retake. Then examples 7 to 12, a small
  resource each for a later concept: a rule written as data, one part
  used twice, keys that come from the site, access and how an operation
  behaves, the stock widgets, and a component's props read from its JSON
  Schema, each page saying what it shows and what its tool answers; and
  example 13, a block that embeds a component, which says to enable
  `data_surface_component_block` and `data_surface_block`. Needs
  [Tool](https://www.drupal.org/project/tool).
- **Data Surface Examples - Compliance**
  (`data_surface_examples_compliance`): example 4, a compliance
  structure and the one alter that attaches it to example 3's resource,
  which does not know it.
- **Data Surface Examples - Workflow**
  (`data_surface_examples_workflow`): example 10, extended, two alters
  that answer access for the program and its fees, which do not know
  them: the fees left out for an account that may not see them, and the
  program fixed once its workflow publishes it.
- **Data Surface component block** (`data_surface_component_block`):
  example 13, a block, "Component", that embeds a single-directory
  component: the component chosen from those the site has and its props
  read from the schema it declares, example 12's two lines named by
  `#[UsesStructure]`, so the block's form, validation and storage come
  from them, placed through the placement resource of
  `data_surface_block`. Needs `block`.
- **Data Surface block placement** (`data_surface_block`): a placed
  block as a resource, `block.placement`, by its machine name: its
  plugin chooses its settings (a plugin's declared structure, or its
  config schema read), its theme its region, and its visibility is a
  list of conditions. Placed, configured, read and removed through four
  tools, HTTP (`DELETE` removes) and a form. Needs `block`.
- **Data Surface - React** (`data_surface_react`): the served contract,
  an operation as JSON Schema 2020-12 with an `x-surface` extension,
  served over HTTP at `/surface-api/{resource}`, the method its
  operation (`GET` reads, `PATCH` updates, `POST` creates, `DELETE`
  removes), with a
  narrowing that re-narrows it as answers change and a dry run that
  rehearses the write, and a React app that renders the form from it at
  `/surface-react/{resource}/{operation}`. Its built app
  is committed, so it needs no Node.
- **Data Surface - OpenAPI** (`data_surface_openapi`): an OpenAPI 3.1
  document of every operation's paths at `/surface-api/openapi.json`
  (and `.yaml`), each operation's full schema as components, built for
  no account so it is the same for everyone, with Swagger UI at
  `/surface-api/docs` and Redoc at `/surface-api/redoc`. Needs `data_surface_react`.

Each submodule's README says what it shows, how to try it, and what gates
it.

## Documentation

Full documentation is under [`docs/`](docs/). [Decisions](docs/decisions.md)
holds the reasons behind each rule, for a reader who wants the why;
nothing on it is needed to build.

**Start here**

| Page | What it covers |
| --- | --- |
| [Home](docs/index.md) | What a resource and a structure are, and where each concept lives in the code. |
| [Your first resource](docs/first-resource.md) | A settings page from a resource, an operation, a storage and `add()`: the whole module, pinned to `data_surface_first`. |
| [Your second resource](docs/second-resource.md) | One key whose allowed values depend on another's, with one refiner. |
| [Concepts](docs/concepts.md) | Every concept, the question that makes you need it, when to skip it, and the page that shows it. |
| [Common settings recipes](docs/recipes.md) | A time of day, a multi-select, a text area and the other keys a settings page often asks for, one snippet each. |
| [The examples](modules/data_surface_examples/README.md) | One registration form that grows an idea at a time, then a small resource per later concept, each at `/surface-examples`. |

**Writing a resource**

| Page | What it covers |
| --- | --- |
| [Declaring a resource or structure](docs/declaring-a-resource.md) | The class, adopting it on a plugin, defaults, locking, secrets, required. |
| [Resources and structures](docs/resources.md) | Which way to compose, operations, alters, attached structures and variants, variants from the site, ports, mixins, collections, keys from the site, access, storage, tools, the catalog. |
| [Reading a schema](docs/reading-a-schema.md) | A JSON Schema read into a structure: the mapping, what is kept and checked on the server, what is noted and refused, older drafts, the round trip. |
| [Refinement](docs/refinement.md) | An alter offering values and a refiner narrowing them, a rule declared as data, a key that does not apply, the narrowing table, cacheability, a worked example, and what it does not reach yet. |
| [Options and resolvers](docs/options.md) | `LabeledChoice`, the resolver plugin type, the stock resolvers. |
| [Storage](docs/storage.md) | What storage is, prepare and commit, the seven engine storage classes (a simple config object first), config schema, settings layouts, secrets, the serialization rule. |
| [Outputs](docs/outputs.md) | Declaring what a host emits, the NotEmitted sentinel, conformance, its two consumers, an alter's outputs. |
| [Widgets](docs/widgets.md) | The widget plugin type, and writing one. |
| [Generated forms](docs/forms.md) | Host families, the merge rule, AJAX, extraction, stale values, the operation form with its panel and cosmetic seam. |
| [Content entities](docs/entities.md) | A content entity's create and update form as a derived resource, one structure per field, field access as each child's access. |

**The doors**

| Page | What it covers |
| --- | --- |
| [The served contract](docs/served-contract.md) | An operation as JSON Schema with `x-surface`, its refine and validate endpoints, and the React form rendered from it. |
| [The pipeline](docs/pipeline.md) | Access, accept, validate, prepare, commit; dry runs; exceptions. |
| [Value semantics](docs/semantics.md) | Configured or not, the coercion table (the Tool API's), secrets, stale values, type mismatches, required, locked keys. |
| [The Tool API bridge](modules/data_surface_tool/README.md) | One tool per operation and a read tool beside it, who may run them, the contract panel, and what MCP and the Tool API are told. |
| [The React app](modules/data_surface_react/README.md) | The served contract's endpoints and the React form drawn from them. |
| [The OpenAPI document](modules/data_surface_openapi/README.md) | Every operation's endpoints as OpenAPI 3.1, for any OpenAPI client, generator or UI. |
| [The component block](modules/data_surface_component_block/README.md) | A block that embeds a single-directory component, its settings a structure. |
| [Block placement](modules/data_surface_block/README.md) | A placed block of any plugin as a resource: placed, configured and removed through a tool, HTTP and a form. |
| [Installation: Tool](docs/installation.md#tool) | Enabling the tool bridge, through which an agent, MCP and Drush call each operation. |
| [One resource, every door](docs/every-door/index.html) | An interactive 3D model: the layers a data surface is sealed from, a resource, its attached structures and an alter, the doors that read its contract, and the same inputs as they are written today. Open the file in a browser. |

**The engine and the record**

| Page | What it covers |
| --- | --- |
| [The pattern](docs/pattern.md) | What an author writes, where it goes, and why it is shaped that way. |
| [How it fits](docs/how-it-fits.md) | One build followed from the caller to a sealed data surface. |
| [Decisions](docs/decisions.md) | The points the pattern left open, and how the module settled them. |
| [Catalog](docs/catalog.md) | Every resource and structure the modules here declare, generated from the static layer. |
| [Evolving a resource](docs/evolution.md) | Which changes are compatible, the version, stored-value migrations as designed, and the contract diff that holds a module to its baselines. |
| [Installation](docs/installation.md) | Install, integrations, submodules. |

**Evidence**

| Page | What it covers |
| --- | --- |
| [Fresh eyes, 2026-10-18](docs/fresh-eyes-2026-10-18.md) | A newcomer builds a newsletter settings page from the documentation alone. |
| [Fresh eyes, 2026-10-19](docs/fresh-eyes-2026-10-19.md) | A second newcomer builds a library's opening hours, a reused structure and an alter. |
| [Fresh eyes three, 2026-10-19](docs/fresh-eyes-2026-10-19b.md) | A third newcomer builds a newsletter digest from the documentation alone. |
| [Two adopters, 2026-10-19](docs/adopters-2026-10-19.md) | Two contributed modules' settings forms, pathauto's and honeypot's, ported as resources. |
| [Audit: never stricter than the runtime](docs/audit-never-stricter.md) | Where the schema could be stricter than the pipeline, case by case, each verdict pinned by a test. |

**The proposal**

| Page | What it covers |
| --- | --- |
| [Core gaps](docs/core-gaps.md) | The core proposal: what core lacks, bottom-up in the order it could be added, each step with its issue and what it gives alone. |
| [Adoption catalog](docs/adoption.md) | Where core can adopt this, group by group. |

`data_surface.api.php` documents resource and structure classes,
alters and the two plugin types for the API reference.

## Development

`scripts/check.sh` runs the test suite, PHPCS, PHPStan and cspell in one
go, with the incantations each of them needs; `CLAUDE.md` explains what a
passing run looks like.

The design history is kept beside this file: [PLAN.md](PLAN.md) for the
gaps and phases as they were reasoned through, [ADOPTION.md](ADOPTION.md)
for the full core survey, [HARDENING.md](HARDENING.md) for the audit
findings and the decisions taken on them, and [REWORK.md](REWORK.md) for
the rework that brought the module to the pattern;
[ROADMAP.md](ROADMAP.md) is the forward plan. The current documentation
is `docs/`, and the decisions the pattern left open are in
[docs/decisions.md](docs/decisions.md).
