# Installation

## Requirements

- Drupal 11.3 or 12.
- PHP 8.3 or newer.

The base module has no dependencies beyond Drupal core and ships no
configuration of its own.

## Install the module

```bash
composer require drupal/data_surface
drush pm:install data_surface
```

On its own the base module adds the resource and structure model, the
pipeline, the two plugin types (widgets and options resolvers), the stock
storage classes, and the host adoption layer. It adds no resources or
structures, no routes and no permissions. Those come from the modules
that declare them.

## In production: compile assertions out

Set `zend.assertions = -1` in the production `php.ini`:

```ini
zend.assertions = -1
```

The module checks, under `assert()`, what a declaration promises and
no type can say: a static refiner runs with the container guarded and
throws if it asks for a service (`Refinement\StaticRefinerContainer`),
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

Two of the submodules bridge to a contributed module. Both are declared
as Composer `suggest` entries rather than requirements, so the base
module installs without them.

### Address

```bash
composer require drupal/address
drush pm:install data_surface_address
```

Describes the [Address](https://www.drupal.org/project/address) field
type's instance settings as a resource class, and lets Field UI render
the generated form. The address module itself is
not modified: the field type class is swapped for a subclass through
`hook_field_info_alter()`.

### Tool

```bash
composer require drupal/tool
drush pm:install data_surface_tool
```

Derives one [Tool](https://www.drupal.org/project/tool) API tool,
`data_surface:<id>:<operation>`, for every operation that can be
asked on its own. Among them are the field tools,
`data_surface:field.instance:add`, `:reuse` and `:edit` and
`data_surface:field.storage:edit`, whose settings are derived from the
field type's own settings class, or from its config schema when it has
none, so an agent is offered typed, labeled, bounded settings instead of
a free-form map.

## Experimental submodules

Every submodule is marked `lifecycle: experimental`. They are where the
architectural decisions are visible in practice, and they double as the
fixtures the tests run against, so they are supported, but their APIs
and their configuration may change without a deprecation path, and none
of them is meant to replace core's own forms on a production site.

| Submodule | Shows |
| --- | --- |
| `data_surface_first` | The code of [Your first resource](first-resource.md) and [Your second resource](second-resource.md): a newsletter settings page and a store location, held to the pages by a test. Adds its own permission. |
| `data_surface_demo` | Two structures and three hosts: `DemoBlockStructure` drives a block and a standalone form, and the field formatter is its own structure. |
| `data_surface_demo_classic` | The same block and formatter written the usual way, without Data Surface, for the side-by-side comparison. Depends on nothing from Data Surface. |
| `data_surface_demo_extras` | A third-party module extending someone else's resources and structures with alters, with no form alter; and, for content types, the same two settings added to core's own form the classic way, for comparison. |
| `data_surface_demo_node_type` | One resource serving an add form and an edit form through two operations, with storage of its own. Adds its own permission. |
| `data_surface_address` | A contributed field type adopting a resource without being forked. Needs Address. |
| `data_surface_tool` | The same resources serving a non-form caller. Needs Tool. |
| `data_surface_entity` | A resource derived for every content entity bundle with a form display, one structure per field, and a basic page added and edited through it at `/surface-entity`. Needs `node` and Tool; see [Content entities](entities.md). |
| `data_surface_demo_node_type_tool` | A content type add tool whose input is the content type resource, compared with Tool Belt's bundle tool once another module has extended content types. Needs Tool. |
| `data_surface_examples` | One resource that grows, one idea per example, each example a form beside the contract it emits, at `/surface-examples`: declaring, refining, parts, and the same contract as a tool. Needs Tool. |
| `data_surface_examples_compliance` | Example 4: a compliance structure, and one alter that attaches it to example 3's resource, and nothing else. |
| `data_surface_examples_workflow` | Example 10, extended: two alters that answer access for the program and its fees, with no change to the program, and nothing else. |
| `data_surface_react` | The served contract: an operation as JSON Schema with refine and validate endpoints, and a React form rendered from it at `/surface-react/{resource}/{operation}`. Its built app is committed, so it needs no Node. |

Each submodule's own README says what it shows, how to try it, and which
tests gate it.

```bash
drush pm:install data_surface_demo data_surface_demo_extras data_surface_demo_classic
```

The examples run on a fresh site with nothing else, and are written to
be shown: start at `/surface-examples`, enable the compliance module
when you reach example 4, and the workflow module once example 10 has
been shown without it.

```bash
drush pm:install data_surface_examples
drush pm:install data_surface_examples_compliance
drush pm:install data_surface_examples_workflow
```

With `data_surface_react` enabled as well, the landing page links each
of examples 1 to 3 in React, rendered from the contract it serves:

```bash
drush pm:install data_surface_react
```

`data_surface_demo_node_type` also defines
`administer data surface node type demo`, which is granted *in addition
to* the content type permission rather than instead of it: holding it
alone opens nothing. See that submodule's README for the full matrix.

## Verify the install

Place the demo block, or visit the standalone demo form at
`/admin/config/development/data-surface-demo`, and change the entity type
select. The bundle and field selects rebuild over AJAX from the structure's
refinement map, which is the shortest proof that the whole chain is
wired.
