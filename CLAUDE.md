# Data Surface — working notes

A surface is an immutable, sealed group of core data definitions that one
host declares once and every caller reads: a generated form renders it, a
pipeline accepts and validates values against it, a target writes them to
storage. The rule the whole module turns on is that refinement may only
*narrow*, so what a surface advertised when sealed stays true afterwards.

Where to look: **`docs/`** is the current documentation, and the only place
kept true (start at `docs/index.md`). **`ROADMAP.md`** is what is next.
**`PLAN.md`**, **`ADOPTION.md`**, **`HARDENING.md`** are design history —
read them for why, never as a description of today. **`REWORK.md`** is
the rework in progress on this branch, toward the spelling in `sketch/`.

## Two spellings

Until step 5 of `REWORK.md`, a surface can be written two ways, and both
seal through the same factory into the same `DataSurfaceInterface`. The
**new spelling** is a class in a module's `src/Surface/` carrying
`#[Surface]`, with `defineInputs()` and `#[RefinesInput]` methods, alters
in `src/SurfaceAlter/` carrying `#[AltersSurface]`, and `#[Situation]`
static methods; a plugin names its surface with `#[UsesSurface]`. It is
discovered by `SurfaceBuild\SurfaceCollectorPass` and built by the
`data_surface.surfaces` service (`docs/surfaces.md`). The **old
spelling** is `declareDataSurface()`, provider services and build event
subscribers, and the build event still fires for new-spelling surfaces,
so an old subscriber extends a new surface. Moved so far: the demo
block (with its presentation slot) and the demo formatter, the field
instance surface in `data_surface_tool` (with its storage child and
add/reuse/edit), the address settings that fill its slot and the
address field type that names them, the content type surface in
`data_surface_demo_node_type`, and every extras module extension (three
alters, no subscriber). Every plugin host reads `#[UsesSurface]` from
the plugin definition: block, formatter, condition, action, field type,
and `DataSurfacePluginForm` for any configurable plugin. A target has
three verbs, `load()`, `prepare()` (rehearse the write with storage's
own checks, no side effects) and `commit()` of what prepare returned; a
dry run stops after prepare and reports it. A decision the sketch does
not cover is marked `// SKETCH GAP:` where it is made; grep for it.

Situations are what routes and tools are generated from. A route names
`_data_surface_surface` and `_data_surface_situation` and maps its
parameters onto the situation's by name (`SituationRoute`), gated by
`_data_surface_situation_access`; `DataSurfaceProviderForm` serves it.
`data_surface_tool` derives one tool per situation that can be asked
on its own, `data_surface:<surface>:<situation>`: its surface names a
target, no plugin uses it, and every `%key` in its permission can be
supplied by a parameter (`SurfaceCatalogue::standalone()`). The field
tools are `data_surface:field.instance:add` / `:reuse` / `:edit`; the
hand-written `field_add` / `field_update` are gone.
`data_surface.surface_catalogue` lists the static layer; `docs/catalogue.md`
is generated from it by `scripts/generate-catalogue.php`. The old
provider spelling of the form stays for `NodeTypeSurfaceProvider`
(deprecated, unrouted) until step 5.

Subsurfaces are `attach()` (a fixed child, by class) and `attachBy()`
(a slot a sibling chooses; open when it lists no children, filled by
`#[SurfaceVariant]`). A child is built by the same build step in its
own frame and sealed into the parent's entry as a `SurfaceAttachment`,
or a `SurfaceSlot` of them; the pipeline, refinement, the form and the
tool bridge recurse through those entries, and nothing in a parent can
name a key inside its child. `docs/surfaces.md` has the rules.

## Running the suite

The suite runs from `web/` inside the ddev container, which is the only
place that can reach the test database:

```
ddev exec bash -c 'cd /var/www/html/web && SIMPLETEST_DB=mysql://db:db@db/db \
  SIMPLETEST_BASE_URL=http://localhost ../vendor/bin/phpunit -c core \
  modules/custom/data_surface'
```

The baseline as of this writing: **675 tests, 4344 assertions, 0 errors,
2 failures** — the two below. The test and assertion counts drift upward
as work lands and are not the thing to check. **No test may error,
and the only tests that may fail are the ones in
`DataSurfaceRefinementTest`**, for a reason that is not this module's:

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
- A surface is declared in one place: a `#[Surface]` class, or (old
  spelling) `declareDataSurface()`, or entirely at runtime in
  `getDataSurface()`. Never half of each.
- A `#[Surface]` class has no constructor and holds no service; a refiner
  points at a list with a constraint and the options resolver fetches.
  Alters, targets and access classes are autowired services and may hold
  services.
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
  by `scripts/generate-comparison.php`, and `docs/catalogue.md` by
  `scripts/generate-catalogue.php`. Never edit any of them by hand.
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
src/Surface/             The new spelling's API and attributes (Attribute/).
src/SurfaceBuild/        Discovery pass, registry, build step, adapters.
src/Hook/                #[UsesSurface] into plugin definitions.
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
