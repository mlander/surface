# Surface API - Content entities

A content entity's create and update form, said as a resource, derived
from the bundle's fields: the example that pokes holes in the pattern.

## What it shows

Two derivers in `src/Resource/`, each a class carrying
`#[DerivesResources]`:

- `ContentEntityResources` makes `entity.content:<entity type>.<bundle>`
  for every content entity bundle with a stored default form display: a
  resource with its identity (`entity_type`, `bundle`, `id`), two
  operations, `create` and `update` (which takes the entity as `id`), one
  child per field the form display shows, in its order, the entity's own
  access and `Storage\ContentEntityStorage`.
- `FieldItemStructures` makes `field.item:<entity type>.<bundle>.<field>`
  for every field that resource carries: a structure, stored by its
  parent, derived from the item's typed data by `FieldItemDerivation`,
  or written by hand by a class marked
  `#[FieldItemStructure(type: '...')]`. Its access is field access:
  open, locked, or omitted.

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
| `/surface-react/entity.content:node.page/create` | The same, as the React app, with `surface_react` |

With `surface_tool` enabled, every bundle's resource is three tools:

```bash
drush tool:info surface:entity.content:node.page:create
drush tool:run surface:entity.content:node.page:create --uid=1 \
  --input='{"values":{"title":{"value":"Hello"},"field_description":{"value":"A page."}}}'
drush tool:run surface:entity.content:node.page:update:read --uid=1 --input=id=1
```

## Requirements

`node` and `surface_tool`, whose contract panel the two routes
show. Experimental, like every submodule.
