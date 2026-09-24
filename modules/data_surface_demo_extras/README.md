# Data Surface Demo Extras

What a third-party module can do to another module's contract without
touching a form — and, for content types, the same extension written
both ways, so the two can be compared.

## What it shows

- It mounts a `badge` setting under its own namespace on the demo
  formatter's surface, with a default and a labeled choice. The setting
  is advertised, validated and rendered like any other, where the
  form-alter era gave an element no machine could see.
- It contributes one more value, `ribbon`, to the formatter's `variant`
  key, and registers a refiner under its own provider id to say when that
  value is offered — here, only in upper case.

Both live in one event subscriber on `DataSurfaceBuildEvent`, which fires
while the surface is still mutable. Nothing here alters a form, and every
consumer of the surface — form, validation, defaults, and any
machine-readable contract — sees the same extended surface.

The contribution is the part worth reading twice. The value is added at
build time, so it is part of what the surface advertises rather than
something a refiner smuggles in afterwards; and the refiner registered
with it is handed that one value and nothing else, so it cannot narrow
away a variant the formatter owns, and cannot hand back a value it was
never given. What the refined surface offers is the union of what each
contribution narrowed to:

| Casing | The formatter's variants | This module's | Offered |
| --- | --- | --- | --- |
| `none` | all of them | none | bold, strong, quiet, muted |
| `uppercase` | bold, strong | ribbon | bold, strong, ribbon |
| `lowercase` | quiet, muted | none | quiet, muted |

## The same extension, written twice

For content types this module adds two editorial review settings,
stored as its own third party settings on the node type: a review
deadline of one hour to thirty days, stored as one integer of seconds
under a key, `review_deadline`, that does not name its unit; and a list
of audience tags.

- **As contract**, in the same subscriber: mounted on the content type
  surface (host id `entity_type:node_type`, so nothing here depends on
  the module providing it). The deadline is asked for as an amount and a
  unit — hours, days or weeks — with a constraint on the pair that
  refuses anything past thirty days on the amount, and
  `ReviewDeadlineShape` turns the pair into seconds and back; it is
  handed to the surface with `setThirdPartyShape()`, and the target that
  writes third party settings applies it in prepare. The tags are a
  list, with a pattern for each tag and a uniqueness constraint. It
  brings a comma-separated widget for the list, because the stock
  widgets draw a list only as a multiple select.
- **The classic way**, in `Hook\NodeTypeFormHooks`: a
  `hook_form_node_type_form_alter` on core's own content type form, with
  an amount and a unit select whose `#element_validate` turns them into
  seconds and checks the range, an `#element_validate` that splits,
  trims, lower cases and de-duplicates the typed tags, and an
  `#entity_builders` callback — what `menu_ui` does to the same form.

The config schema is complete for the deadline — an integer with a Range
of 3600 to 2592000 — so the classic side is not short of validation.
What it cannot carry is the unit, and how an amount of hours, days or
weeks becomes the integer: that lives in the form. For the tags the
schema says only that they are a list of strings; what a tag may be
lives in the form too, which is the common case.

The node integration is inert without the node module, so the module
does not depend on it.
[`data_surface_demo_node_type_tool`](../data_surface_demo_node_type_tool/README.md)
records what an agent's tool can see and do with each.

## How to try it

```bash
drush pm:install data_surface_demo_extras
```

Open the **Data surface demo formatter**'s settings on any bundle's
manage display page. Before this module: four variants and no badge.
After: a badge select that no module the formatter knows about declared,
and a variant list that gains `ribbon` exactly when the casing is upper
case.

For the content type settings, open core's **Add content type** form and
find the **Editorial review** tab. With `data_surface_demo_node_type`
installed, the same two settings appear on
`/admin/structure/types/surface-add` under **Third party settings**.

## What gates it

`Kernel\DemoFormatterTest` installs this module alongside the demo and
asserts the mounted key, the contributed value, and the union each casing
produces. `Kernel\NodeTypeToolComparisonTest` covers both content type
extensions: the classic form for a person, the surface form, and what
each content type tool advertises and stores.

## See also

[`docs/refinement.md`](../../docs/refinement.md) in the parent module is
the model this is the worked example of.
