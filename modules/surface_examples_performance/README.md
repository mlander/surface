# Surface API - Performance settings

Three settings of core's performance form, declared once in one
resource, `system.performance`: how long browsers and proxies may cache
a page, and whether CSS and JavaScript files are aggregated. The
resource is stored in core's `system.performance` config object, each
key at the path core keeps it at, and held to core's config schema for
that object, which this module does not change.

```bash
drush pm:install surface_examples_performance
```

The form is at `/admin/config/development/surface-performance`, behind
core's "administer site configuration" permission. The module adds no
permission of its own.

With `surface_tool` enabled, the resource is also the tool
`surface:system.performance:configure`, with its read tool:

```bash
drush tool:run surface:system.performance:configure --uid=1 --input='{"values":{"max_age":300,"css_preprocess":true,"js_preprocess":true},"dry_run":true}'
```

With `surface_openapi` enabled, the OpenAPI document at
`/surface-api/openapi.json` describes `PATCH /surface-api/system.performance`.

A write keeps every key of `system.performance` the resource does not
declare (`css.compress`, `js.compress`, `fast_404`), and then deletes
the aggregated CSS and JavaScript files, as core's form does, whether
the write came from the form, the tool or HTTP.

## What the schema does not say

Core's schema for the page cache's maximum age says only that it is an
integer labeled "Max age". Three facts the form shows are in the form's
code alone, so nothing else can read them:

- **The title**: "Browser and proxy cache maximum age".
- **The description**: "This is used as the value for max-age in
  Cache-Control headers."
- **The list of periods**: fourteen values from no caching to one day,
  each labeled by the date formatter, where the schema allows any
  integer.

The resource declares the three once, and the form, the tool, the MCP
listing, the OpenAPI document and the React page read them from it:

```php
$inputs->add(self::MAX_AGE, 'integer', t('Browser and proxy cache maximum age'), default: 0)
  ->setDescription(t('This is used as the value for max-age in Cache-Control headers.'))
  ->setRequired(TRUE)
  ->addConstraint('LabeledChoice', ['labels' => $periods]);
```

`$periods` holds the same fourteen values core's form offers, each
labeled by `formatInterval()`, and 0 labeled `<no caching>`.

The storage is held to core's schema unchanged. When the resource is
built, the storage's `widerThanSchema()` compares each key with the
property at its path in `system.schema.yml`, and the build refuses a
resource that allows a value the schema refuses. So the resource cannot
advertise a value storage would refuse. The list of periods is narrower
than "any integer", which the schema allows.

The storage says where each key is kept:

```php
public const STORED_AT = [
  'max_age' => 'cache.page.max_age',
  'css_preprocess' => 'css.preprocess',
  'js_preprocess' => 'js.preprocess',
];
```

See [A key stored under a path](../../docs/storage.md#a-key-stored-under-a-path).

Core's schema does not mark `system.performance` fully validatable, so
by core's rule any of its keys may hold nothing. The resource marks the
three keys required, so a call that sends null for one is refused.
