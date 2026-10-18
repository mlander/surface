# Data Surface

A *data surface* is the description of a group of values a piece of software
accepts: what each value is called, what it means, what type it is, what
it may contain, what it defaults to, and which of its values depend on
which others. Drupal core already has a vocabulary for all of that in its
typed data definitions; what it has never had is a place to keep a group
of them, hand that group to one caller after another, and have every
caller agree about what was accepted.

This module is that place. A *resource* (a thing with identity and
storage that operations read and write: a content type, a field) or a
*structure* (a shape with members that only something else holds: a
block's settings) is declared once as a class, built in a context,
sealed into a data surface, and then read: a generated form renders it,
a pipeline accepts and validates values against it, and storage writes
the accepted values where they live (a plugin's configuration, a
field's settings, a content type and its base field overrides, a config
object, or state). A resource names its own storage class; a plugin's
host supplies the storage for the structure it holds. The same data
surface answers a form submit, a Drush command, an agent call and a test
without any of them owning a second copy of the rules. When a value
depends on another (a bundle list that only makes sense once an entity
type is chosen), a refiner narrows the definition, and the form rebuilds
that part of itself over AJAX rather than the host re-implementing the
dependency.

The rule the module holds itself to is that refinement may only *narrow*.
Whatever a data surface advertises when it is sealed stays true of
everything it accepts afterwards, so a caller that read it once, or a
machine-readable contract emitted from it, is never contradicted later.
That is the property that makes a data surface worth having over a form
array, and it is the property the module exists to prove out for
[the core issue behind it](https://www.drupal.org/project/drupal/issues/3622144).

## Requirements

Drupal 11.3 or 12, PHP 8.3 or newer. No dependencies beyond core, and no
configuration of its own.

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
discovered; nothing is registered by hand. Its shape is static, asked
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

The module uses these twelve words in exactly one sense each.

- **Resource**: a thing with identity and storage that operations read
  and write; the form, the tool and the schema are derived from it.
  Declared as a class carrying `#[Resource(id, identity:, storage:,
  access:)]`. A resource with no identity keys is a singleton.
- **Structure**: a shape with members and no identity or storage of its
  own, only ever held by something else: attached to a parent, filling a
  slot as a variant, or configuring a plugin, whose host supplies the
  operation and the storage. Declared as a class carrying
  `#[Structure(id, access:)]`.
- **Data surface**: what the build step seals from a resource or
  structure in a context: an immutable group of data definitions, plus
  the map of which definitions depend on which others and the refiners
  that narrow them. Once sealed it is only read. It has two halves: what
  a host **accepts**, and what its execution **emits**, declared in the
  same vocabulary (see [Outputs](docs/outputs.md)).
- **Definition**: one core `DataDefinitionInterface` describing a single
  value: its type, label, description, constraints, whether it is
  required, and the interim default and example metadata the module
  carries until core lands its own.
- **Refiner**: a `#[RefinesInput]` method that returns a narrower
  definition for one key given the values of the siblings it watches. A
  refiner may restrict what a definition allows; it may never widen it.
- **Resolver**: a `DataSurfaceOptionsResolver` plugin that turns a
  constraint into a value/label list with its cacheability, so the list
  that validates a value and the list that is offered as form options are
  the same list, derived rather than repeated.
- **Storage**: where accepted values are written, and the place that
  knows the distance between the layout a resource describes and the
  layout the store wants. A resource names its storage class, a
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
- **Operation**: one way a resource is asked for: add, edit, reuse. A
  static method on the class carrying `#[Operation]`, returning a
  context that says which identity keys are already known (and locked),
  and carrying the permission that gates it. A structure may declare
  operations too, for what holds it to choose between. Operations are
  what routes and tools are generated from: a route naming a resource
  class and an operation is a working form with no form class behind it,
  through `Form\DataSurfaceOperationForm`, and `data_surface_tool`
  derives one tool per operation that can be asked on its own. Who may
  submit is the operation's permission and then the resource's access
  class, and the pipeline consults that answer before it reads or writes
  anything, so a form, a tool and the agent or Drush command calling it
  resolve one gate rather than several.
- **Context**: an `OperationContext`: where a resource or structure is
  being asked for. Its id (the operation id, or a host's own verb such
  as `configure`), whether it creates, which identity keys are known, and
  any narrowing, starting values or child contexts the operation adds.
  Built by an operation (or bare, by a plugin host), applied once by the
  build step, and never seen by a shape method or a refiner.
- **Alter**: another module's class in its `src/Alter/`, carrying
  `#[Alters]`. It adds keys to a resource or structure (mounted under
  `third_party_settings.<module>`), rewords a label with `describe()`,
  offers more values on a fixed list with `extendChoices()`, and narrows
  with `#[RefinesInput]` methods, on the owner's keys or its own. It
  never removes a key or a value, never retypes, and cannot attach.
- **Slot**: an attached structure a sibling key chooses, declared with
  `attachBy($key, by: $sibling)`. Always open: the parent names no
  child, and every class marked `#[Variant]` for it fills it for one
  value, as do derived variants. Until the sibling has a value it is an
  `any` placeholder, which refinement narrows to the chosen variant's
  map. (`attach()` is the fixed kind: one child, always there.)

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
  example 1, and example 3 called as a tool with no form, with a reset
  to the shipped settings for a retake. Needs
  [Tool](https://www.drupal.org/project/tool).
- **Data Surface Examples - Compliance**
  (`data_surface_examples_compliance`): example 4, one alter class
  changing example 3's resource, which does not know it.
- **Data Surface - React** (`data_surface_react`): the served contract,
  an operation as JSON Schema 2020-12 with an `x-surface` extension, a
  refine endpoint that re-narrows it as answers change, a validate
  endpoint that rehearses the write, and a React app that renders the
  form from it at `/surface-react/{surface}/{situation}`. Its built app
  is committed, so it needs no Node.

Each submodule's README says what it shows, how to try it, and what gates
it.

## Documentation

Full documentation is under [`docs/`](docs/).

| Page | What it covers |
| --- | --- |
| [Your first resource](docs/first-resource.md) | A settings page from a resource, an operation, a storage and `add()`: the whole module, pinned to `data_surface_first`. |
| [Your second resource](docs/second-resource.md) | One key whose allowed values depend on another's, with one refiner. |
| [Concepts](docs/concepts.md) | Every concept, the question that makes you need it, and when to skip it. |
| [Home](docs/index.md) | What a resource and a structure are, and where each concept lives in the code. |
| [One resource, every door](docs/every-door/index.html) | An interactive 3D model: a resource, its attached structures and an alter, the doors that read its contract, and the same inputs as they are written today. Open the file in a browser. |
| [The pattern](docs/pattern.md) | What an author writes, where it goes, and why it is shaped that way. |
| [How it fits](docs/how-it-fits.md) | One build followed from the caller to a sealed data surface. |
| [Decisions](docs/decisions.md) | The points the pattern left open, and how the module settled them. |
| [Installation](docs/installation.md) | Install, integrations, submodules. |
| [Declaring a resource or structure](docs/declaring-a-resource.md) | The class, adopting it on a plugin, defaults, locking, secrets, required. |
| [Resources and structures](docs/resources.md) | Operations, alters, attached structures and variants, access, storage, tools, the catalogue. |
| [Catalogue](docs/catalogue.md) | Every resource and structure the modules here declare, generated from the static layer. |
| [The pipeline](docs/pipeline.md) | Access, accept, validate, prepare, commit; dry runs; exceptions. |
| [Value semantics](docs/semantics.md) | Configured or not, the coercion table (the Tool API's), secrets, stale values, shape mismatches, required, locked keys. |
| [Outputs](docs/outputs.md) | Declaring what a host emits, the Omitted sentinel, conformance, its two consumers, an alter's outputs. |
| [Storage](docs/storage.md) | What storage is, prepare and commit, the seven engine storage classes (a simple config object first), config schema, settings layouts, secrets, the serialization rule. |
| [The served contract](docs/served-contract.md) | An operation as JSON Schema with `x-surface`, its refine and validate endpoints, and the React form rendered from it. |
| [Generated forms](docs/forms.md) | Host families, the merge rule, AJAX, extraction, stale values, the operation form with its panel and cosmetic seam. |
| [Widgets](docs/widgets.md) | The widget plugin type, and writing one. |
| [Options and resolvers](docs/options.md) | `LabeledChoice`, the resolver plugin type, the stock resolvers. |
| [Refinement](docs/refinement.md) | An alter offering values and a refiner narrowing them, the narrowing table, cacheability, a worked example, and what it does not reach yet. |
| [Adoption catalogue](docs/adoption.md) | Where core can adopt this, group by group. |
| [Core gaps](docs/core-gaps.md) | The interim spellings and the upstream issues. |

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
