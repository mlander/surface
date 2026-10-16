# Data Surface - Content entities

A content entity's create and update form, said as a surface, derived
from the bundle's fields: the example that pokes holes in the pattern.

## What it shows

Two derivers in `src/Surface/`, each a class carrying
`#[DerivesSurfaces]`:

- `ContentEntitySurfaces` makes `entity.content:<entity type>.<bundle>`
  for every content entity bundle with a stored default form display:
  its identity (`entity_type`, `bundle`, `id`), two situations, `add`
  and `edit` (which takes the entity as `id`), one child per field the
  form display shows, in its order, the entity's own access and
  `Target\ContentEntityTarget`.
- `FieldItemSurfaces` makes `field.item:<entity type>.<bundle>.<field>`
  for every field that surface carries, derived from the item's typed
  data by `FieldItemDerivation`, or written by hand by a class marked
  `#[FieldItemSurface(type: '...')]`. Its access is field access: open,
  locked, or omitted.

Fields it cannot carry yet (more than one value, a type not covered,
computed ones) are left out, each with the reason, which the landing
page lists. `docs/entities.md` says what is derived from what, the
target, and every hole found in core's APIs on the way.

## How to try it

```bash
drush pm:install data_surface_entity
```

| Path | What |
| --- | --- |
| `/surface-entity` | Every content entity surface on the site, what it carries and what it leaves out |
| `/surface-entity/node/page/add` | Add a basic page through `entity.content:node.page` |
| `/surface-entity/node/{id}/edit` | Edit one |
| `/surface-react/entity.content:node.page/add` | The same, as the React app, with `data_surface_react` |

With `data_surface_tool` enabled, every bundle's surface is three tools:

```bash
drush tool:info data_surface:entity.content:node.page:add
drush tool:run data_surface:entity.content:node.page:add --uid=1 \
  --input='{"values":{"title":{"value":"Hello"},"field_description":{"value":"A page."}}}'
drush tool:run data_surface:entity.content:node.page:edit:read --uid=1 --input=id=1
```

## Requirements

`node` and `data_surface_tool`, whose contract panel the two routes
show. Experimental, like every submodule.
