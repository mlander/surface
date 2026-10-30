# Examples

The example and demo modules put each idea on a running site. They are
not part of the introduction or the build path: you can read both
without installing any of them. The words of each example's subject (a
registration, a ticket, a venue, a compliance check) belong to that
example only; the concept pages explain each idea without them.

[The examples' README](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md)
walks through every example in full: its form, its contract, its files
and its calls. This page is the index to it.

## Install and run

```bash
drush pm:install surface_examples
```

Then visit `/surface-examples` as an administrator. The examples run on
a fresh site with nothing else, and are written to be shown in order.
Enable a submodule when you reach the example that needs it: the
compliance module at example 4, and the workflow module once example 10
has been shown without it.

```bash
drush pm:install surface_examples_compliance
drush pm:install surface_examples_workflow
```

With `surface_react` enabled as well, the landing page links each
of examples 1 to 3 in React, rendered from the contract it serves
([In React](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#in-react)):

```bash
drush pm:install surface_react
```

With `surface_openapi` as well, `/surface-api/openapi.json` describes
every operation's paths for an OpenAPI client, and the landing page
links it under example 6:

```bash
drush pm:install surface_openapi
```

Which permission each page asks for, and how to reset the examples to
the shipped settings, are in
[the examples' README](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md).

Read [Your first resource](../first-resource.md) first, then the
examples in order, with [Concepts](../concepts.md) as the map. The
landing page, `/surface-examples`, lists each example's concept, docs
page, decision and files, and each example's page lists its files.

## The examples

Examples 1 to 6 grow one registration form, one idea at a time.
Examples 7 to 12 are a small resource each, for one or two later
concepts. Examples 13 to 17 each add one more idea, most in a submodule
of their own.

| Example | Concept it shows | Submodule | Page on a site | Concept's page |
| --- | --- | --- | --- | --- |
| [1: declare what you accept](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-1-declare-what-you-accept) | A resource, its operation and its storage, with a classic twin written by hand. | `drush pm:install surface_examples` | `/surface-examples/1`, `/surface-examples/1/classic` | [Your first resource](../first-resource.md) |
| [2: answers depend on answers](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-2-answers-depend-on-answers) | A refiner: one key narrowed by another, with a classic twin. | `surface_examples` | `/surface-examples/2`, `/surface-examples/2/classic` | [Your second resource](../second-resource.md) |
| [3: made of parts](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-3-made-of-parts) | Structures: a child attached at a key, and variants. | `surface_examples` | `/surface-examples/3` | [Children](../resources.md#children) |
| [4: others get a say](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-4-others-get-a-say) | An alter: another module adds keys and rules to example 3's resource, which does not know it. | `drush pm:install surface_examples_compliance` | `/surface-examples/4` | [A whole alter](../resources.md#a-whole-alter) |
| [5: same contract, no form](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-5-same-contract-no-form) | Example 3 called as a tool, with no form: the tool and the read tool. | `surface_examples` (needs Tool) | `/surface-examples/5` | [The Tool API door](../tools.md) |
| [6: every door](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-6-every-door) | The same contract through every door: the served contract, React and OpenAPI. | `drush pm:install surface_react surface_openapi` | The landing page's React and OpenAPI links, `/surface-api/openapi.json`, `/surface-api/docs` | [The served contract](../served-contract.md) |
| [7: a rule written as data](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-7-a-rule-written-as-data) | A declared rule, and a key that does not apply. | `surface_examples` | `/surface-examples/7` | [Declaring a rule](../refinement.md#declaring-a-rule) |
| [8: one part, used twice](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-8-one-part-used-twice) | A port, a mixin, a named shape and a collection. | `surface_examples` | `/surface-examples/8`, `/surface-examples/8/desk` | [Ports](../resources.md#ports) |
| [9: keys that come from the site](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-9-keys-that-come-from-the-site) | A key per thing the site has, listed by a provider. | `surface_examples` | `/surface-examples/9` | [Keys that come from the site](../resources.md#keys-that-come-from-the-site) |
| [10: who may change what](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-10-who-may-change-what) | Static and runtime access, and what an operation states; extended, two alters that answer access for keys they do not own. | `surface_examples`; extended, `drush pm:install surface_examples_workflow` | `/surface-examples/10`, `/surface-examples/10/check` | [Static and runtime access](../resources.md#static-and-runtime-access) |
| [11: the stock widgets, and what nothing means](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-11-the-stock-widgets-and-what-nothing-means) | The stock widgets, and a key left out, sent null or sent a value. | `surface_examples` | `/surface-examples/11` | [The stock widgets](../widgets.md#the-stock-widgets) |
| [12: a schema read into a structure](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-12-a-schema-read-into-a-structure) | A JSON Schema read into a structure: a component's props. | `surface_examples` | `/surface-examples/12` | [Reading a schema](../reading-a-schema.md) |
| [13: a block that embeds a component](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-13-a-block-that-embeds-a-component) | A block whose settings are a structure: example 12's two lines as a block's settings, placed through the placement resource. | `drush pm:install surface_examples_component_block surface_examples_block` | `/surface-examples/13` | [Plugins](../resources.md#plugins) |
| [14: an optional extension](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-14-an-optional-extension) | A credential pasted as a secret, or a key the site manages: a variant the site adds, or an alter, built once by the owner and once by another module. | `surface_examples`; the managed key, `drush pm:install surface_examples_keys` | `/surface-examples/14`, `/surface-examples/14/webhook` | [Variants from the site](../resources.md#variants-from-the-site) |
| [15: a config entity of your own](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-15-a-config-entity-of-your-own) | A config entity type, the venue, with a resource over it: one added, edited and removed, the listing a read with outputs, the reorder an update over the collection, and the slug a key computed from the name. | `surface_examples` | `/surface-examples/15`, `/surface-examples/15/add` | [A config entity](../storage.md#a-config-entity) |
| [16: references, three ways](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-16-references-three-ways) | A key that refers to an entity: listed while the site has few, looked up past the limit, and of any entity type. | `drush pm:install surface_examples_references` (needs node) | `/surface-examples/16` | [A key that refers to an entity](../options.md#a-key-that-refers-to-an-entity) |
| [17: retitle in bulk, access borrowed](https://github.com/mlander/surface/blob/main/modules/surface_examples/README.md#example-17-retitle-in-bulk-access-borrowed) | A gate: access another thing's owner decides, asked per node, never copied. | `drush pm:install surface_examples_retitle` (needs node) | `/surface-examples/17` | [A gate borrowed from another thing](../resources.md#a-gate-borrowed-from-another-thing) |

What each submodule ships beyond its example, and what it needs, is in
[Installation](../installation.md#experimental-submodules).

## The demo modules

Each shows one idea on a page of core's or a form of its own, outside
the examples' landing page.

| Module | Concept it shows | Install | Page on a site | Concept's page |
| --- | --- | --- | --- | --- |
| [Your first resource](https://github.com/mlander/surface/blob/main/modules/surface_examples_first/README.md) | The code of the first two build pages: a settings page, and one key that depends on another. | `drush pm:install surface_examples_first` | `/admin/config/services/surface-newsletter`, `/admin/config/services/surface-store` | [Your first resource](../first-resource.md), [Your second resource](../second-resource.md) |
| [Demo](https://github.com/mlander/surface/blob/main/modules/surface_examples_plugins/README.md) | A structure as a plugin's settings, in the two spellings: a class of its own for a block and a standalone form, and a formatter that is its own structure. | `drush pm:install surface_examples_plugins` | `/admin/config/development/surface-example-form` | [On a plugin](../declaring-a-resource.md#on-a-plugin) |
| [Demo, classic](https://github.com/mlander/surface/blob/main/modules/surface_examples_classic/README.md) | The same block and formatter written by hand, held to the same behavior by a parity test, and what each way costs. | `drush pm:install surface_examples_classic` | The classic block and formatter | [On a plugin](../declaring-a-resource.md#on-a-plugin) |
| [Demo extras](https://github.com/mlander/surface/blob/main/modules/surface_examples_extras/README.md) | Alters: a third-party module extending another module's structures and resources, with no form alter. | `drush pm:install surface_examples_extras` | The demo's formatter settings, and core's content type form | [Alters](../resources.md#alters), [Worked example: the extras demo](../refinement.md#worked-example-the-extras-demo) |
| [Demo, node type](https://github.com/mlander/surface/blob/main/modules/surface_examples_node_type/README.md) | One resource with identity, a create and an update operation, and storage of its own, served by the generic operation form. | `drush pm:install surface_examples_node_type` | `/admin/structure/types/surface-add`, `/admin/structure/types/manage/{type}/surface-edit` | [Operations and identity](../declaring-a-resource.md#operations-and-identity) |
| [Demo, node type tool](https://github.com/mlander/surface/blob/main/modules/surface_examples_node_type_tool/README.md) | The tool derived from the content type resource, compared with a hand-written tool once another module has extended content types. | `drush pm:install surface_examples_node_type_tool` (needs Tool) | No page: the recorded comparison | [Tools](../resources.md#tools) |
| [Performance settings](https://github.com/mlander/surface/blob/main/modules/surface_examples_performance/README.md) | A singleton resource stored in core's own config object, held to core's config schema unchanged. | `drush pm:install surface_examples_performance` | `/admin/config/development/surface-performance` | [A simple config object](../storage.md#a-simple-config-object) |
| [Content entities](https://github.com/mlander/surface/blob/main/modules/surface_examples_entity/README.md) | A resource derived for every content entity bundle, one structure per field. | `drush pm:install surface_examples_entity` (needs node) | `/surface-entity` | [Content entities](../entities.md) |
| [Field settings](https://github.com/mlander/surface/blob/main/modules/surface_examples_field/README.md) | Core's field instance and field storage settings as resources, each field type's settings a variant read from its settings class or its config schema, and the field tools compared with Tool Belt's. | `drush pm:install surface_examples_field` (the tools need Tool) | No page: the field tools, and the recorded comparison | [Variants from the site](../resources.md#variants-from-the-site) |
| [Block placement](https://github.com/mlander/surface/blob/main/modules/surface_examples_block/README.md) | A placed block as a resource whose plugin chooses its settings: variants. | `drush pm:install surface_examples_block` | `/admin/structure/block/surface/add` | [Variants](../resources.md#variants) |

## Showcase

Two drawings of the whole module, for a reader who already knows its
words. Open each file in a browser.

- [One resource, every door](../every-door/index.html): an interactive
  3D model of the layers a data surface is sealed from, a resource, its
  attached structures and an alter, the doors that read its contract,
  and the same inputs as they are written today.
- [Visuals](../visuals/index.html): seven small drawings, one
  relationship each: resource, structure and shape; who may ask; the
  layers; the markers; six ways to compose; the doors; the pipeline.
