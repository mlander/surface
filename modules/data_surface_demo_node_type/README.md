# Data Surface Demo - Node type

A surface-driven alternative to core's content type add and edit form.

## What it shows

One surface, `Surface\NodeTypeSurface` (`node.type`), with two
situations: `add()` knows nothing, so the machine name is open and
carries a service-backed uniqueness constraint the situation adds;
`edit(NodeTypeInterface $type)` knows the machine name, so it is
locked — still advertised, narrowed to the one value it has. Add and
edit are not two shapes, they are how much is known.

`Target\NodeTypeTarget` loads by the machine name the context knows and
creates or updates by whether it creates. It writes the node type
config entity, and the base field overrides that hold the title label
and the three workflow defaults, in the order core's own form writes
them and only where a value moved. The overrides are read, planned and
written by the engine's `BaseFieldOverrideTarget`, through its
`values()`, `plan()` and `write()`. `Access\NodeTypeAccess` is what the
situation's permission cannot say: the node type entity's own answer.

There is no form class in this module, and no tool. Both routes name
`Drupal\data_surface\Form\DataSurfaceSituationForm` with the surface
class and a situation; the edit route's `{type}` is the edit
situation's `$type`. What is left of a form here is
`NodeTypeSurfaceFormCosmetics`: core's vertical tabs, the machine name's
mirror-while-typing, the message and the redirect, which is the part
that should stay bespoke. With `data_surface_tool` enabled, both
situations are tools, `data_surface:node.type:add` and
`data_surface:node.type:edit`, generated from the surface.

## How to try it

```bash
drush pm:install data_surface_demo_node_type
drush role:perm:add content_editor 'administer data surface node type demo'
```

| Path | Operation |
| --- | --- |
| `/admin/structure/types/surface-add` | Add a content type |
| `/admin/structure/types/manage/{node_type}/surface-edit` | Edit one |

The edit form is also offered as an "Edit (surface)" operation on the
content type listing, beside core's own, so the two forms are one click
apart to compare.

## Who may use it

This module is a second way into writing content types, so it is gated
twice and both answers have to allow. There is no path here that a
reviewer of core's own content type form has not already reviewed.

| Route, link or tool | Entity access (the access class) | Module permission (the situation's) |
| --- | --- | --- |
| `data_surface_demo_node_type.add`, `data_surface:node.type:add` | node type create access | `administer data surface node type demo` |
| `data_surface_demo_node_type.edit`, `data_surface:node.type:edit` | `$node_type->access('update')` | `administer data surface node type demo` |
| The "Edit (surface)" operation link | `$node_type->access('update')` | `administer data surface node type demo` |

All of them ask one question, the content type surface's access for
the situation, so none can drift from the others: the routes through
the `_data_surface_situation_access` requirement, the form again on its
way in and out, the link before it is offered, and the tool before it
writes.

**Entity access** is what decides whether these values may be written at
all. A content type is a content type however it is edited, so the answer
comes from the `node_type` entity type's own access handler, which is
core's `administer content types` permission plus whatever any module
says through the entity access hooks. This module can therefore never
grant more than core's form grants.

**`administer data surface node type demo`** decides whether this second
way in is open on this site at all. It is marked `restrict access: true`
and it is granted *in addition to*, never instead of, the content type
permission: holding it alone opens nothing, because entity access still
refuses. That is the point of it — installing a demo module should not
silently hand everyone who already administers content types a second,
separately written form to do it through.

The operation link asks the same question before it is offered, so it
never appears where following it would be refused.

## What gates it

`Kernel\NodeTypeSurfaceTest` covers both situations and their locks,
the alter from the extras module, the target creating and updating, a
dry run, access and the operation link. `Kernel\DataSurfaceSituationFormTest`
drives the generic situation form, message and redirect included, and
`Functional\NodeTypeSurfaceFormTest` drives both routes in a browser,
with the routes' 403s.

## Why it is a demo and not a replacement

It exists to show the two claims above in practice. The module is marked
`lifecycle: experimental` and is not meant to replace `node`'s own form
on a production site.
