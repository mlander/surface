# Installation

This page installs the module and each submodule, and says what each
submodule adds. To learn what the module does before you install it,
read [the introduction](introduction/index.md) first: nothing there
needs a site. [Doors](introduction/12-doors.md) says which submodule
opens which door.

## Requirements

- Drupal 11.3 or 12.
- PHP 8.3 or newer.
- [opis/json-schema](https://github.com/opis/json-schema) (Apache-2.0),
  which checks what a structure read from a JSON Schema keeps of it
  ([Reading a schema](reading-a-schema.md#what-is-kept)). Composer
  installs it with the module.

Beyond these, the base module has no dependencies and ships no
configuration of its own.

## Install the module

```bash
composer require drupal/surface
drush pm:install surface
```

On its own the base module adds the resource and structure model, the
pipeline, the two plugin types (widgets and options resolvers), the stock
storage classes, and the host adoption layer. It adds no resources or
structures, no routes and no permissions. Those come from the modules
that declare them. `drush generate surface:resource` writes a settings
page's files into one ([Generating a resource](generating.md)).

## In production: compile assertions out

Set `zend.assertions = -1` in the production `php.ini`:

```ini
zend.assertions = -1
```

The module checks, under `assert()`, what a declaration promises and
no type can say: a static refiner runs with the container guarded and
throws if it asks for a service (`Pipeline\PipelineGuard`),
the emitter asks each enumerated key's static refiners a second time
and refuses a different answer, and a static access answer is asked a
second time and held to the first
([Enforcing the kind](refinement.md#enforcing-the-kind)). These are
development and test checks. PHP's own default for `zend.assertions`
is `1`, and a host or container image that ships no `php.ini` (the
official `php` images among them) runs with it, so on such a host
every one of them runs in production too, and a refiner that reaches
for a service throws there, for a visitor, instead of in development.
With `-1` the calls are not compiled at all and cost nothing; `0`
compiles them and skips them at run time.

What they cost when they run, measured on this module's examples
(PHP 8.4, 2026-10-20): a build costs the same, a full schema up to
about 11% more (example 3 about 11%, the field instance resource about
1%, a basic page's nothing measurable), and each static access answer
is asked twice on its first use in a request. Drupal core's own development checks are
`assert()` calls too, which is the other reason core recommends `-1` in
production.

## Optional integrations

The base module opens the Tool API door when the Tool module is
installed, and one submodule bridges to the Address module. Both are
declared as Composer `suggest` entries rather than requirements, so the
base module installs without them.

### Address

```bash
composer require drupal/address
drush pm:install surface_examples_address
```

Describes the [Address](https://www.drupal.org/project/address) field
type's instance settings as a resource class, and lets Field UI render
the generated form. The address module itself is
not modified: the field type class is swapped for a subclass through
`hook_field_info_alter()`.

### Tool

```bash
composer require drupal/tool
drush pm:install tool
```

With the [Tool](https://www.drupal.org/project/tool) module installed,
the base module derives one Tool API tool, `surface:<id>:<operation>`,
for every operation that can be asked on its own, with a read tool and
a narrowing tool beside it where they apply
([The Tool API door](tools.md)). No submodule is needed: the base
module registers the door's services only when the tool module is
installed. With `surface_examples_field` installed too, the tools include
the field tools, `surface:field.instance:create`, `:reuse` and `:update`
and `surface:field.storage:update`, whose settings are derived from the
field type's own settings class, or from its config schema when it has
none, so an agent is offered typed, labeled, bounded settings instead of
a free-form map.

## Experimental submodules

Every submodule is marked `lifecycle: experimental`. They are where the
architectural decisions are visible in practice, and they double as the
fixtures the tests run against, so they are supported, but their APIs
and their configuration may change without a deprecation path, and none
of them is meant to replace core's own forms on a production site.

Each row is one submodule: its install command, what it shows, and what
it opens on a site. A submodule that needs a contributed module needs
its `composer require` first, as under
[Optional integrations](#optional-integrations). What each example
teaches, in reading order, is in [Examples](examples/index.md).

| Install | Shows | Opens |
| --- | --- | --- |
| `drush pm:install surface_examples_first` | The code of [Your first resource](first-resource.md) and [Your second resource](second-resource.md): a newsletter settings page and a store location, held to the pages by a test. Adds its own permission. | `/admin/config/services/surface-newsletter`, `/admin/config/services/surface-store` |
| `drush pm:install surface_examples_performance` | Three settings of core's performance form, the page cache maximum age and asset aggregation, as one resource stored in core's `system.performance`, each key at core's path, held to core's config schema unchanged: the title, the description and the list of periods are declared once and read by the form, the tool and both documents. Uses core's "administer site configuration". | `/admin/config/development/surface-performance` |
| `drush pm:install surface_examples_plugins` | Two structures and three hosts, in the two spellings: `DemoBlockStructure`, a class of its own, drives a block and a standalone form, and the field formatter is its own structure. | `/admin/config/development/surface-example-form`; the block and the formatter where you place them |
| `drush pm:install surface_examples_classic` | The same block and the same formatter written the classic way, by hand, without Surface API, with a parity test holding the two to the same behavior and a README counting what each costs. Depends on nothing from Surface API. | The classic block and formatter, for the side-by-side comparison |
| `drush pm:install surface_examples_extras` | A third-party module extending someone else's resources and structures through alters, with no form alter anywhere; and, for content types, the same two settings added to core's own form the classic way, so the two can be compared. | The demo's formatter settings and core's content type form, extended |
| `drush pm:install surface_examples_node_type` | One resource serving a create form and an update form through two operations, with storage of its own, and two routes that name the generic operation form rather than a form class. Adds its own permission ([below](#the-node-type-demo-permission)). | `/admin/structure/types/surface-add`, `/admin/structure/types/manage/{type}/surface-edit` |
| `drush pm:install surface_examples_address` | A contributed field type adopting a resource for its settings without being forked. Needs [Address](#address). | Field UI's settings form for an address field |
| `drush pm:install surface_examples_field` | Core's field instance and field storage settings as resources, `field.instance` and `field.storage`, each field type's settings a variant read from its settings class or its config schema; with [Tool](#tool), the field tools, and a generated comparison of them against Tool Belt's. | The field tools, for an agent, MCP, ECA and Drush |
| `drush pm:install surface_examples_entity` | A resource derived for every content entity bundle with a form display, one structure per field derived from its typed data, field access as each child's access, and storage that validates the entity; a basic page added and edited through it. Needs `node`; see [Content entities](entities.md). | `/surface-entity` |
| `drush pm:install surface_examples_node_type_tool` | No code of its own, only the generated comparison between `surface:node.type:create`, the tool derived from the content type resource, and Tool Belt's bundle tool and core's form, once another module has extended content types. Needs Tool. | No page: the recorded comparison and its test, in its README |
| `drush pm:install surface_examples` | One resource that grows, one idea per example, each example a form beside the contract it emits, then a small resource per later concept; examples 16 and 17 are in submodules of their own. Needs Tool. | `/surface-examples` |
| `drush pm:install surface_examples_compliance` | Example 4: a compliance structure and the one alter that attaches it to example 3's resource, which does not know it, and nothing else. | `/surface-examples/4` |
| `drush pm:install surface_examples_workflow` | Example 10, extended: two alters that answer access for the program and its fees, which do not know them, with no change to the program: the fees left out for an account that may not see them, and the program fixed once its workflow publishes it. | `/surface-examples/10` |
| `drush pm:install surface_examples_keys` | Example 14: a module that manages keys, standing in for the Key module: a list of named keys, the constraint and resolver that offer them, and an alter that offers one on a resource that takes a pasted key, ruling the pasted key out once one is chosen. | `/surface-examples/14`, `/surface-examples/14/webhook` |
| `drush pm:install surface_examples_references` | Example 16: keys that refer to an entity, declared by the data type string of what they refer to: a venue, a user, an article and an entity of any type, listed while the site has few and looked up past that. Needs node; ships the article and page bundles for a site that has neither, and writes three nodes its calls name. | `/surface-examples/16` |
| `drush pm:install surface_examples_retitle` | Example 17: a bulk retitle of nodes with keys, storage and a permission of its own, which borrows each node's update access and its title's edit access as gates, asked per node, a refusal on the node's own item. Needs node; ships the page bundle for a site that has none, two roles, and writes three pages its calls name. | `/surface-examples/17` |
| `drush pm:install surface_examples_component_block` | Example 13: a block, "Component", that embeds a single-directory component: the component chosen from those the site has and its props read from the schema it declares, example 12's two lines named by `#[UsesStructure]`, so the block's form, validation and storage come from them, placed through the placement resource of `surface_examples_block`. Needs `block`. | `/surface-examples/13` |
| `drush pm:install surface_examples_block` | A placed block as a resource, `block.placement`, by its machine name: its plugin chooses its settings (a plugin's declared structure, or its config schema read), its theme its region, and its visibility is a list of conditions. Placed, configured, read and removed through four tools, HTTP (`DELETE` removes) and a form. Needs `block`. | `/admin/structure/block/surface/add` |
| `drush pm:install surface_react` | The served contract: an operation as JSON Schema 2020-12 with an `x-surface` extension, served over HTTP, the method its operation (`GET` reads, `PATCH` updates, `POST` creates, `DELETE` removes), with a narrowing that re-narrows it as answers change and a dry run that rehearses the write, and a React app that renders the form from it. Its built app is committed, so it needs no Node. | `/surface-api/{resource}`, `/surface-react/{resource}/{operation}` |
| `drush pm:install surface_openapi` | An OpenAPI 3.1 document of every operation's paths (and `.yaml`), each operation's full schema as components, built for no account so it is the same for everyone, for any OpenAPI client, generator or UI. Needs `surface_react`. | `/surface-api/openapi.json`, Swagger UI at `/surface-api/docs`, Redoc at `/surface-api/redoc` |

Each submodule's own README says what it shows, how to try it, and which
tests gate it.

### The node type demo permission

`surface_examples_node_type` also defines
`administer data surface node type demo`, which is granted *in addition
to* the content type permission rather than instead of it: holding it
alone opens nothing. See that submodule's README for the full matrix.

## Verify the install

Install the demo modules:

```bash
drush pm:install surface_examples_plugins surface_examples_extras surface_examples_classic
```

Place the demo block, or visit the standalone demo form at
`/admin/config/development/surface-example-form`, and change the entity type
select. The bundle and field selects rebuild over AJAX from the structure's
refinement map, which is the shortest proof that the whole chain is
wired.

`drush surface:catalog` lists every resource and structure the site's
modules declare, with the doors each operation reaches, and `drush
surface:catalog --refused` what did not build, with the file to open
([The site's catalog](catalog-command.md)). The same listing, each door
linked, is the page at `/admin/config/development/surfaces`, for a role
with "View the data surface catalog" ([the catalog page](catalog-command.md#the-catalog-page)). To hold your own resources
to the invariants from your module's tests, see [Testing a resource](testing-a-resource.md).

The examples, in the order they are written to be shown and with the
submodules each one needs, are in [Examples](examples/index.md).
