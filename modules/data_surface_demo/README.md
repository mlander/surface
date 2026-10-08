# Data Surface Demo

Three adoptions of one layer, each reduced to what only it can say: a
block plugin, a field formatter, and a standalone form. The block and
the formatter show the two ways a plugin has a surface: the block names
a surface class of its own, the formatter is its own surface.

## What it shows

### The block: `data_surface_demo`

The plugin only renders; its
configuration is `Surface\DemoBlockSurface`, which the plugin names with
`#[UsesSurface]`, and it writes no form code at all — no
`defaultConfiguration()`,
no `blockForm()`, no `blockValidate()`, no `blockSubmit()`. The entity
type is a `PluginExists` constraint naming its manager and interface,
which the options resolver reads as a select of content entity types, so
the list that validates and the list that is offered are one list. The
bundle refines against the entity type and the field against both,
through two `#[RefinesInput]` methods that point at a list with a
constraint rather than fetch one: core's `EntityBundleExists` for the
bundle, and this module's `DataSurfaceDemoBundleField`, with its options
resolver, for the field. Its presentation settings are a slot: a list
and a grid need different things, so each is a surface of its own,
`ListPresentationSurface` and `GridPresentationSurface`, each marked
`#[SurfaceVariant]` for one value of the `presentation` key, which
chooses the `presentation_settings` slot the block's surface makes with
`attachBy()`; the form swaps them over AJAX and
the config schema picks the mapping with `[%parent.presentation]`. See
[docs/surfaces.md](../../docs/surfaces.md).

### The formatter: `data_surface_demo_string`

Adoption on a host protocol with no validate and no submit hook. Field UI
asks for a settings form, harvests the raw values itself, and prunes what
it saves against a static defaults array. The formatter is its own
surface: `#[UsesSurface]` with no argument, and the plugin class
implements `SurfaceInterface`, so its inputs in a static
`defineInputs()`, its outputs in a static `defineOutputs()` and the
variant narrowed by the casing in one static `#[RefinesInput]` method
sit in the same file as `formatValue()`, the one thing it does. The
surface's id comes from the plugin, `field_formatter:data_surface_demo_string`,
and the extras module's alter names the formatter class.
`defaultSettings()` is not written here: the
base class reads the surface's own shape, `third_party_settings`
included, so settings other modules mount at build time survive the
display save. Outputs are never refined, so the class list it emits is
advertised open whatever variant is chosen.

### Which spelling, when

The block keeps its surface in `Surface\DemoBlockSurface` because the
surface is large: six keys, two refiners and a slot whose two children
are surfaces of their own. A separate class reads better there: the
plugin stays a page of rendering, and the surface is one file to open
for the whole contract.
The formatter is small, its settings are only ever its own, and every
key is read a few lines below in `formatValue()`: one file reads better.
Both are built the same way, discovered the same way, altered the same
way, and listed in the catalogue the same way; only where the class is
differs.

### The standalone form

The same surface, a different storage. The form asks the block manager
for the plugin and reads its contract rather than repeating it, then
hands the submitted values to `DataSurfacePipeline::submit()` with a
`StateTarget`. A surface plus a target is a complete configurable thing,
and the form is three delegations long.

## How to try it

```bash
drush pm:install data_surface_demo
```

| Where | What to look at |
| --- | --- |
| `/admin/structure/block` | Place the **Data surface demo** block. Change the entity type and watch bundle and field rebuild over AJAX. |
| `/admin/config/development/data-surface-demo` | The same surface as a standalone form, writing to State instead of block configuration. Needs `administer site configuration`. |
| Manage display, on any bundle with a string field | Choose the **Data surface demo formatter** and open its settings. |

`data_surface_demo_classic` ships the same block and the same formatter
written the pre-surface way, by hand. Install it beside this module to
read the two side by side; its README counts the lines and the separate
mechanisms each version costs, and `Kernel\ClassicParityTest` holds the
two to the same behavior.

`data_surface_demo_extras` extends the formatter's and the block's
surfaces from outside, with surface alters and no form alter anywhere.
Install it and the variant select gains a value and a mounted badge
setting.

## What gates it

| Test | Covers |
| --- | --- |
| `Kernel\DemoBlockTest` | The block's surface, refinement and configuration round trip. |
| `Kernel\DemoFormatterTest` | The formatter's settings protocol, the host reading `#[UsesSurface]`, and the extras module's contribution. |
| `Kernel\UsesSurfaceHostsTest` | Every plugin host reading its surface from the attribute. |
| `Kernel\DemoFormTest` | The standalone form against `StateTarget`. |
| `Kernel\ClassicParityTest` | That the block and the formatter still match their hand-written twins. |
| `FunctionalJavascript\DataSurfaceRefinementTest` | The AJAX rebuild in a real browser. |
