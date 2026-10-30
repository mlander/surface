# Surface API Demo

Three adoptions of one layer, each reduced to what only it can say: a
block plugin, a field formatter, and a standalone form. The block and
the formatter show the two ways a plugin has a structure: the block names
a structure class of its own, the formatter is its own structure.

## What it shows

### The block: `surface_examples_plugins`

The plugin only renders; its
configuration is `Structure\DemoBlockStructure`, which the plugin names with
`#[UsesStructure]`, and it writes no form code at all: no
`defaultConfiguration()`,
no `blockForm()`, no `blockValidate()`, no `blockSubmit()`. The entity
type is a `PluginExists` constraint naming its manager and interface,
which the options resolver reads as a select of content entity types, so
the list that validates and the list that is offered are one list. The
bundle refines against the entity type and the field against both,
through two `#[RefinesInput]` methods that point at a list with a
constraint rather than fetch one: core's `EntityBundleExists` for the
bundle, and this module's `SurfaceExamplesBundleField`, with its options
resolver, for the field. Its presentation settings are variants: a list
and a grid need different things, so each is a structure of its own,
`ListPresentationStructure` and `GridPresentationStructure`, each marked
`#[Variant]` for one value of the `presentation` key, which
chooses between the variants the block's structure attaches at
`presentation_settings` with `attachVariants()`; the form swaps them over AJAX and
the config schema picks the mapping with `[%parent.presentation]`. See
[docs/resources.md](../../docs/resources.md).

### The formatter: `surface_examples_plugins_string`

Adoption on a host protocol with no validate and no submit hook. Field UI
asks for a settings form, harvests the raw values itself, and prunes what
it saves against a static defaults array. The formatter is its own
structure: `#[UsesStructure]` with no argument, and the plugin class
implements `StructureInterface`, so its inputs in a static
`defineInputs()`, its outputs in a static `defineOutputs()` and the
variant narrowed by the casing in one static `#[RefinesInput]` method
sit in the same file as `formatValue()`, the one thing it does. The
structure's id comes from the plugin, `field_formatter:surface_examples_plugins_string`,
and the extras module's alter names the formatter class.
`defaultSettings()` is not written here: the
base class reads the structure's own declaration, `third_party_settings`
included, so settings other modules mount at build time survive the
display save. Outputs are never refined, so the class list it emits is
advertised open whatever variant is chosen.

### Which spelling, when

The block keeps its structure in `Structure\DemoBlockStructure` because
the structure is large: six keys, two refiners and two variants that
are structures of their own. A separate class reads better
there: the plugin stays a page of rendering, and the structure is one
file to open
for the whole contract.
The formatter is small, its settings are only ever its own, and every
key is read a few lines below in `formatValue()`: one file reads better.
Both are built the same way, discovered the same way, altered the same
way, and listed in the catalog the same way; only where the class is
differs.

### The standalone form

The same structure, different storage. The form asks the block manager
for the plugin and reads its contract rather than repeating it, then
hands the submitted values to `SurfacePipeline::submit()` with a
`StateStorage`. A structure plus storage is a complete configurable thing,
and the form is three delegations long.

## How to try it

```bash
drush pm:install surface_examples_plugins
```

| Where | What to look at |
| --- | --- |
| `/admin/structure/block` | Place the **Data surface demo** block. Change the entity type and watch bundle and field rebuild over AJAX. |
| `/admin/config/development/surface-example-form` | The same structure as a standalone form, writing to State instead of block configuration. Needs `administer site configuration`. |
| Manage display, on any bundle with a string field | Choose the **Data surface demo formatter** and open its settings. |

`surface_examples_classic` ships the same block and the same formatter
written the classic way, by hand. Install it beside this module to
read the two side by side; its README counts the lines and the separate
mechanisms each version costs, and `Kernel\ClassicParityTest` holds the
two to the same behavior.

`surface_examples_extras` extends the formatter's and the block's
structures from outside, with alters and no form alter anywhere.
Install it and the variant select gains a value and a mounted badge
setting.

## What gates it

| Test | Covers |
| --- | --- |
| `Kernel\DemoBlockTest` | The block's structure, refinement and configuration round trip. |
| `Kernel\DemoFormatterTest` | The formatter's settings protocol, the host reading `#[UsesStructure]`, and the extras module's contribution. |
| `Kernel\UsesStructureHostsTest` | Every plugin host reading its structure from the attribute. |
| `Kernel\DemoFormTest` | The standalone form against `StateStorage`. |
| `Kernel\ClassicParityTest` | That the block and the formatter still match their hand-written twins. |
| `FunctionalJavascript\SurfaceRefinementTest` | The AJAX rebuild in a real browser: the chain, an entity type change replacing the bundle and the field and nothing else, and an orphaned bundle reset silently or, when stored, refused on save. |
