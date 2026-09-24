# Data Surface — working notes

A surface is an immutable, sealed group of core data definitions that one
host declares once and every caller reads: a generated form renders it, a
pipeline accepts and validates values against it, a target writes them to
storage. The rule the whole module turns on is that refinement may only
*narrow*, so what a surface advertised when sealed stays true afterwards.

Where to look: **`docs/`** is the current documentation, and the only place
kept true (start at `docs/index.md`). **`ROADMAP.md`** is what is next.
**`PLAN.md`**, **`ADOPTION.md`**, **`HARDENING.md`** are design history —
read them for why, never as a description of today.

## Running the suite

The suite runs from `web/` inside the ddev container, which is the only
place that can reach the test database:

```
ddev exec bash -c 'cd /var/www/html/web && SIMPLETEST_DB=mysql://db:db@db/db \
  SIMPLETEST_BASE_URL=http://localhost ../vendor/bin/phpunit -c core \
  modules/custom/data_surface'
```

The baseline as of this writing: **573 tests, 3129 assertions, 0 errors,
2 failures**. The test and assertion counts drift upward as work lands
and are not the thing to check. **No test may error, and the only tests
that may fail are the ones in `DataSurfaceRefinementTest`**, for a reason
that is not this module's:

1. `DataSurfaceRefinementTest` — environmental, every test in it.
   `DriverException: Could not open connection` on port 4444; ddev runs
   no webdriver. That class is the invariant; a failure anywhere else is
   a real regression.

There are no open findings. The two that stood here —
`NodeTypeSurfaceFormTest::testAddStoresTheTypeAndItsOverrides` and
`NodeTypeSurfaceFormTest::testDuplicateMachineNameIsRefusedOnTheTypeElement`
— are fixed: the cosmetic layer no longer lets core's machine name
element validate beside the surface and swallow its violation, and the
test drops the field definitions its own process memoized before the
request wrote them. Both are pinned by kernel tests now. They surfaced
only once node could be installed inside functional tests at all, which
the project-level `web/core/phpunit.xml` and its bootstrap at the site
root (`phpunit-bootstrap.php`) made possible; delete those two files and
the old `node_make_sticky_action` install failure comes back, hiding
these tests again. Any error, or any other failure, is a real regression.

`scripts/check.sh` runs the suite and all three gates below in order,
enforces that rule, and stops at the first failure.

## The three gates

Run from the site root (`ROOT` below), which is the parent of `web/`:

```
# phpcs — the stored installed_paths point inside the container.
cd web/modules/custom/data_surface && $ROOT/vendor/bin/phpcs \
  --runtime-set installed_paths \
  "$ROOT/vendor/drupal/coder/coder_sniffer,$ROOT/vendor/slevomat/coding-standard"

# phpstan — the module's own config; needs the raised memory limit.
cd $ROOT && vendor/bin/phpstan analyse \
  -c web/modules/custom/data_surface/phpstan.neon --no-progress --memory-limit=1G

# cspell — core's .cspell.json with globRoot set to the module, its
# dictionaryDefinitions paths made absolute, and .cspell-project-words.txt
# merged into "words"; then, from the module root:
npx --yes cspell@8 --config /tmp/merged.json --no-progress --no-summary "**"
```

`scripts/check.sh` bakes all four in; read it rather than retyping them.

## Conventions that are load-bearing

- Contracts are objects; payloads are arrays. Never pass a definition as an
  array or a value bag as an object.
- A surface is declared in one place: `declareDataSurface()`, or entirely at
  runtime in `getDataSurface()`. Never half of each.
- Refiners narrow a definition, contributors widen the surface at build
  time, filters remove keys. Those are three different jobs; do not blur.
- Requiredness appears only when it says something: `setRequired(TRUE)` on
  the keys that must be configured, nothing at all on the rest, because
  core data definitions are optional by default.
- Violations are message objects end to end, from the pipeline to the form,
  never pre-rendered strings.
- Translatable strings: static context constructs `new TranslatableMarkup`
  because it must, instance context calls `$this->t()` — a container-built
  class of ours injects `string_translation` for it — and object-oriented
  code never calls the global `t()`.
- No closures anywhere a form array or a surface carries — both are
  serialized. Use a service, a callable string, or a class.
- Iteration order is load-bearing: the definition map is in declaration
  order, and `FieldToolsComparisonTest`'s drift assertion is the tripwire.
- `modules/data_surface_tool/COMPARISON.md` and
  `modules/data_surface_demo_node_type_tool/COMPARISON.md` are generated
  by `scripts/generate-comparison.php`. Never edit either by hand.
- Commit messages are one line.

## Naming

- A standalone provider class ends in `SurfaceProvider`
  (`NodeTypeSurfaceProvider`). A host that declares its own surface is named
  for the host instead.
- A class-swap adopter prefixes the swapped class with `Surface`
  (`AddressItem` → `SurfaceAddressItem`), and the hook implementation's
  docblock **must name the replacement class**, so grepping the original
  lands on the line that replaces it.
- Host ids are namespaced `<host type>:<id>` — `block:my_teaser`,
  `field_type:address`.
- Operations are closed verbs from the host type's vocabulary (`configure`,
  `add`, `edit`, `field_settings`) and never carry identity. Identity goes
  in the opaque subject the provider resolves for itself.

See `docs/declaring-a-surface.md` for all four in full.

## Where things live

```
src/                     Surface, builder, factory, definition map, host trait.
src/Pipeline/            Access, accept, validate, prepare, commit; results.
src/Target/              Where accepted values are written; storage shapes.
src/Form/                Form builder, host traits, the generic provider form.
src/Widget/ src/Plugin/  Definition to form element; resolvers, hosts, constraints.
src/Options/             Option sets, the resolver plugin base and manager.
src/Refinement/ Event/   Narrowing and choice sets; the build event.
modules/                 Seven experimental submodules; each has its own README.
tests/src/               Unit, Kernel, Functional, FunctionalJavascript.
docs/ scripts/           Published documentation; check.sh and the generator.
```
