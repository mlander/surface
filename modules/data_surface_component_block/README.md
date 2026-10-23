# Data Surface component block

A block, "Component", that embeds a single-directory component: the
component chosen from those the site has, and its props read from the
JSON Schema the component declares. Its settings are a structure of two
keys, and a placed one is placed, configured and removed through the
general placement resource of `data_surface_block`.

```bash
drush pm:install data_surface_component_block
```

It needs `block` and nothing contributed. Example 13 of
`data_surface_examples`, at `/surface-examples/13`, shows it beside
example 12, whose two lines its settings are, with `data_surface_block`
on too.

## What it shows

### The block: `data_surface_component`

The plugin, `Plugin\Block\ComponentBlock`, names its settings with
`#[UsesStructure(ComponentBlockStructure::class)]` on
`DataSurfaceBlockBase` and renders. The block host builds the structure,
draws its settings form, holds what is submitted to it and stores it in
the block's settings; the plugin writes no `defaultConfiguration()`,
`blockForm()`, `blockValidate()` or `blockSubmit()`. `build()` returns
`#type => 'component'` with the component and the props it holds, less a
prop that holds nothing (stored as null, which core's check of the props
refuses for a prop whose type has no null), with the cacheability of the
component list. A block with no component, or with one the site no
longer has, renders nothing. The block depends on the module or theme
that provides its component, so it is removed with it.

### The structure: `block.data_surface_component`

`Structure\ComponentBlockStructure` is example 12's two lines under the
block's id:

```php
$inputs->add('component', 'string', t('Component'));
$inputs->attachVariants('props', by: 'component', from: ComponentProps::class)
  ->setLabel(t('Props'));
```

`ComponentProps` is the main module's provider
(`Drupal\data_surface\Variants\ComponentProps`), which example 12 uses
too: one variant per component the site's modules and themes provide,
read from its props schema
([Variants from the site](../../docs/resources.md#variants-from-the-site)).
Choosing a component on the block form draws its props below it over
AJAX. The component is not required: the host builds the block from the
declared defaults before anything is chosen, and no component is one
every site has. The config schema,
`config/schema/data_surface_component_block.schema.yml`, holds the
component as a string and the props as `type: ignore`, since which
components a site has is the site's; the chosen component's variant
holds the props to its own keys before anything is written.

### A placed block, through the placement resource

A plugin's structure is held by its host and is no tool. A placed block
is a config entity, and `data_surface_block` declares it as a resource,
`block.placement`, whose plugin chooses its settings: for this plugin,
this structure, built as declared, the components its variants in turn
([its README](../data_surface_block/README.md)). With both modules on
and `data_surface_tool`, an agent places a component block, chooses its
component and props, rehearses the change, moves it within its theme and
removes it, and is refused, by path, props of another component or out
of their bounds:

```bash
drush tool:run data_surface:block.placement:edit:read --uid=1 --input='{"id":"data_surface_examples_spotlight"}'
```

This module needs no resource of its own for it, and does not depend on
`data_surface_block`: until 2026-10-23 it declared a stop-gap,
`block.placed_component`, which the general resource replaced.

What was decided, and what is open (the component's slots, the add form
without JavaScript): [A block embeds a component through its
structure](../../docs/decisions.md#a-block-embeds-a-component-through-its-structure),
and [A placed block is a
resource](../../docs/decisions.md#a-placed-block-is-a-resource).

## How to try it

| Where | What to look at |
| --- | --- |
| `/admin/structure/block`, "Place block", "Component" | Choose a component and its props are drawn below it; save, and the block renders it. |
| `/surface-examples/13`, with `data_surface_examples` and `data_surface_block` on | The block beside example 12, its contract, the placement's tools, and the Spotlight block the examples place there through them. |

## Tests

| Test | Covers |
| --- | --- |
| `Kernel\ComponentBlockTest` | The structure: two keys, no component required, one variant per component the site has; both documents pinned whole for the three components the examples ship; the plugin's structure is no tool; the render array, the props that hold nothing left out, nothing for no component or one the site lacks, and the dependency on the component's module. |
| `Kernel\BlockPlacementToolsTest` | A component block placed, its props changed, read and removed through the placement resource's tools, props of another component refused by path. |
| `Kernel\ContractBaselineTest` | The structure's full schema against its baseline. |
| `Functional\ComponentBlockPlacementTest` | The block form without JavaScript: the components offered, a block with no component placed and rendering nothing, a component chosen and saved at once refused for the props it requires, and a placed block's form drawing its component's props, saving a change, refusing an out of bounds one and rendering. |
| `FunctionalJavascript\ComponentBlockAjaxTest` | The block form in a browser: choosing a component draws its props over AJAX, another swaps them, and the block saved renders the card with the props it holds. |
