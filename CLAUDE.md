# Data Surface — working notes

A surface is an immutable, sealed group of core data definitions that one
host declares once and every caller reads: a generated form renders it, a
pipeline accepts and validates values against it, a target writes them to
storage. The rule the whole module turns on is that refinement may only
*narrow*, so what a surface advertised when sealed stays true afterwards.

Where to look: **`docs/`** is the current documentation, and the only place
kept true (start at `docs/index.md`; the pattern is `docs/pattern.md`, the
build step `docs/how-it-fits.md`, every decision the pattern left open
`docs/decisions.md`). **`ROADMAP.md`** is what is next.
**`PLAN.md`**, **`ADOPTION.md`**, **`HARDENING.md`** are design history —
read them for why, never as a description of today. **`REWORK.md`** is
the record of the rework that brought the module to the pattern.

## One spelling

A surface is a class in a module's `src/Surface/` carrying `#[Surface]`,
with `defineInputs()` (and, on `HasOutputsInterface`, `defineOutputs()`),
`#[RefinesInput]` methods and `#[Situation]` static methods. Another
module changes it with an alter in `src/SurfaceAlter/` carrying
`#[AltersSurface]`. A plugin names its surface with `#[UsesSurface]`.
Everything is discovered by `SurfaceBuild\SurfaceCollectorPass` and built
by the `data_surface.surfaces` service (`SurfacesInterface::build($surface,
$context)`), which writes the shape, the alters, the context and the
bound refiners into the engine's `DataSurfaceBuilder` and seals it into a
`DataSurfaceInterface` (`docs/surfaces.md`). The builder, the definition
map and `DataSurfaceRefinerInterface` are internal: nothing an author
writes touches them. There is no build event, no declaration method, no
provider service, no policy filter and no output refiner; step 5 of
`REWORK.md` deleted them.

Every plugin host reads `#[UsesSurface]` from the plugin definition:
block, formatter, condition, action and field type base classes, and
`DataSurfacePluginForm` for any configurable plugin. A host builds the
named surface in its own verb's context (`configure`, `field_settings`),
supplies the target (the plugin's configuration array; the field config
for a field type) and answers static defaults from the surface's own
shape (`SurfacesInterface::defaults()`). A target has three verbs,
`load()`, `prepare()` (rehearse the write with storage's own checks, no
side effects) and `commit()` of what prepare returned; a dry run stops
after prepare and reports it.

Decisions live in `docs/decisions.md`: one entry per point the pattern
did not settle, with its reason. A new undecided point is added there,
marked open, not left as a code comment; code points at an entry with
`// Decision: see docs/decisions.md#<anchor>.` only where a reader at
that spot needs it.

Situations are what routes and tools are generated from. A route names
`_data_surface_surface` and `_data_surface_situation` and maps its
parameters onto the situation's by name (`SituationRoute`), gated by
`_data_surface_situation_access`; `DataSurfaceSituationForm` serves it.
`data_surface_tool` derives one tool per situation that can be asked
on its own, `data_surface:<surface>:<situation>`: its surface names a
target, no plugin uses it, and every `%key` in its permission can be
supplied by a parameter (`SurfaceCatalogue::standalone()`). The field
tools are `data_surface:field.instance:add` / `:reuse` / `:edit`; the
hand-written `field_add` / `field_update` are gone.
`data_surface.surface_catalogue` lists the static layer; `docs/catalogue.md`
is generated from it by `scripts/generate-catalogue.php`.

Subsurfaces are `attach()` (a fixed child, by class) and `attachBy()`
(a slot a sibling chooses, always open: every `#[SurfaceVariant]` for it
fills it, and the parent names none). Both return the map at the key,
which the owner labels with the core setters; `describe()` is for an
alter rewording a key it does not own. A service implementing
`SurfaceBuild\DerivedVariantsInterface` fills a slot for the values no
variant class fills (`data_surface_tool` derives a field type's settings,
and its storage settings, from its config schema). A child whose shape cannot be listed at all is
a `#[RefinesInput]` method on an `any` key returning a map. A child is
built by the same build step in its own frame and sealed into the parent's entry as a `SurfaceAttachment`,
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

The baseline as of this writing: **650 tests, 4033 assertions, 0 errors,
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
- A surface is declared in one place, its `#[Surface]` class. A plugin
  only names it with `#[UsesSurface]`; the host builds it.
- A `#[Surface]` class has no constructor and holds no service; a refiner
  points at a list with a constraint and the options resolver fetches.
  Alters, targets and access classes are autowired services and may hold
  services.
- `#[RefinesInput]` methods narrow a definition; an alter adds keys and,
  with `extendChoices()`, offers more values on a fixed choice list (the
  one widening verb). Nothing removes a key or a value: a remove-only
  policy is a later concept (`ROADMAP.md`).
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

- A surface class ends in `Surface` and its `#[Surface]` id is dotted
  (`node.type`, `field.instance`); an alter ends in `Alter`, a target in
  `Target`, an access class in `Access`.
- A class-swap adopter prefixes the swapped class with `Surface`
  (`AddressItem` → `SurfaceAddressItem`), and the hook implementation's
  docblock **must name the replacement class**, so grepping the original
  lands on the line that replaces it.
- The catalogue lists a plugin as `<host type>:<plugin id>` —
  `block:my_teaser`, `field_type:address`.
- A context's operation is a situation id (`add`, `edit`, `reuse`) or a
  host's own verb (`configure`, `field_settings`) and never carries
  identity. Identity is the `#[Surface(identity:)]` keys the context
  knows.

See `docs/declaring-a-surface.md` for all four in full.

## Where things live

```
src/                     Surface, builder (internal), definition map, host trait.
src/Surface/             The authoring API and attributes (Attribute/).
src/SurfaceBuild/        Discovery pass, registry, build step, adapters,
                         derived variants, catalogue.
src/Hook/                #[UsesSurface] into plugin definitions.
src/Pipeline/            Access, accept, validate, prepare, commit; results.
src/Target/              Where accepted values are written; storage shapes.
src/Form/                Form builder, host traits, the situation form.
src/Widget/ src/Plugin/  Definition to form element; resolvers, hosts, constraints.
src/Options/             Option sets, the resolver plugin base and manager.
src/Refinement/          Narrowing and choice sets.
modules/                 Seven experimental submodules; each has its own README.
tests/src/               Unit, Kernel, Functional, FunctionalJavascript.
docs/ scripts/           Published documentation; check.sh and the generator.
```
