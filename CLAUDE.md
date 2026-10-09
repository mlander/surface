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
with a static `defineInputs()` (and, on `HasOutputsInterface`, a static
`defineOutputs()`), static `#[RefinesInput]` methods and `#[Situation]`
static methods; it is never instantiated. Another module changes it
with an alter in `src/SurfaceAlter/` carrying `#[AltersSurface]`. A
plugin names its surface with `#[UsesSurface]`, or, with no argument,
is its own surface: the plugin class implements `SurfaceInterface`, is
found through its plugin definition (`SurfacePlugins::ownSurfaces()`),
gets the id `<host type>:<plugin id>` unless it carries `#[Surface]`,
and alters name it by the plugin class (the demo formatter).
Everything else is discovered by `SurfaceBuild\SurfaceCollectorPass` and built
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
place that can reach the test database. The browser tests
(`tests/src/FunctionalJavascript`) drive Chrome through the ddev add-on
`ddev/ddev-selenium-standalone-chrome` (`ddev add-on get
ddev/ddev-selenium-standalone-chrome`, then `ddev restart`): a
`selenium-chrome` service with a webdriver on port 4444, and, in the web
container's environment (`.ddev/config.selenium-standalone-chrome.yaml`),
`MINK_DRIVER_ARGS_WEBDRIVER` pointing at it and `SIMPLETEST_BASE_URL=http://web`,
the site under a name both containers reach. The command takes those from
the environment:

```
ddev exec bash -c 'cd /var/www/html/web && SIMPLETEST_DB=mysql://db:db@db/db \
  ../vendor/bin/phpunit -c core modules/custom/data_surface'
```

`http://localhost` still serves kernel and functional tests, but the
browser would look for the site in its own container; leave the base URL
to the environment.

The baseline as of this writing: **751 tests, 7745 assertions, 0 errors,
0 failures**. The test and assertion counts drift upward as work lands
and are not the thing to check. **No test may error and no test may
fail**, the browser tests included; any error or failure is a real
regression.

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
these tests again.

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

## The React submodule and its app

The served contract's emitter is the main module's:
`Contract\ContractEmitter` (`data_surface.contract_emitter`) turns a
sealed surface and its values into JSON Schema 2020-12 with an
`x-surface` keyword per property, each slot an `allOf` of `if`/`then`
on its deciding sibling. It names no widget unless asked
(`emit(..., widgets: TRUE)`); `data_surface_tool`'s contract panel shows
it without, beside what the Tool API can advertise (which flattens a
slot, and is what `tool:info` prints). `modules/data_surface_react`
serves it with the widget hints, through the main module's service:
`/surface-api/{surface}/{situation}` serves it, `/refine` re-narrows it
(the AJAX rebuild's equivalent, emitted from the form builder's `refinementOverlay()`, so an orphan is
held unanswered and listed `stale`), `/validate` runs the pipeline
dry and `/submit` writes, answering a refusal as 200 data
(`committed: false`), a write with the rebuilt contract, and a create
with the situation the created thing now lives at (`created`, from the
outputs or identity, checked by building that situation). The GET
contract carries a `fingerprint` of the stored values; a submit that
sends it back is refused when storage changed since, and one that sends
none is not checked (opt-in; the app sends it).
`/surface-react/{surface}/{situation}` renders it with a React app.
`docs/served-contract.md` has the format, `docs/decisions.md` (Served
contract) the seven points it settled.

The app is `modules/data_surface_react/app/`: Vite, React 18 and
TypeScript, every version pinned exactly. Run npm on the host, never
inside ddev:

```
cd modules/data_surface_react/app
npm ci          # node_modules/ is ignored
npm test        # Vitest + React Testing Library
npm run build   # tsc --noEmit, then dist/app.js and dist/app.css
```

**`dist/` is committed**, so the module works on a site with no Node,
and it must be rebuilt and committed with every change under `app/`.
Nothing checks that the two agree (a timestamp test would be fragile),
so the rule is this sentence. `npm test` is not part of
`scripts/check.sh`; run it beside the check when `app/` changed.
`node_modules/` is excluded from phpcs and phpstan, and cspell skips it,
`dist/` and `package-lock.json`.

## Conventions that are load-bearing

- Contracts are objects; payloads are arrays. Never pass a definition as an
  array or a value bag as an object.
- A surface is declared in one place, its `#[Surface]` class, or the
  plugin class that is its own surface. A plugin names it with
  `#[UsesSurface]`; the host builds it.
- A surface is static: `defineInputs()`, `defineOutputs()` and its
  `#[RefinesInput]` methods are `public static`, called on the class, so
  it has no constructor, holds no service and is never instantiated; a
  refiner points at a list with a constraint and the options resolver
  fetches. A surface's refiner is static; an alter's is an instance
  method. Alters, targets and access classes are autowired services and
  may hold services (`docs/decisions.md#a-surface-is-static`).
- `#[RefinesInput]` methods narrow a definition; an alter adds keys and,
  with `extendChoices()`, offers more values on a fixed choice list (the
  one widening verb). Nothing removes a key or a value: a remove-only
  policy is a later concept (`ROADMAP.md`).
- Requiredness appears only when it says something: `setRequired(TRUE)` on
  the keys that must be configured, nothing at all on the rest, because
  core data definitions are optional by default.
- Violations are message objects end to end, from the pipeline to the form,
  never pre-rendered strings.
- Translatable strings: static context (a surface, a static protocol
  method, an enum, a value object's static helper) calls the global
  `t()` with a literal string — the same lazy `TranslatableMarkup`, and
  extractable; a container-built class of ours injects
  `string_translation` and calls `$this->t()`; `new TranslatableMarkup`
  appears only inside attribute arguments, where a call is not allowed.
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
src/Contract/            The served contract's emitter (JSON Schema).
src/Form/                Form builder, host traits, the situation form.
src/Widget/ src/Plugin/  Definition to form element; resolvers, hosts, constraints.
src/Options/             Option sets, the resolver plugin base and manager.
src/Refinement/          Narrowing and choice sets.
modules/                 Ten experimental submodules; each has its own README.
tests/src/               Unit, Kernel, Functional, FunctionalJavascript.
docs/ scripts/           Published documentation; check.sh and the generator.
```
