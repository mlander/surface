# Data Surface Examples - Compliance

Step 4 of [the examples](../data_surface_examples/README.md): another
module has a say in a surface it does not own.

## What it shows

One class, `SurfaceAlter\RegistrationComplianceAlter`, naming step 3's
surface with `#[AltersSurface]`. It adds a privacy notice, which is
stored under this module's name at
`third_party_settings.data_surface_examples_compliance.privacy_notice`
and described by this module's own config schema; it rewords step 3's
title label with `describe()`; and its `#[RefinesInput('privacy_notice')]`
method, watching step 3's `capacity`, makes the notice required above a
hundred people. Step 3 does not change and does not know this module
exists. There is no form alter and no hook.

## How to try it

```bash
drush pm:install data_surface_examples_compliance
```

Then reload `/surface-examples/3`, choose a room that seats more than a
hundred, and set the capacity to 101.

## What gates it

| Test | Covers |
| --- | --- |
| `Kernel\ExamplesComplianceTest` | The key appears under the module's name, the label changes, the notice is required at 101 and not at 100, an empty notice is refused, and a given one is stored in step 3's config. |
