# Data Surface Demo - Classic

The demo block and the demo formatter, written the way they would have
been written before surfaces, so the two can be read side by side.

This module depends on `block` and `field` and on nothing from Data
Surface. Install it beside `data_surface_demo` and the site has two of
each plugin, behaving the same way, written two ways.

## Read them in pairs

| The classic way | The surface way |
| --- | --- |
| [`ClassicDemoBlock`](src/Plugin/Block/ClassicDemoBlock.php) | [`DataSurfaceDemoBlock`](../data_surface_demo/src/Plugin/Block/DataSurfaceDemoBlock.php) and its [`DemoBlockSurface`](../data_surface_demo/src/Surface/DemoBlockSurface.php) |
| [`ClassicDemoFormatter`](src/Plugin/Field/FieldFormatter/ClassicDemoFormatter.php) | [`DataSurfaceDemoFormatter`](../data_surface_demo/src/Plugin/Field/FieldFormatter/DataSurfaceDemoFormatter.php) |

The classic side is written to be good code, not to lose. It uses the
element-level validation core already provides (`#required`, `#min` and
`#max` on the number element, `#options` on the selects) instead of
re-checking it by hand, it reads its option lists live, and it escapes
field text the way core's own `StringFormatter` does. If a shorter
correct classic version exists, the comparison should be redone against
that one.

## The equivalence is enforced, not claimed

`Drupal\Tests\data_surface\Kernel\ClassicParityTest` holds the two sides
to the same behavior: the same submission into both blocks stores the
same configuration (the block host's own keys aside), both start from the
same defaults, both render the same list, and the same field items and
settings through both formatters produce the same render array, the same
rendered HTML, the same settings summary and the same static defaults.
A failure there means this page has stopped describing anything.

## Line counts

Counted from the files in this repository. "Code" is the same files with
blank lines and comment lines removed, because the two sides comment at
different rates and the raw count would flatter whichever writes less
prose.

| | Classic | | Surface | |
| --- | ---: | ---: | ---: | ---: |
| | **lines** | **code** | **lines** | **code** |
| Block plugin and its surfaces | 434 | 264 | 264 | 142 |
| Field list (constraint, validator, resolver) | — | — | 198 | 103 |
| Formatter plugin and its surface | 285 | 167 | 168 | 95 |
| Variant vocabulary | — | — | 120 | 40 |
| Config schema | 64 | 58 | 81 | 70 |
| **Total** | **783** | **489** | **831** | **450** |

Five of those rows are worth a sentence.

- The **variant vocabulary** is `DemoVariant`, an enum the surface
  formatter shares between its declaration and its refiner. It is counted
  on the surface side in full, even though most of it is documentation,
  because the classic formatter has no equivalent file: it writes the
  same vocabulary inline in `variants()` and `variantsFor()`, beside a
  `casings()` list the surface side gets from its declaration, and those
  lines are already inside its 285.
- The **formatter** is two files on the surface side: the plugin,
  which only shows a value (`formatValue()`), and
  `DemoFormatterSurface`, which its `#[UsesSurface]` names and which
  declares its settings and its outputs side by side. Its refiner is one
  `#[RefinesInput]` method, and its outputs have none, because outputs
  are never refined.
- The **config schema** is *longer* on the surface side, not shorter.
  Both versions hand-maintain a schema file; the surface one also
  declares the `third_party_settings` namespace other modules mount into,
  which the classic version has no way to offer.
- The **block plugin** is four files on the surface side: the plugin,
  which only renders; `DemoBlockSurface`, which its `#[UsesSurface]`
  names; and the two presentation surfaces its slot names,
  `ListPresentationSurface` and `GridPresentationSurface`. None holds a
  service. The slot is where the classic block grew most: a third
  AJAX-wired select, a details element whose children depend on it,
  per-presentation defaults, and storage written per presentation, all
  by hand. On the surface side it is one `attachBy()` and two classes of
  a few lines each.
- The **field list** is where the services went. A surface refiner
  points at a list rather than fetching it, and no core constraint names
  a bundle's fields, so the demo brings one, its validator, and the
  options resolver that reads it — more lines than the classic block
  spends fetching the same list inline. The bundle list needs nothing of
  the kind, because core's `EntityBundleExists` already says it.

## Concepts, which is the real difference

Line counts are a proxy. What actually costs an author is the number of
separate mechanisms they have to know about, get right, and keep in step
with each other. Counted from the same files. What each plugin *does* —
the block's `build()`, the formatter's showing step — is one method on
each side and is left out of both columns, because it is the part neither
approach changes.

### The block

| What the author has to touch | Classic | Surface |
| --- | ---: | ---: |
| Form API element definitions | 11 | 0 |
| AJAX wiring (`#ajax` arrays, callbacks, wrappers) | 5 | 0 |
| Value casting and storage assignments | 8 | 0 |
| Default values written out | 8 | 0 |
| Variant swapping by hand (rebuild read, per-variant defaults and storage) | 3 | 0 |
| Validation written by hand | 1 | 0 |
| Label lists kept in step with the form | 1 | 0 |
| Live option lists read from the site | 3 | 1 |
| Constraint naming a list, with its validator | 0 | 1 |
| Surface classes (the block's, and its slot's two children) | 0 | 3 |
| Refiner methods pointing at those lists | 0 | 2 |
| Config schema files | 1 | 1 |
| **Distinct mechanisms in play** | **9** | **5** |

### The formatter

| What the author has to touch | Classic | Surface |
| --- | ---: | ---: |
| Form API element definitions | 3 | 0 |
| AJAX wiring (`#ajax` array, callback, wrapper, rebuild read) | 4 | 0 |
| Validation written by hand (`#element_validate` and its checks) | 4 | 0 |
| Vocabulary lists kept in step | 4 | 1 |
| Default values written out | 3 | 0 |
| Settings summary assembled by hand | 1 | 0 |
| Surface class (settings and outputs together) | 0 | 1 |
| Refiner methods | 0 | 1 |
| Config schema files | 1 | 1 |
| **Distinct mechanisms in play** | **8** | **4** |

Nine against five on the block, eight against four on the formatter. The
mechanisms the classic version sheds — form elements, AJAX, storage,
defaults, validation — are also the ones that have to agree with each
other, and nothing checks that they do: a key added to
`defaultConfiguration()` and forgotten in `blockForm()` can never be
set, one added to `blockForm()` and forgotten in the schema
fails on save, and one added everywhere except `blockValidate()` is
stored unchecked. On the surface side they are one declaration, so there
is nothing to keep in step.

## What the classic version does not get

Everything above is about writing the same thing with less code, which is
the smaller half of the argument. The larger half is what the classic
version cannot do at any length. Its settings exist only as form
elements, so the only way to write them is to submit that form: there is
no one write path a config action, a REST client, a test fixture or an
agent can use, and no way to ask what the block would accept without
rendering a page. There is no dry run, because validation and storage are
the same step. A stored value whose bundle was deleted under it throws
out of the select instead of being reported as stale and kept. Nothing it
emits is described, so nothing it emits can be checked against a
contract. And no other module can extend it: a third party that wants one
more variant or one more setting has to alter the form, which reaches the
form and nothing else — not the validator, not the schema, not any
machine reading the contract — whereas a contribution to a surface joins
the advertisement itself, under the contributing module's name, held to
narrowing forever after. See [Refinement and
contributions](../../docs/refinement.md).

## How to try it

```bash
drush pm:install data_surface_demo data_surface_demo_classic
```

| Where | What to look at |
| --- | --- |
| `/admin/structure/block` | Place **Data surface demo** and **Data surface demo (classic)** side by side and configure both. The forms should be indistinguishable. |
| Manage display, on any bundle with a string field | Switch between the two demo formatters and compare their settings forms and their output. |

## What gates it

| Test | Covers |
| --- | --- |
| `Kernel\ClassicParityTest` | That the two versions of each plugin really do the same thing. |
| `Kernel\DemoBlockTest`, `Kernel\DemoFormatterTest` | The surface side of each pair, in its own right. |
