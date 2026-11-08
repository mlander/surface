# Surface API examples: content entities

One of the examples of [the pattern on Drupal's own things](../../README.md#the-examples).
These examples apply the pattern to things Drupal and a contrib module
already have, to test it across scenarios; they are not part of the
project and will not ship in this form.

A content entity's create and update form, said as a resource, derived
from the bundle's fields: the example that pokes holes in the pattern.

## What it shows

Two derivers in `src/Resource/`, each a class carrying
`#[DerivesResources]`:

- `ContentEntityResources` makes `entity.content:<entity type>.<bundle>`
  for every content entity bundle with a stored default form display: a
  resource with its identity (`entity_type`, `bundle`, `id`), four
  operations, `create`, `update`, `read` and `delete` (each of the last
  three takes the entity as `id`), one child per field the form display
  shows, in its order, the entity's own access and
  `Storage\ContentEntityStorage`. Its `read` asks view access, of the
  entity and of each field, so an account that may view a page and not
  edit it reads it; its `delete` asks delete access, and removes the
  entity with every translation and revision it has.
- The same deriver makes `entity.content:<entity type>` for every
  content entity type with such a bundle: one resource for all its
  bundles, identity `entity_type` and `id`, `bundle` a key that chooses
  the fields at `fields`, one variant per bundle
  (`entity.content:<entity type>.<bundle>.fields`, a structure holding
  the same field children). On `create` the bundle is one the account
  may create; on `update`, `read` and `delete` it is the entity's. A
  test holds each bundle's variant equal to that bundle's resource.
- `FieldItemStructures` makes `field.item:<entity type>.<bundle>.<field>`
  for every field that resource carries: a structure, stored by its
  parent, derived from the item's typed data by `FieldItemDerivation`,
  or written by hand by a class marked
  `#[FieldItemStructure(type: '...')]`. Its access is field access:
  open, locked, or omitted; on a read, open or omitted.

Fields it cannot carry yet (more than one value, a type not covered,
computed ones) are left out, each with the reason, which the landing
page lists. `docs/entities.md` says what is derived from what, the
storage, and every hole found in core's APIs on the way.

## How to try it

```bash
drush pm:install surface_examples_entity
```

| Path | What |
| --- | --- |
| `/surface-entity` | Every content entity resource on the site, what it carries and what it leaves out |
| `/surface-entity/node/page/add` | Add a basic page through `entity.content:node.page` |
| `/surface-entity/node/{id}/edit` | Edit one |
| `/surface-entity/node/{id}/delete` | Delete one, after a confirmation; then back to `/surface-entity`, since the id names nothing any more |
| `/surface-entity/delete` | Confirm the deletes the content list's bulk action staged ([below](#bulk-delete-from-the-content-list)) |
| `/surface-react/entity.content:node.page/create` | The same, as the React app, with `surface_examples_react` |

With the `tool` module installed, every bundle's resource is four tools,
and every entity type's six (its two writes that take values each with
a narrowing tool, `:schema`):

```bash
drush tool:info surface:entity.content:node.page:create
drush tool:run surface:entity.content:node.page:create --uid=1 \
  --input='{"values":{"title":{"value":"Hello"},"field_description":{"value":"A page."}}}'
drush tool:run surface:entity.content:node.page:read --uid=1 --input=id=1
drush tool:run surface:entity.content:node:create --uid=1 \
  --input='{"values":{"bundle":"page","fields":{"title":{"value":"Hello"},"field_description":{"value":"A page."}}}}'
drush tool:run surface:entity.content:node.page:delete --uid=1 --input='{"id":<nid>,"dry_run":true}'
drush tool:run surface:entity.content:node.page:delete --uid=1 --input=id=<nid>
```

A delete takes `stage` as every write does, and is then kept in the
stage until it is applied (`docs/staging.md`).

The entity type's resource holds the fields under `fields`. Both kinds
are at every door; `docs/entities.md` says which to call when.

## Bulk delete from the content list

The module ships one action, "Delete content through a stage"
(`system.action.surface_entity_stage_delete`, the plugin
`Plugin\Action\StageDeleteAction`), which the bulk form of the content
list, `/admin/content`, offers beside core's "Delete content":

1. Select the items and apply the action. It asks each item's delete
   access through `entity.content:node`, opens a stage labeled with the
   count of the items allowed, stages one delete per item, and keeps
   the stage's id and the staged ids in the private temp store. Nothing
   is removed. Two rows of one item, its translations among them, are
   one item.
2. The bulk form sends you to `/surface-entity/delete`
   (`Form\StageDeleteConfirmForm`), which lists the selected items as
   they are stored and names the stage.
3. Delete applies the stage: each delete is checked again (its access,
   and that the item did not change since it was staged) and all are
   made in one transaction, or none. A refusal is shown one line per
   write, `/writes/<position>`, and the stage is kept. After a
   conflict, select the items again. Cancel leaves the stage to expire.

A limit of the example: a translation's row stages the removal of the
whole node with every translation, as the storage deletes
(`docs/entities.md`, The storage), where core's own "Delete content"
removes the one translation.

`docs/staging.md` has the flow, and `docs/decisions.md` why it stages.
On a site where the module was installed before the action existed,
reinstall the module to get the action.

## Requirements

`node`. The create and update routes show the base module's contract
panel, with the tool's schema where the `tool` module is installed; the
delete route draws a confirmation. The bulk action needs the content
list, core's `views` module. Experimental, like every submodule.
