# Data Surface Demo Extras

What a third-party module can do to another module's contract without
touching a form.

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

## How to try it

```bash
drush pm:install data_surface_demo_extras
```

Open the **Data surface demo formatter**'s settings on any bundle's
manage display page. Before this module: four variants and no badge.
After: a badge select that no module the formatter knows about declared,
and a variant list that gains `ribbon` exactly when the casing is upper
case.

## What gates it

`Kernel\DemoFormatterTest` installs this module alongside the demo and
asserts the mounted key, the contributed value, and the union each casing
produces.

## See also

[`docs/refinement.md`](../../docs/refinement.md) in the parent module is
the model this is the worked example of.
