# Data Surface component block

A block, "Component", that embeds a single-directory component: the
component chosen from those the site has, its props read from the JSON
Schema the component declares, and its slots read from its metadata,
each holding text, one component or a list of them. Its settings are a
structure of three keys, and a placed one is placed, configured and
removed through the general placement resource of `data_surface_block`.

```bash
drush pm:install data_surface_component_block
```

It needs `block` and nothing contributed. Example 13 of
`data_surface_examples`, at `/surface-examples/13`, shows it beside
example 12, whose three lines its settings are, with `data_surface_block`
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
refuses for a prop whose type has no null), and its slots filled
(`#slots`): text as `#plain_text`, escaped where the slot prints it; one
component as its own render array, props held and slots filled the same
way; a list as a render array of each. A slot that holds nothing is not
passed. The render array carries the cacheability of the two component
lists. A block with no component, or with one the site no longer has,
renders nothing, and so does a nested component the site no longer has.
The block depends on the module or theme that provides each component it
embeds, at any depth, so it is removed with any of them.

### The structure: `block.data_surface_component`

`Structure\ComponentBlockStructure` is example 12's three lines under the
block's id:

```php
$inputs->add('component', 'string', t('Component'));
$inputs->attachVariants('props', by: 'component', from: ComponentProps::class)
  ->setLabel(t('Props'));
$inputs->attachVariants('slots', by: 'component', from: ComponentSlots::class)
  ->setLabel(t('Slots'));
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
component as a string and the props and the slots as `type: ignore`,
since which components a site has is the site's; the chosen component's
variants hold the props and the slots to their own keys before anything
is written.

### The slots

`ComponentSlots`, also the main module's
(`Drupal\data_surface\Variants\ComponentSlots`), lists one variant per
component at `slots`: one key per slot its metadata declares, none for a
component with no slot. Each slot holds what its `kind` says:

| `kind` | Holds | Stored as |
| --- | --- | --- |
| `text` | A plain string, escaped where the slot prints it. | `{"kind": "text", "text": "..."}` |
| `component` | One component: its `component`, `props` and `slots`, the block's three keys again. | `{"kind": "component", "component": {"component": "...", "props": {...}, "slots": {...}}}` |
| `components` | A list of those. | `{"kind": "components", "components": [{...}, ...]}` |

What the slot's metadata says narrows it, and each is a refusal both
documents state:

| The slot declares | The structure |
| --- | --- |
| `expected` with ids (`provider:name`) | The nested `component` offers those ids alone; another is refused naming them. |
| `expected` with a tag (no colon) | The nested `component` offers the components whose `tags` hold it. |
| `expected: []` | No component: the slot holds text alone (the examples' card's `body`). |
| `minItems`, `maxItems` | The `components` list's Count. |
| `maxItems: 1` | Text or one component, no list. |
| Nothing | Any component the site has. |

Slots nest three deep (`ComponentSlots::DEPTH`): the block's
component's slots are at depth 1, a nested component's at 2, and a slot
at depth 3 holds text alone. The examples' hero expects two calls to
action at most in its `actions`; their section takes any component
tagged `block` in its `content`, the card and the hero. On the block
form a slot's kind draws what it holds and a nested component swaps its
props and slots over AJAX; a list of components inside a slot is not
drawn there, and is set through the tool, HTTP or the React page.
[A slot holds text, a component, or
components](../../docs/decisions.md#a-slot-holds-text-a-component-or-components)
says why each.

### A placed block, through the placement resource

A plugin's structure is held by its host and is no tool. A placed block
is a config entity, and `data_surface_block` declares it as a resource,
`block.placement`, whose plugin chooses its settings: for this plugin,
this structure, built as declared, the components its variants in turn
([its README](../data_surface_block/README.md)). With both modules on
and `data_surface_tool`, an agent places a component block, chooses its
component, props and slots, rehearses the change, moves it within its
theme and removes it, and is refused, by path, props of another
component or out of their bounds, and a component a slot does not
expect, naming the ones it does:

```bash
drush tool:run data_surface:block.placement:update:read --uid=1 --input='{"id":"data_surface_examples_spotlight"}'
```

This module needs no resource of its own for it, and does not depend on
`data_surface_block`: until 2026-10-23 it declared a stop-gap,
`block.placed_component`, which the general resource replaced.

What was decided, and what is open (the create form without JavaScript, a
list of components on the block form): [A block embeds a component
through its
structure](../../docs/decisions.md#a-block-embeds-a-component-through-its-structure),
[A slot holds text, a component, or
components](../../docs/decisions.md#a-slot-holds-text-a-component-or-components),
and [A placed block is a
resource](../../docs/decisions.md#a-placed-block-is-a-resource).

## How to try it

| Where | What to look at |
| --- | --- |
| `/admin/structure/block`, "Place block", "Component" | Choose a component and its props and slots are drawn below it; choose "A component" in a slot and pick one; save, and the block renders it. |
| `/surface-examples/13`, with `data_surface_examples` and `data_surface_block` on | The block beside example 12, its contract, the hero's narrowed slot, the placement's tools, and the Spotlight and Tonight blocks the examples place there through them. |

## Tests

| Test | Covers |
| --- | --- |
| `Kernel\ComponentBlockTest` | The structure: three keys, no component required, one variant per component the site has at the props and again at the slots; both documents pinned whole for the four components the examples ship, and the hero's narrowed slot pinned exactly; the plugin's structure is no tool; the render array, the props that hold nothing left out, nothing for no component or one the site lacks; each slot rendered, text escaped, one component, a list, a section holding a card, the slots that hold nothing left out; the dependency on every embedded component's module. |
| `Kernel\ComponentSlotsTest` | What a slot expects, read back from the metadata by id, by tag, empty and absent; the kinds that follow; the depth cap, built and in the full schema; each expectation a refusal: a component outside the list, a tag mismatch, too many items, a list where one at most is allowed. |
| `Kernel\BlockPlacementToolsTest` | A component block placed, its props changed, read and removed through the placement resource's tools, props of another component refused by path; a section holding a card placed through create, and a hero in the hero's actions refused naming the call to action. |
| `Kernel\ContractBaselineTest` | The structure's full schema against its baseline. |
| `Functional\ComponentBlockPlacementTest` | The block form without JavaScript: the components offered, a block with no component placed and rendering nothing, a component chosen and saved at once refused for the props it requires, and a placed block's form drawing its component's props, saving a change, refusing an out of bounds one and rendering. |
| `FunctionalJavascript\ComponentBlockAjaxTest` | The block form in a browser: choosing a component draws its props over AJAX, another swaps them, and the block saved renders the card with the props and slot it holds; a section's slot switched to one component draws it, a nested hero swapped for a card swaps their props, and the section saved renders the card inside it. |
| `FunctionalJavascript\ReactNarrowingTest` | Example 12 on the React page: a slot's kind and a nested component narrowed from the full schema with no request. |
