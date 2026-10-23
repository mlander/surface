# Data Surface component block

A block, "Component", that embeds a single-directory component: the
component chosen from those the site has, and its props read from the
JSON Schema the component declares. Its settings are a structure of two
keys, and a placed one is configured through a tool.

```bash
drush pm:install data_surface_component_block
```

It needs `block` and nothing contributed. Example 13 of
`data_surface_examples`, at `/surface-examples/13`, shows it beside
example 12, whose two lines its settings are.

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

### A placed block, through a tool

A plugin's structure is held by its host and is no tool. A placed block
is a config entity, with a machine name and storage of its own, so the
module declares a resource beside the plugin,
`Resource\PlacedComponentBlockResource`, `block.placed_component`: its
identity is the block's machine name, `block`, its storage the block
config entity (`Storage\PlacedComponentBlockStorage`, which keeps the
host's own keys as stored), and it attaches the block's structure at
`settings`. Its operation, `configure`, asks "administer blocks" and
refuses a block of another plugin. With `data_surface_tool` on, the
Tool API bridge derives two tools:

| Tool | Inputs |
| --- | --- |
| `data_surface:block.placed_component:configure` | `block` (its machine name), `values` (`settings`: `component`, `props`), `dry_run` |
| `data_surface:block.placed_component:configure:read` | `block` |

```bash
drush tool:run data_surface:block.placed_component:configure:read --uid=1 --input='{"block":"data_surface_examples_spotlight"}'
```

Over MCP an agent can read a placed component block's component and
props, change both, rehearse a change first, and is refused, by path,
props of another component or out of their bounds. It cannot yet place,
move or remove a block, list the placed blocks, or configure a block of
another plugin this way. With `data_surface_react` on, the same resource
is served at `/surface-api/block.placed_component/{block}`.

What was decided, and what is open (the component's slots, one resource
for a placed block of any plugin, the add form without JavaScript):
[A block embeds a component through its
structure](../../docs/decisions.md#a-block-embeds-a-component-through-its-structure).

## How to try it

| Where | What to look at |
| --- | --- |
| `/admin/structure/block`, "Place block", "Component" | Choose a component and its props are drawn below it; save, and the block renders it. |
| `/surface-examples/13`, with `data_surface_examples` on | The block beside example 12, its contract and tools, and the Spotlight block the examples place there. |

## Tests

| Test | Covers |
| --- | --- |
| `Kernel\ComponentBlockTest` | The structure: two keys, no component required, one variant per component the site has; both documents pinned whole for the three components the examples ship; the plugin's structure is no tool, a placed block's write and read tools are, their inputs and listing, a read, a dry run, a write that keeps the host's keys, props of another component refused, a block of another plugin refused; the render array, the props that hold nothing left out, nothing for no component or one the site lacks, and the dependency on the component's module. |
| `Kernel\ContractBaselineTest` | The structure's full schema against its baseline. |
| `Functional\ComponentBlockPlacementTest` | The block form without JavaScript: the components offered, a block with no component placed and rendering nothing, a component chosen and saved at once refused for the props it requires, and a placed block's form drawing its component's props, saving a change, refusing an out of bounds one and rendering. |
| `FunctionalJavascript\ComponentBlockAjaxTest` | The block form in a browser: choosing a component draws its props over AJAX, another swaps them, and the block saved renders the card with the props it holds. |
