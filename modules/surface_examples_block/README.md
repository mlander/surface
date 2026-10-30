# Surface API block placement

A placed block as a resource: `block.placement`, the block config entity
`block.block.<id>` by its machine name, whose plugin chooses its
settings. An agent, a script or a person places a block, configures it,
moves it within its theme and removes it through a tool, HTTP or a form,
each validated as any resource is.

```bash
drush pm:install surface_examples_block
```

It needs `block` and nothing contributed. The tools need
`surface_tool`, the HTTP door `surface_react`.

## What it shows

### The resource: `block.placement`

`Resource\BlockPlacementResource` declares the keys every block has
(`label`, `id`, `label_display`, `plugin`, `theme`, `region`, `weight`,
`status`), the plugin's settings and the visibility:

```php
$inputs->attachVariants('settings', by: 'plugin', from: BlockPluginStructures::class)
  ->setLabel(t('Settings'));
// ...
$inputs->attachList('visibility', VisibilityConditionStructure::class)
  ->setLabel(t('Visibility'));
```

- **The plugin chooses the settings.** `Variants\BlockPluginStructures`
  lists the plugins the block layout offers, less any plugin that reads
  a context, which is not offered yet. A plugin whose class names its
  settings with `#[UsesStructure]` (the component block, the demo block)
  is that structure, built as declared. Any other is read from its
  config schema, `block.settings.<plugin id>`, less the keys every block
  has, written as JSON Schema by `Contract\ConfigSchemaDocument` and
  read by the schema reader, the plugin's own defaults its defaults.
- **The theme chooses the region.** The theme is one of the installed
  themes (core's `ExtensionExists`, read by the main module's
  `extension_exists` resolver); the region, a visible region of any
  installed theme (`SurfaceAnyThemeRegion`) until the theme narrows
  it to that theme's (`SurfaceThemeRegion`, added by a refiner on
  the theme), both cached by `config:core.extension`. The full schema
  enumerates the region per theme.
- **The visibility is a list of conditions.** Each item is a condition
  plugin, whether it is negated, and its settings, chosen by the
  condition the same way (`Variants\ConditionPluginStructures`), with a
  `context_mapping` for a condition that reads a context, its default
  the context the block layout assigns.
- **Three operations.** `create` places a block under a machine name the
  caller types, refused when it is in use; `update` changes one, its theme
  held to the one it is in; `delete` removes it, taking no values
  (`#[Operation(deletes: TRUE, destructive: TRUE)]`). Each asks
  "administer blocks".

`Storage\BlockPlacementStorage` extends the main module's
`ConfigEntityStorage`: the entity's own properties by name, and the label,
the plugin's settings and the visibility shaped onto the block's
settings and visibility as core stores them. It holds the whole block to
`block.block.*` before anything is written, calculates the dependencies a
save would, and files each violation under the key that owns it, a
condition's by its place in the list.

Not built yet: a plugin that declares a context is not offered; an
update keeps a block in the theme it is in, so a block cannot move
between themes; Layout Builder's components are not placements; and a
create without JavaScript draws a plugin's settings only once the
plugin is chosen
(why: [decision](../../docs/decisions.md#a-placed-block-is-a-resource)).

### The doors

| Door | Where |
| --- | --- |
| Tools | `surface:block.placement:create` (`values`, `dry_run`), `:update` (`id`, `values`, `dry_run`), `:update:read` (`id`), `:delete` (`id`, `dry_run`), with `surface_tool`; and `:create:schema` and `:update:schema`, since a plugin's settings are variants the site lists, which a tool's listing never spells out ([the narrowing tool](../surface_tool/README.md#the-narrowing-tool)) |
| HTTP | `POST /surface-api/block.placement` (201, `Location`), `GET` and `PATCH /surface-api/block.placement/{id}`, `DELETE /surface-api/block.placement/{id}`, with `surface_react` |
| Forms | `/admin/structure/block/surface/add`, `/admin/structure/block/surface/{id}`, and `/admin/structure/block/surface/{id}/delete`, a confirmation |
| React | `/surface-react/block.placement/create`, `/surface-react/block.placement/update?id={id}` |

Place a hero in Olivero's sidebar, rehearsed first:

```bash
drush tool:run surface:block.placement:create --uid=1 --input='{"values":{"label":"Doors","id":"doors_hero","plugin":"surface_component","settings":{"component":"surface_examples:hero","props":{"title":"Doors open at half past six","align":"start"}},"theme":"olivero","region":"sidebar"}}' --input=dry_run=true
```

A region the theme lacks is refused by name ("The olivero theme has no
region named header_nope."), props of another component by their path
(`/settings/props/heading`), and a machine name in use by its key
(`/id`, code `taken`). Remove it with
`drush tool:run surface:block.placement:delete --uid=1 --input='{"id":"doors_hero"}'`.

## How to try it

| Where | What to look at |
| --- | --- |
| `/admin/structure/block/surface/add` | Choose a plugin and its settings are drawn; choose a theme and its regions are offered. |
| `/surface-examples/13`, with `surface_examples` and `surface_examples_component_block` | The Spotlight block, placed through `create`, and the calls that place, change and remove it. |
| `/surface-api/docs`, with `surface_openapi` | The placement's paths, `DELETE` among them. |

## Tests

| Test | Covers |
| --- | --- |
| `Kernel\BlockPlacementResourceTest` | The keys and what each offers; the plugins less those reading a context; the region narrowed by the theme, with its cacheability; the settings variants, declared and read from a config schema; the visibility conditions with their context mapping; the theme held on an update; both documents pinned whole and in part. |
| `Kernel\BlockPlacementToolsTest` | The four tools, their inputs and kinds; create as a dry run and for real, a taken machine name; a region of another theme, props of another component and a condition twice refused; update and read; delete as a dry run and for real. |
| `Kernel\ConfigEntityStorageTest`, `Unit\ConfigEntityStorageTest` | The storage it extends. |
| `Functional\BlockPlacementApiTest` | POST 201 with `Location`, GET with `ETag`, PATCH and a stale `If-Match`, a refusal as problem details, DELETE rehearsed and done, a body refused, 404 once gone, 405 on the collection, the session's token. |
| `Functional\BlockPlacementFormTest` | A block placed through the form and seen rendered, a region of another theme refused, edited, removed after a confirmation. |
| `FunctionalJavascript\BlockPlacementReactTest` | The React page: a plugin's settings and a theme's regions drawn as they are chosen, a block placed, the page moved to its update, a change saved. |
| `Kernel\ContractBaselineTest` | Both declarations' full schemas against their baselines. |
| `Kernel\Matrix\AdvertisedSchemaAcceptanceTest` | Documents the pipeline accepts and refuses, against both schemas. |
| `Kernel\OpenApiDocumentTest` | The placement's `DELETE`, with no body, and its facts. |
