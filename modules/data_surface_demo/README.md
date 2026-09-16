# Data Surface Demo

Three adoptions of one layer, each reduced to what only it can say: a
block plugin, a field formatter, and a standalone form, all driven by the
same surface.

## What it shows

### The block: `data_surface_demo`

A configurable block that declares its settings in one
`declareDataSurface()` method and writes no form code at all — no
`defaultConfiguration()`,
no `blockForm()`, no `blockValidate()`, no `blockSubmit()`. The entity
type is a `PluginExists` constraint naming its manager and interface,
which the options resolver reads as a select of content entity types, so
the list that validates and the list that is offered are one list. Bundle
refines against entity type and the field refines against both, live from
site state, through the one refiner method on the class.

### The formatter: `data_surface_demo_string`

Adoption on a host protocol with no validate and no submit hook. Field UI
asks for a settings form, harvests the raw values itself, and prunes what
it saves against a static defaults array. `defaultSettings()` is not
written here: the base class derives it from the same declaration the
surface is built from, `third_party_settings` included, so settings other
modules mount at build time survive the display save.

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

`data_surface_demo_extras` extends the formatter's surface from outside,
with no form alter anywhere. Install it and the variant select gains a
value and a mounted badge setting.

## What gates it

| Test | Covers |
| --- | --- |
| `Kernel\DemoBlockTest` | The block's surface, refinement and configuration round trip. |
| `Kernel\DemoFormatterTest` | The formatter's settings protocol, including the extras module's contribution. |
| `Kernel\DemoFormTest` | The standalone form against `StateTarget`. |
| `Kernel\ClassicParityTest` | That the block and the formatter still match their hand-written twins. |
| `FunctionalJavascript\DataSurfaceRefinementTest` | The AJAX rebuild in a real browser. |
