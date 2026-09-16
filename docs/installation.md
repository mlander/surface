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
type's instance settings as a surface declared on the field item class,
and lets Field UI render the generated form. The address module itself is
not modified: the field type class is swapped for a subclass through
`hook_field_info_alter()`.

### Tool

```bash
composer require drupal/tool
drush pm:install data_surface_tool
```

Adds two field instance tools to the
[Tool](https://www.drupal.org/project/tool) API whose `settings` input is
derived from the field type's surface, so an agent is offered typed,
labeled, bounded settings instead of a free-form map.

## Experimental submodules

Every submodule is marked `lifecycle: experimental`. They are where the
architectural decisions are visible in practice, and they double as the
fixtures the tests run against, so they are supported — but their APIs
and their configuration may change without a deprecation path, and none
of them is meant to replace core's own forms on a production site.

| Submodule | Shows |
| --- | --- |
| `data_surface_demo` | One surface driving three hosts: a block, a field formatter, and a standalone form. |
| `data_surface_demo_extras` | A third-party module extending someone else's surface through the build event, with no form alter. |
| `data_surface_demo_node_type` | One surface serving an add form and an edit form, with a composite target. Adds its own permission. |
| `data_surface_address` | A contributed field type adopting a surface without being forked. Needs Address. |
| `data_surface_tool` | The same surface serving a non-form caller. Needs Tool. |

Each submodule's own README says what it shows, how to try it, and which
tests gate it.

```bash
drush pm:install data_surface_demo data_surface_demo_extras
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
