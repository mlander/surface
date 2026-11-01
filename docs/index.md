# Surface API documentation

Every page of the documentation, in four sections and the project's own
records. This page only points; each linked page explains its idea.

## Introduction

[The introduction](introduction/index.md): short pages, one idea each,
for a Drupal developer who has never used the module. Nothing to
install; read it first.

## Build

- [Your first resource](first-resource.md): a settings page from a
  resource, an operation, a storage and `add()`, pinned to the shipped
  module `surface_examples_first`.
- [Your second resource](second-resource.md): one key whose allowed
  values depend on another's, with one refiner.
- [A plugin's settings](plugin-settings.md): give a block, formatter or
  condition plugin a generated settings form.
- [Testing a resource](testing-a-resource.md): `ResourceTestBase`, the
  invariants (never stricter, the round trip, static agreement, every
  door, the baseline) held for your own resources from one kernel test.
- [Generating a resource](generating.md): `drush generate
  surface:resource`, the first page's files from an interview, a config
  schema entry or a JSON Schema document, and the comments it leaves for
  what it cannot write.
- [Common settings recipes](recipes.md): a time of day, a multi-select,
  a text area and the other keys a settings page often asks for, one
  snippet each.
- [See what your site has](catalog-command.md): `drush surface:catalog`,
  every resource and structure on a site, each operation's doors, one in
  full, and what did not build.

## Reference

### Look up a term or a concept

- [Glossary](glossary.md): every term, its plain and its precise
  definition.
- [Concepts](concepts.md): every concept, the question that makes you
  need it, when to skip it, and the page that shows it; its
  [Namespaces](concepts.md#namespaces) table gives each class's `use`
  line.
- [The pattern](pattern.md): what an author writes, where it goes, and
  why it is shaped that way.
- [How it fits](how-it-fits.md): one build followed from the caller to
  a sealed data surface.
- [Built on typed data](on-typed-data.md): what core's Typed Data API
  gives, the three parts the module adds on top of it (the blueprint,
  the build and the seal), and why core alone does not do each today.
- [Transformations](transformations.md): a value asked for one way and
  stored another (coercion, layouts, the doors' wire forms), what runs
  because something was saved, and a value nobody types.

### Writing a resource

- [Declaring a resource or structure](declaring-a-resource.md): the
  class, adopting it [on a plugin](declaring-a-resource.md#on-a-plugin),
  defaults, locking, secrets, required.
- [Resources and structures](resources.md): [which way to compose](resources.md#which-way-to-compose), operations, alters,
  attached structures and variants, variants from the site, ports,
  mixins, collections, keys from the site, access, storage, tools, the
  catalog.
- [Reading a schema](reading-a-schema.md): a JSON Schema read into a
  structure: the mapping, what is kept and checked on the server, what
  is noted and refused, older drafts, the round trip.
- [Refinement](refinement.md): an alter offering values and a refiner
  narrowing them, a rule declared as data, a key that does not apply,
  the narrowing table, cacheability, a worked example, and what it does
  not reach yet.
- [Options and resolvers](options.md): `LabeledChoice`, the resolver
  plugin type, the stock resolvers.
- [Storage](storage.md): what storage is, prepare and commit, the
  engine storage classes (a simple config object first), config schema,
  settings layouts, secrets, the serialization rule.
- [Outputs](outputs.md): declaring what a host emits, the `NotEmitted`
  sentinel, conformance, its two consumers, an alter's outputs.
- [Widgets](widgets.md): the widget plugin type, and writing one.
- [Generated forms](forms.md): host families, the merge rule, AJAX,
  extraction, stale values, the operation form with its panel and
  cosmetic seam.
- [Content entities](entities.md): a content entity's create and update
  form as a derived resource, one structure per field, field access as
  each child's access.
- [Evolving a resource](evolution.md): which changes are compatible, the
  version, stored-value migrations as designed, and the contract diff
  that holds a module to its baselines.
- `surface.api.php`, beside the module's README: resource and
  structure classes, alters and the two plugin types, for the API
  reference.

### The doors

- [The served contract](served-contract.md): an operation as JSON Schema
  with `x-surface`, its refine and validate endpoints, and the React form
  rendered from it.
- [The pipeline](pipeline.md): access, accept, validate, prepare,
  commit; dry runs; exceptions.
- [Value semantics](semantics.md): configured or not, the coercion
  table (the Tool API's), secrets, stale values, type mismatches,
  required, locked keys.
- [The Tool API door](tools.md): one tool per operation, a read tool
  and a narrowing tool beside it, who may run them, the contract panel,
  and what MCP and the Tool API are told. Enabling it:
  [Installation: Tool](installation.md#tool), for an agent, MCP and
  Drush. The field tools are an example of their own,
  [`surface_examples_field`](https://github.com/mlander/surface/blob/main/modules/surface_examples_field/README.md).
- [The React app](https://github.com/mlander/surface/blob/main/modules/surface_examples_react/README.md):
  an example client of the served contract's endpoints, the React form
  drawn from them.
- [The OpenAPI document](served-contract.md#an-openapi-document):
  every operation's endpoints as OpenAPI 3.1, for any OpenAPI client,
  generator or UI.
- [The component block](https://github.com/mlander/surface/blob/main/modules/surface_examples_component_block/README.md):
  a block that embeds a single-directory component, its settings a
  structure.
- [Block placement](https://github.com/mlander/surface/blob/main/modules/surface_examples_block/README.md): a placed
  block of any plugin as a resource, placed, configured and removed
  through a tool, HTTP and a form.

### Running it

- [Installation](installation.md): install, production settings,
  integrations, and every submodule with its install command.
- [Catalog](catalog.md): every resource and structure the modules here
  declare, with each operation's doors, as `drush surface:catalog
  --format=markdown` writes it for this repository.
- [Performance](performance.md): what is kept across requests, what each
  door builds, and the numbers.

### Detailed diagrams

- [Visuals](visuals/index.html): seven small drawings, one relationship
  each: resource, structure and shape; who may ask; the layers; the
  markers; six ways to compose; the doors; the pipeline. Open the file
  in a browser.

## Examples

[Examples](examples/index.md): every example module, one row each, in
two groups: the modules that teach the pattern, and the pattern applied
to things Drupal already has. Each row gives the concept it shows, its
install command, its page on a site and the page that explains the
idea. Neither the introduction nor the build pages need them.

## Project records

The project's own records: why each rule is what it is, what it was
held to, and what it proposes for core. None of it is needed to use the
module.

- [Decisions](decisions.md): the points the pattern left open, and how
  the module settled them. Every page states a rule itself and links its
  reason in one form, `(why: decision)`, which you may skip.
- [Core gaps](core-gaps.md): the core proposal: what core lacks,
  bottom-up in the order it could be added, each step with its issue and
  what it gives alone.
- [Adoption catalog](adoption.md): where core can adopt this, group by
  group.
- [Audit: never stricter than the runtime](audit-never-stricter.md):
  where the schema could be stricter than the pipeline, case by case,
  each verdict pinned by a test.

Logs of people and agents who did not write the module, each kept as
written:

- [Fresh eyes, 2026-10-18](fresh-eyes-2026-10-18.md): a newcomer builds
  a settings page from the documentation alone.
- [Fresh eyes, 2026-10-19](fresh-eyes-2026-10-19.md): a second newcomer
  builds a library's opening hours, a reused structure and an alter.
- [Fresh eyes three, 2026-10-19](fresh-eyes-2026-10-19b.md): a third
  newcomer builds a digest's settings from the documentation alone.
- [Fresh eyes four, 2026-10-21](fresh-eyes-2026-10-21.md): a fourth
  newcomer builds a rental's settings, with a shape, a port and an alter,
  from the docs alone.
- [Two adopters, 2026-10-19](adopters-2026-10-19.md): two contributed
  modules' settings forms, pathauto's and honeypot's, ported as
  resources.
- [Pathauto again, 2026-10-26](adopters-2026-10-26.md): pathauto's
  settings form re-ported a week later from the docs alone, as a size
  measurement and a fourth newcomer run: the line counts side by side,
  and what the form builder still gets wrong.
- [Reviews, 2026-10-20](reviews-2026-10-20/synthesis.md): the synthesis
  of thirteen developer-experience reviews; each review is in
  [the same directory](reviews-2026-10-20/).
- [API consolidation, 2026-10-24](reviews-2026-10-24/api-consolidation.md):
  a decision list of renames, merges and drops across the API.
