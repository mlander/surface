# Data Surface - Address field settings

Adopting a surface on a contrib field type without forking it, and the
A/B for what a typed contract adds over a config schema.

## What it shows

The `address` field type's instance settings (`available_countries`,
`langcode_override`, `field_overrides`) are described by a surface
declared on the field item class instead of by the form that collects
them. Field UI renders the generated form; the address module is not
modified.

Three pieces, all on `SurfaceAddressItem`:

- The `#[DataSurfaceAware]` attribute — the definitions, readable from
  the class without instantiating anything. The country list and the
  language list are not in it: the items carry the address module's
  `Country` constraint and the `LanguageExists` constraint, and
  `CountryOptions` and `LanguageExistsOptions` resolve them live, which
  is what lets the declaration be static. The twelve override properties
  are merged in `getFieldSurface()`, because core's `MapDataDefinition`
  cannot take property definitions in its constructor.
- `toStorage()` / `fromStorage()` — the shape transform between the input
  shape and the stored shape. It is not new logic: it is the settings
  form's validate handler (strip the rows with an empty override) plus
  the item class's accessors (unwrap each override, drop the falsy
  countries, let the deprecated `fields` key win when it is set), given
  one visible home.
- `hook_field_info_alter()` — the class swap.

The surface deliberately describes the input shape, not the storage
shape: a list of country codes rather than a map of each code to itself,
one optional override per field rather than a single-key array around
each one, and no deprecated `fields` key at all. Every write through the
surface stores `fields` empty, so it can never shadow `field_overrides`.

### Why this field type

Its settings form has no dependent settings and no AJAX, so nothing about
the comparison is about form mechanics. What is left is meaning and
shape, which is the whole of what the config schema for
`field.field_settings.address` cannot say: it gives three types, a nested
`override` key with no vocabulary, no labels, no defaults, and no hint
that one of the four keys is deprecated and overrules another.

## How to try it

```bash
composer require drupal/address
drush pm:install data_surface_address
```

Add an address field to any bundle and open its field settings. The swap
is a single assignment in `AddressSurfaceHooks::fieldInfoAlter()`, so the
before and after are one uninstall apart:

1. Configure an address field with the module uninstalled.
2. Install it and reload
   `admin/structure/types/manage/<bundle>/fields/<field>`.
3. Compare. The storage contract does not change, so a field configured
   through either form is read identically by
   `AddressItem::getAvailableCountries()` and
   `AddressItem::getFieldOverrides()`.

## What gates it

`Kernel\AddressFieldSurfaceTest` covers the surface, the shape transform
in both directions, and the settings round trip. The same surface is what
`data_surface_tool`'s tools describe, so
`Kernel\FieldToolsTest` and `Kernel\FieldToolsComparisonTest` exercise it
from a non-form caller.
