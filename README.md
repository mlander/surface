# Data Surface

A *surface* is the description of a group of values a piece of software
accepts: what each value is called, what it means, what type it is, what
it may contain, what it defaults to, and which of its values depend on
which others. Drupal core already has a vocabulary for all of that in its
typed data definitions; what it has never had is a place to keep a group
of them, hand that group to one caller after another, and have every
caller agree about what was accepted.

This module is that place. A surface is built once, sealed, and then
read: a generated form renders it, a pipeline accepts and validates
values against it, and a target writes the accepted values to a config
entity, to field settings, to base field overrides, to a plugin's
configuration, or to state. The same surface
answers a form submit, a Drush command, an agent call and a test without
any of them owning a second copy of the rules. When a value depends on
another — a bundle list that only makes sense once an entity type is
chosen — a refiner narrows the definition, and the form rebuilds that
part of itself over AJAX rather than the host re-implementing the
dependency.

The rule the module holds itself to is that refinement may only *narrow*.
Whatever a surface advertises when it is sealed stays true of everything
it accepts afterwards, so a caller that read the surface once, or a
machine-readable contract emitted from it, is never contradicted later.
That is the property that makes a surface worth having over a form array,
and it is the property the module exists to prove out for
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

## Quick start

Declare the surface as a class, name it on the plugin whose values it
describes, and delete the form code:

```php
#[Surface('block.my_teaser')]
final class TeaserBlockSurface implements SurfaceInterface {

  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('headline', 'string', new TranslatableMarkup('Headline'), default: 'Featured content')
      ->setRequired(TRUE)
      ->addConstraint('Length', ['max' => 50]);
    $inputs->add('limit', 'integer', new TranslatableMarkup('Number of items'), default: 10)
      ->setRequired(TRUE)
      ->addConstraint('Range', ['min' => 1, 'max' => 50]);
  }

}
```

```php
#[Block(id: 'my_teaser', admin_label: new TranslatableMarkup('Teaser'))]
#[UsesSurface(TeaserBlockSurface::class)]
final class TeaserBlock extends DataSurfaceBlockBase {

  public function build(): array {
    return ['#markup' => $this->getConfiguration()['headline']];
  }

}
```

The surface class goes in the module's `src/Surface/`, where it is
discovered; nothing is registered by hand. The block has no
`defaultConfiguration()`, no `blockForm()`, no `blockValidate()` and no
`blockSubmit()`. The block configuration form is generated from the
definitions, with a maxlength on the headline and a number spinner
bounded at 1 and 50.

The same surface answers a caller that never renders anything:

```php
$result = \Drupal::service('data_surface.pipeline')->submit(
  $block->getDataSurface(),
  ['headline' => 'Latest', 'limit' => '5'],
  new PluginConfigurationTarget($block),
);
$result->isValid();
```

`'5'` is stored as the integer `5`, a misspelled key comes back as a
violation rather than vanishing, and the messages are the ones the form
would have shown.

## Glossary

The module uses these seven words in exactly one sense each.

- **Surface** — an immutable group of data definitions, plus the map of
  which definitions depend on which others and the refiners that narrow
  them. Declared as a class carrying `#[Surface]`, built by the build
  step in a context, sealed, then only read. A surface has two halves:
  what a host **accepts**, and what its execution **emits**, declared in
  the same vocabulary — see [Outputs](docs/outputs.md).
- **Definition** — one core `DataDefinitionInterface` describing a single
  value: its type, label, description, constraints, whether it is
  required, and the interim default and example metadata the module
  carries until core lands its own.
- **Refiner** — a `#[RefinesInput]` method that returns a narrower
  definition for one key given the values of the siblings it watches. A
  refiner may restrict what a definition allows; it may never widen it.
- **Resolver** — a `DataSurfaceOptionsResolver` plugin that turns a
  constraint into a value/label list with its cacheability, so the list
  that validates a value and the list that is offered as form options are
  the same list, derived rather than repeated.
- **Target** — the destination accepted values are written to, and the
  place that knows the distance between the shape a surface describes and
  the shape storage wants. Config entities, field settings, base field
  overrides, plugin configuration, state, and compositions of those.
- **Host** — the plugin whose configuration a surface is, and whose
  protocol it has to satisfy: a block, a field formatter, an action, a
  condition, a field type, any configurable plugin. It names the
  surface with `#[UsesSurface]` and supplies the context and the target,
  because only it holds the instance.
- **Situation** — one way a surface is asked for: add, edit, reuse. A
  static method on the surface class carrying `#[Situation]`, returning
  a context that says which identity keys are already known (and
  locked), and carrying the permission that gates it. Situations are
  what routes and tools are generated from: a route naming a surface
  class and a situation is a working form with no form class behind it,
  through `Form\DataSurfaceSituationForm`, and `data_surface_tool`
  derives one tool per situation. Who may submit is the situation's
  permission and then the surface's access class, and the pipeline
  consults that answer before it reads or writes anything — so a form,
  a Drush command, a config action and an agent resolve one gate rather
  than four.

## Experimental submodules

Every submodule is `lifecycle: experimental`. They are where the
architectural decisions are visible in practice, and they double as the
fixtures the tests run against, so they are supported but their APIs and
their configuration may change without a deprecation path.

- **Data Surface Demo** (`data_surface_demo`) — one surface driving a
  block, a field formatter and a standalone form.
- **Data Surface Demo - Classic** (`data_surface_demo_classic`) — the
  same block and the same formatter written the pre-surface way, by
  hand, with a parity test holding the two to the same behavior and a
  README counting what each costs. Depends on nothing from this module.
- **Data Surface Demo Extras** (`data_surface_demo_extras`) — a
  third-party module extending someone else's surface through surface
  alters, with no form alter anywhere; and, for content types, the same
  two settings added to core's own form the classic way, so the two can
  be compared.
- **Data Surface Demo - Node type** (`data_surface_demo_node_type`) — one
  surface serving an add form and an edit form through two situations,
  with a target of its own, and two routes that name the generic
  situation form rather than a form class. Adds its own permission; see its README.
- **Data Surface - Address field settings** (`data_surface_address`) — a
  contributed field type adopting a surface without being forked. Needs
  [Address](https://www.drupal.org/project/address).
- **Data Surface - Tool API bridge** (`data_surface_tool`) — the same
  surface serving a non-form caller. Needs
  [Tool](https://www.drupal.org/project/tool).
- **Data Surface Demo - Node type tool**
  (`data_surface_demo_node_type_tool`) — a content type add tool whose
  input is the content type surface, so a setting another module mounts
  on it reaches an agent with no change to the tool; with a generated
  comparison against Tool Belt's bundle tool and core's form.

Each submodule's README says what it shows, how to try it, and what gates
it.

## Documentation

Full documentation is under [`docs/`](docs/).

| Page | What it covers |
| --- | --- |
| [Home](docs/index.md) | What a surface is, and where each concept lives in the code. |
| [The pattern](docs/pattern.md) | What an author writes, where it goes, and why it is shaped that way. |
| [How it fits](docs/how-it-fits.md) | One build followed from the caller to a sealed surface. |
| [Decisions](docs/decisions.md) | The points the pattern left open, and how the module settled them. |
| [Installation](docs/installation.md) | Install, integrations, submodules. |
| [Declaring a surface](docs/declaring-a-surface.md) | The surface class, adopting it on a plugin, defaults, locking, secrets, required. |
| [Surfaces as classes](docs/surfaces.md) | Situations, alters, subsurfaces and variants, access, targets, tools, the catalogue. |
| [Surface catalogue](docs/catalogue.md) | Every surface the modules here declare, generated from the static layer. |
| [The pipeline](docs/pipeline.md) | Access, accept, validate, prepare, commit; dry runs; exceptions. |
| [Value semantics](docs/semantics.md) | Configured or not, the casting table, shape mismatches. |
| [Outputs](docs/outputs.md) | Declaring what a host emits, the Omitted sentinel, conformance. |
| [Targets](docs/targets.md) | The six engine targets and the serialization rule. |
| [Generated forms](docs/forms.md) | Host families, the situation form and its cosmetic seam, the merge rule, AJAX, extraction. |
| [Widgets](docs/widgets.md) | The widget plugin type, and writing one. |
| [Options and resolvers](docs/options.md) | `LabeledChoice`, the resolver plugin type, the stock resolvers. |
| [Refinement](docs/refinement.md) | Contributions, the narrowing table, cacheability. |
| [Adoption catalogue](docs/adoption.md) | Where core can adopt this, group by group. |
| [Core gaps](docs/core-gaps.md) | The interim spellings and the upstream issues. |

`data_surface.api.php` documents surface classes, alters and the two
plugin types for the API reference.

## Development

`scripts/check.sh` runs the test suite, PHPCS, PHPStan and cspell in one
go, with the incantations each of them needs; `CLAUDE.md` explains what a
passing run looks like.

The design history is kept beside this file: [PLAN.md](PLAN.md) for the
gaps and phases as they were reasoned through, [ADOPTION.md](ADOPTION.md)
for the full core survey, and [HARDENING.md](HARDENING.md) for the audit
findings and the decisions taken on them; [ROADMAP.md](ROADMAP.md) is the
forward plan. The current documentation is
`docs/`.
