# Data Surface Demo - Node type

A surface-driven alternative to core's content type add and edit form.

## What it shows

One surface serves both operations: the machine name carries a
service-backed uniqueness constraint when adding and is locked when
editing, and a composite target writes the node type config entity plus
the base field overrides that hold the title label and the three workflow
defaults.

Two things worth reading it for. Locking is the degenerate refinement —
the same declaration serves add and edit, with the operation as
build-time context rather than as a second surface. It is also the
worked example of the coordinate a surface is addressed by: the
operation `add` with no subject, or `edit` with the content type's
machine name as its subject, so the verb never carries the identity.
And a value set is separate from its storage: one surface writes a
config entity and a set of base field overrides through one composite
target, in an order that matters, because on add the bundle does not
exist while values are prepared.

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

| Route or link | Entity access | Module permission |
| --- | --- | --- |
| `data_surface_demo_node_type.add` | `_entity_create_access: node_type` | `administer data surface node type demo` |
| `data_surface_demo_node_type.edit` | `_entity_access: node_type.update` | `administer data surface node type demo` |
| The "Edit (surface)" operation link | `$node_type->access('update')` | `administer data surface node type demo` |

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

The operation link asks both questions before it is offered, so it never
appears where following it would be refused.

## What gates it

`Kernel\NodeTypeSurfaceTest` covers the add and edit surfaces, the lock
on edit, and the composite target's two destinations.

## Why it is a demo and not a replacement

It exists to show the two claims above in practice. The module is marked
`lifecycle: experimental` and is not meant to replace `node`'s own form
on a production site.
