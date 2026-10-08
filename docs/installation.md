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

On its own the base module adds the surface model, the pipeline, the two
plugin types (widgets and options resolvers), the stock targets, and the
host adoption layer. It adds no surfaces, no routes and no permissions.
Surfaces come from the modules that declare them.

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
type's instance settings as a surface class, and lets Field UI render
the generated form. The address module itself is
not modified: the field type class is swapped for a subclass through
`hook_field_info_alter()`.

### Tool

```bash
composer require drupal/tool
drush pm:install data_surface_tool
```

Derives one [Tool](https://www.drupal.org/project/tool) API tool,
`data_surface:<surface>:<situation>`, for every situation that can be
asked on its own. Among them are the field tools,
`data_surface:field.instance:add`, `:reuse` and `:edit` and
`data_surface:field.storage:edit`, whose settings are derived from the
field type's surface, or from its config schema when it has none, so an
agent is offered typed, labeled, bounded settings instead of a
free-form map.

## Experimental submodules

Every submodule is marked `lifecycle: experimental`. They are where the
architectural decisions are visible in practice, and they double as the
fixtures the tests run against, so they are supported — but their APIs
and their configuration may change without a deprecation path, and none
of them is meant to replace core's own forms on a production site.

| Submodule | Shows |
| --- | --- |
| `data_surface_demo` | One surface driving three hosts: a block, a field formatter, and a standalone form. |
| `data_surface_demo_classic` | The same block and formatter written the pre-surface way, for the side-by-side comparison. Depends on nothing from Data Surface. |
| `data_surface_demo_extras` | A third-party module extending someone else's surfaces with surface alters, with no form alter; and, for content types, the same two settings added to core's own form the classic way, for comparison. |
| `data_surface_demo_node_type` | One surface serving an add form and an edit form through two situations, with a target of its own. Adds its own permission. |
| `data_surface_address` | A contributed field type adopting a surface without being forked. Needs Address. |
| `data_surface_tool` | The same surface serving a non-form caller. Needs Tool. |
| `data_surface_demo_node_type_tool` | A content type add tool whose input is the content type surface, compared with Tool Belt's bundle tool once another module has extended content types. Needs Tool. |
| `data_surface_examples` | One surface that grows, one idea per step, each step a form beside the contract it emits, at `/surface-examples`: declaring, refining, parts, and the same contract as a tool. Needs Tool. |
| `data_surface_examples_compliance` | Step 4 of the examples: one alter class that changes step 3's surface, and nothing else. |

Each submodule's own README says what it shows, how to try it, and which
tests gate it.

```bash
drush pm:install data_surface_demo data_surface_demo_extras data_surface_demo_classic
```

The examples run on a fresh site with nothing else, and are written to
be shown: start at `/surface-examples`, and enable the compliance module
when you reach step 4.

```bash
drush pm:install data_surface_examples
drush pm:install data_surface_examples_compliance
```

`data_surface_demo_node_type` also defines
`administer data surface node type demo`, which is granted *in addition
to* the content type permission rather than instead of it: holding it
alone opens nothing. See that submodule's README for the full matrix.

## Verify the install

Place the demo block, or visit the standalone demo form at
`/admin/config/development/data-surface-demo`, and change the entity type
select. The bundle and field selects rebuild over AJAX from the surface's
refinement map, which is the shortest proof that the whole chain is
wired.
