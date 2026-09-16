# data_surface: from proof of concept to something real

Four independent audits (architecture and API, correctness, tests and
quality gates, Drupal conventions and security) were run on the module
as of 2026-09-15, after the overnight build. This document is the
synthesis: what is wrong, in what order to fix it, and the decisions the
fixes depend on. The goal is a module that survives review by core
maintainers as the reference implementation behind
https://www.drupal.org/project/drupal/issues/3622144, not a demo.

Every item below was verified against the code; items marked
*probed* were reproduced by running it. Nothing here has been changed
yet.

## The short version

The design holds. The layering (surface, pipeline, target, host trait,
thin base class) is right and the audits found nothing that argues for
a different shape. What they found is that several of the module's own
headline claims are not enforced by the code:

- **"Refinement can only narrow"** is checked by data type only. A
  refiner can widen choices, drop constraints, or clear `required`,
  and one demo refiner does widen.
- **"Immutable after seal"** has no guard, and the address adoption
  mutates a sealed surface.
- **"Cacheability is declared by resolvers"** is true, and then every
  resolver's metadata is thrown away before it reaches a form.
- **"One entry point for every caller"** is true, and the form path can
  silently reset stored values to defaults in three separate ways.

Plus the things a maintainer checks before reading a line of PHP: no
config schema for the demo block, no functional tests at all, no
quality gates in the repository, no root README.

## Part 1: blockers

Fix these before anything else. Each is a data-loss bug, a broken
invariant, or a hard stop for review.

### B1. Silent data loss on the form path (probed)

| # | Where | What happens | Fix |
| --- | --- | --- | --- |
| B1.1 | `Pipeline\DataSurfacePipeline::accept()` locked keys | A locked key takes the definition default and ignores the stored value even inside `submit()`, which just loaded it. Only the node type demo survives because its provider sets the default to the entity id before locking. | Prefer the current value, fall back to the default. |
| B1.2 | `Form\DataSurfaceFormBuilder::findSurfaceContainer()` depth limit 3 | Below depth 3 the marker is not found, extraction sees no keys, and `accept()` runs with no current values: every stored value becomes its default. | Throw when the marker is missing; pass the target's loaded values as `current` on the form path. |
| B1.3 | `Plugin\DataSurfaceWidget\MapWidget::extractValue()` | A map property with no rendered element (access denied, conditionally removed) is written as NULL. | Skip properties absent from the element, as the top level already does. |
| B1.4 | `Target\CompositeTarget::prepare()` | A surface key claimed by no child is accepted, validated, reported committed, and never written. A key claimed by two is written twice. | Refuse unclaimed and double-claimed keys at construction. |
| B1.5 | `Target\ConfigEntityTarget::prepare()` and `commit()` | Prepare mutates the caller's entity on a dry run and on failure; commit saves the held entity, not the artifact, so a later unrelated save persists the dry run. | Set values on a clone, make the clone the artifact, commit the artifact. |

### B2. The narrowing contract is a convention, not a contract

`DataSurface::refine()` compares only `getDataType()`. Required: a
constraint-subset check (the refined constraint set must include the
advertised one; a resolvable option set must be a subset of the
advertised set), and a deep clone before the chain runs, because the
shallow clone lets a refiner touching a map property mutate the sealed
surface (probed). Also carry `default_value` and `examples` forward
after each chain link; today a refiner that returns a fresh definition
drops both, so the rendered form and the accepted value disagree
(probed). Decision D2 below governs whether build-event refiners may
widen at all.

### B3. Immutability is claimed and not enforced

`DataSurfaceBuilder::seal()` has no guard and can run twice; the
factory can be handed the same builder twice and double-register
refiner chains. `SurfaceAddressItem::getFieldSurface()` adds twelve
map properties after the factory sealed and dispatched, so no
subscriber ever sees them. Fix both at once: a sealed flag that makes
every mutator throw, and `DataSurfaceBuilder::setPropertyDefinitions()`
so a map is complete before seal. That also removes the one documented
limit on attribute-declared surfaces.

### B4. Cacheability is computed and dropped

All six resolvers build correct `CacheableMetadata`; nothing consumes
it. A block form with a country or entity type select is cached with no
tags and no language context. `OptionsWidget::buildElement()` must
apply the set's metadata to the element, and `DataSurface` must
implement `CacheableDependencyInterface` so builders, refiners, and
resolvers can bubble into it. Refiners need a way to declare
cacheability (comment 4 on the issue). This is also where the
site-state versus per-request split gets a home.

### B5. Objects on form arrays

`#data_surface` and `#data_surface_target` put a surface (holding
refiners, often the plugin itself) and a target (holding services) into
the form array. The form cache serializes it. No target and no surface
uses `DependencySerializationTrait`; `FieldSettingsTarget` holds
callables typed `mixed`; the node type provider hands closures to
`ConfigEntityTarget`. Rule to adopt: ids on the element, objects
rebuilt in the callback. Replace callables with a small
`SettingsShapeInterface` implemented by a named class.

### B6. Missing config schema and missing gates

- No `block.settings.data_surface_demo`, and no schema for the four
  test plugins. Any functional test placing the block fails on strict
  schema, and a real site writes config no schema can validate.
- No `composer.json`, root `README.md`, `.gitlab-ci.yml`,
  `phpstan.neon`, `phpcs.xml`, `.cspell-project-words.txt`,
  `data_surface.api.php`. The sibling `tool` module ships all of them.
- No functional or JavaScript tests. `refreshSurface()` and
  `findSurfaceContainer()`, the AJAX path, have never executed.

### B7. Public API shape

`DataSurface`, `DataSurfaceBuilder`, `DataSurfacePipeline`, and
`DataSurfaceFactory` are final classes with no interfaces, and every
consumer type-hints the concrete class. `DataSurface::validate()`
takes an untyped optional manager and falls back to the container.
Extract interfaces, make validation a pipeline concern only, and make
the surface constructor internal with `seal()` the only construction
path.

## Part 2: should fix

Grouped by theme. Each group is one work package in Part 3.

### S1. Semantics stated once

The rules for absent, empty, NULL, `''`, and required differ by data
type by accident: `''` becomes NULL except for required strings;
`castBoolean()` maps `''` to FALSE but `'off'` and `'no'` to TRUE;
integers truncate floats (`'1.9'` becomes `1`, probed); a required
boolean FALSE, integer 0, or map `[]` passes as configured; `refine()`
treats FALSE and `[]` as known dependencies; a required empty list
violates through typed data's message, not the surface's. Define one
`isConfigured()` predicate and one casting table, use them in
`accept()`, `validate()`, and `refine()`, refuse shape mismatches in
`accept()` the way unknown keys are refused, and document the table in
one place. Decision D3.

### S2. Form generation

- `#ajax` is attached with no `#limit_validation_errors`, so touching a
  dependency validates the whole host form (probed).
- A map dependency gets `#ajax` on a `details` element, which never
  fires.
- `$container + $form` overwrites a host's own `#type`, attributes, and
  `#tree` (probed).
- Wrapper ids are deterministic per plugin id, so two instances of one
  block on a Layout Builder page collide.
- An empty option set renders an empty required select; `isApplicable()`
  is static, reaches the container, and runs for every definition on
  every build, and an unregistered constraint makes widget selection
  itself throw.
- Locked elements carry no explanation for screen readers; a required
  map has no required marker.
- `refreshSurface()` creates missing keys on the form by reference.

### S3. Targets

- `SchemaViolations` validates the whole config object, so a foreign
  violation blocks an unrelated submit under a key the surface never
  declared (probed). Keep only violations under mapped paths.
- `ConfigEntityTarget` turns every undeclared surface key into an
  entity property; `BaseFieldOverrideTarget` cannot clear an override
  (NULL skipped) and is fatal on a configurable field; `ConfigObjectTarget`
  dirties a factory-cached config; `PluginConfigurationTarget` holds a
  surface and also receives one in `prepare()`.
- `DefinitionMetadata` delegates to `getDefaultValue()` on field
  definitions, which require an entity argument and throw; the setter
  succeeds first, so the next read is fatal (probed). Check arity.
- `DataSurfaceConfigurationTrait` rebuilds the surface and fires the
  build event on every `defaultConfiguration()` and `getConfiguration()`
  call: one submit dispatched the event three times (probed). Memoize
  per instance and operation.

### S4. Security and access

- Tool permissions are string-built from raw agent input and a
  site-configuration admin is granted field administration. Add field
  and entity access checks on the resolved field config.
- The node type demo's operation link and routes use a hardcoded
  permission instead of entity access.
- `(string) $violation->getMessage()` flattens `TranslatableMarkup`,
  so placeholder markup is shown escaped to users. Keep message objects
  through `DataSurfaceResult`.
- The demo formatter renders field text through `html_tag` `#value`,
  which admin-filters rather than escapes. Use `#plain_text`.
- Untranslated strings: "%s is required.", "Unknown key", third-party
  container titles, summary concatenation, demo labels as bare strings.

### S5. Naming, conventions, packaging

- Three prefixes for one family (`DataSurface*`, `Surface*Base`, bare
  `*Target`) and four names for the options concept. Decision D5.
- Procedural hooks in two `.module` files; core 11 uses `#[Hook]`
  classes. Decision D1.
- `hook_entity_operation` signature is the pre-11.3 one.
- `LabeledChoice` is a parallel constraint rather than a `Choice`
  subclass, which is why the Tool bridge must add a duplicate `Choice`
  and why core `Choice` consumers cannot see it. Decision D4.
- `extendChoices()` is dead code from the settings era. Delete.
- `multiline` is a widget hint stored as a type setting. Record it as
  interim.
- `any` has no widget, so an unrefined `any` key throws at build.
- Event subscribers match the host class by exact equality, so a
  subclass loses the extension; host ids are not namespaced.
- `package: Development` everywhere, no `lifecycle` on demos, a
  misspelling of "refinable" in the module description, British
  spellings.

### S6. Tests

- No unit tests. The casting rules, the highest-value gap, need a
  data-provider matrix with negative cases.
- Six phpcs violations, twelve real phpstan findings, three anonymous
  classes in tests, duplicated helpers across five test classes,
  `#[RunTestsInSeparateProcesses]` on all nineteen classes without a
  reason, a reflection assertion on where `defaultSettings()` is
  declared, a comparison recorder that is a generator disguised as a
  permanently skipped test.
- The node type form (200 lines) and the uniqueness validator's actual
  refusal have no coverage.

## Part 3: work packages, in order

Each package is one unit of work with its own green suite. Later
packages assume earlier ones. Names refer to the findings above.

1. **Gates first.** `composer.json`, root README, `phpcs.xml`,
   `phpstan.neon` with a bootstrap that tolerates the optional `tool`
   and `address` dependencies, `.cspell-project-words.txt`,
   `.gitlab-ci.yml` modelled on the tool module, config schema for the
   demo block and the four test plugins. Fix the six phpcs and twelve
   phpstan findings so the gates start green. (B6, S6 partly)
2. **Interfaces and construction.** `DataSurfaceInterface`,
   `DataSurfaceBuilderInterface`, `DataSurfacePipelineInterface`,
   `DataSurfaceFactoryInterface`, `DataSurfaceFormBuilderInterface`;
   validation removed from the surface; internal constructor; sealed
   flag; `setPropertyDefinitions()`; the address item builds its map
   before seal; `extendChoices()` deleted; naming per D5. (B3, B7, S5)
3. **Semantics.** Done. `ValueState::isConfigured()` and the pipeline's
   casting table, applied in accept, validate, refine; shape mismatches
   refused via `ShapeMismatchException`; B1.1 (locked keys prefer the
   stored value) landed here; the 100-row unit matrix in
   `tests/src/Unit/DataSurfacePipelineCastingTest.php` is the
   specification and `docs/semantics.md` the user-facing rules. Open
   note: a payload-level kernel case for shape mismatch once the form
   path settles, and constraint messages are still flattened to strings
   (package 7 owns message objects end to end). (S1, S6, D3)
4. **Form path data loss and AJAX correctness.** Done. B1.2, B1.3, the
   `#limit_validation_errors` (via a container `#process`, the only
   moment the parents exist), the details dependency skipped with
   reason, the host-form merge rule, unique wrapper ids, instance-level
   widget applicability with memoized option sets, empty option sets
   declining, locked element description, and both browser tests
   written. The functional test also caught a real bug kernel tests
   could not: absolute `#parents` read against a `SubformState`
   resolved to the wrong path, so every real block settings submit
   validated against NULLs. Open, environmental: the JavaScript test
   needs a webdriver service in ddev, and the node module cannot
   install in functional tests on this checkout (core's own node
   functional tests fail identically). Open, code: the formatter
   trait's `settingsForm()` merges into the `$form` Field UI passes,
   which is the whole display form, so the settings form nests the
   display form inside itself on a real site (kernel tests pass `[]`);
   and the demo block cannot be constructed on a site without the node
   module because its declared default violates its own `PluginExists`
   constraint at construction (package 9 owns both). (B1, S2, S6)
5. **Narrowing and cacheability.** Done. The D2 contribution model
   (contributors, per-contribution narrowing with the union, policy
   filters), the conservative narrowing check in
   `Refinement\Narrowing`, deep clone, metadata carried through every
   link, refiners and filters as core cacheable dependencies merged by
   `refine()`, the surface itself a cacheable dependency applied to the
   form container, `docs/refinement.md`. Open: D6 dotted paths (a
   contributor cannot yet refine a key it mounted under
   `third_party_settings`), and the narrowing check does not descend
   into map properties. (B2, B4, D2)
6. **Targets.** Done. B1.4 (every key claimed exactly once, so a
   composite can no longer mirror one key into two stores), B1.5
   (prepare on a clone, commit saves the artifact), mapped-path-only
   schema violations with an opt-out, override clearing via NULL,
   getter-arity as the arbiter in `DefinitionMetadata`, memoized
   surfaces in the configuration trait (one build event per instance),
   `SettingsShapeInterface` with `AddressSettingsShape`, ids on
   elements with rebuild in the callbacks and
   `DependencySerializationTrait` as the net, reflection gone from the
   tool locator behind `FieldSurfaceProviderInterface`, `docs/targets.md`.
   Open: `PluginConfigurationTarget` contributor dependencies (a
   `@todo` pointing at `DefinitionMap::byContributor()`), and
   `FieldSurfaceProviderInterface` sits outside the D5 prefix. (B5, S3)
7. **Security and i18n.** Done. Tool access asked twice (checkAccess
   and doExecute), field update through field entity access, the node
   type demo behind entity access plus its own restricted permission on
   routes and the operation link alike, violation messages as objects
   through to `setError()` with `ViolationSummary` capping the three
   exception boundaries, `#plain_text` in the formatter, and every
   untranslated string closed (label constants became static methods,
   since PHP forbids `new` in constant initializers). (S4, D7)
8. **Conventions.** Done except one blocked half: `#[Hook]` classes,
   `LabeledChoice extends Choice`, event matching by `is_a()`,
   namespaced host ids, `deriver` on the two attributes,
   `data_surface.api.php`. The Tool bridge's duplicate `Choice` must
   stay until the Tool API matches constraints by `instanceof` rather
   than plugin id (its normalizer keys on the `Choice`/`AllowedValues`
   names); recorded as a Tool follow-up in the bridge's docblock. (S5)
9. **Test hygiene and coverage.** Done, with one reversal: the audit
   was wrong about process isolation. Core 11.3 deprecates kernel tests
   WITHOUT `#[RunTestsInSeparateProcesses]` and enforces the attribute
   with its own static analysis rule, so it stays on every concrete
   test class and off the new abstract base. Shared kernel base class,
   anonymous classes named into the test module, the comparison
   recorder split into `scripts/generate-comparison.php` plus a drift
   assertion (no skipped tests remain), unit tests for the option set,
   results, and exceptions, functional tests for the standalone form,
   the Field UI address round trip (green locally on entity_test), and
   node type add/edit (needs node; blocked locally by this checkout's
   broken node install, runs in CI). Also fixed here: the formatter
   settings form no longer swallows the whole display form, and the
   demo block's default entity type is `user` so it constructs on any
   site. Kernel tests now use `#[Group]` attributes. (S6)
10. **Documentation.** Done. Twelve-page `docs/` tree with mkdocs nav
   (flat files, nav-grouped), README reduced to what/why, install,
   quick start, and glossary, PLAN.md and ADOPTION.md banked as design
   history with their false claims corrected, api.php cross-referenced,
   submodule READMEs in one shape, the pages job enabled in CI. The
   mkdocs build itself was validated by script only; no mkdocs binary
   exists locally. (B6, S5)


Packages 1 and 2 land before any new adopter is written. Packages 3
through 6 are the substance. Package 5 is the one that answers the
issue's reviewers directly.

## Part 4: decisions

Taken 2026-09-15. The original questions and recommendations follow
each decision for the record. Guiding principle from the owner: all of
this is eventually pushed into core systems, so choose what core would
choose.

- **D1: `core_version_requirement: ^11.3 || ^12`.** `#[Hook]` classes,
  autowiring, and the 11.3 APIs already in use are all allowed.
- **D2: widen at build, narrow at refine, per contribution.** Amended
  after review of where "narrow only" breaks (a refiner that
  enumerates erases another module's build-time widening; a
  contributed key has the same problem). Three roles, one permission
  each:
  1. *Contributors* add at build time: definitions under a provider
     namespace (the third-party mount; never bare top-level keys),
     choices on existing keys (`extendChoices` revived as the API),
     refiners scoped to their own additions, and defaults on their own
     definitions. The owner is the first contributor. A second
     contributor adding a value that already exists throws.
  2. *Contribution refiners* narrow only what their contribution added,
     may depend on any key, and the refined surface is the union of
     contributions. This replaces the single refiner chain.
  3. *Policy filters* run after the union and may only remove, checked
     as a subset of what they received.
  The narrowing check is conservative: adding a constraint, reducing
  a choice list to a subset, and comparator-backed tightening (`Length`, `Range`,
  `Choice`) pass; changing type or required, removing a constraint, or
  replacing a constraint without a comparator are refused. Two rules
  follow: for config-backed surfaces widening is a schema alter and the
  surface follows, since storage gates the write anyway; and the full
  advertisement always comes from the factory, the attribute alone is
  for detection and the static refinement map. Seal gains a cycle check
  across the whole refinement map, and `PluginConfigurationTarget`
  must report contributing providers as dependencies. The extras demo
  becomes the first test of a contribution with its own refiner.
- **D3: implement the recommended semantics and review them in
  practice.** `''` and NULL are "not configured" for every type and
  accept coerces both to NULL; required means configured, so FALSE, 0
  and `[]` count as values; the required violation is always the
  surface's own message; casting never loses information. The unit
  test matrix in package 3 is the specification to review.
- **D4: `LabeledChoice extends Choice`, and it is module-agnostic.**
  Every `Choice`-aware consumer sees it; the upstream ask becomes
  "labels on Choice". Because it is expected to land in core and be
  used well beyond surfaces, the constraint, its validator, and
  `LanguageExists` must carry nothing specific to this module: no
  surface types in their signatures, options named as core would name
  them (`choices` keyed by value, `labels`, `descriptions`), messages
  matching core's `Choice`, and no dependency on the options resolver.
  The resolver layer consumes them; they never know it exists. The
  same rule applies to the resolver plugin type itself: it should read
  as "options from constraints", a core-shaped service, not a surface
  feature.
- **D5: the `DataSurface` prefix on everything host code touches**, plus
  a glossary in the README. Base classes plugins extend become
  `DataSurface*Base`; the options concept gets one name (options
  resolver: plugin type, attribute, base class, service); interfaces
  and the value objects hosts type-hint carry the prefix. Collaborators
  that live only inside their own namespace (`Target\StateTarget`,
  `Options\OptionSet`) stay short, since core relies on namespaces
  rather than prefixes for those. `NodeTypeSurfaceProvider` implements
  the provider interface.
- **D6: dotted refinement paths**, with
  `third_party_settings.<provider>.<key>` as the first case. Settled as
  syntax now; implemented with `mount()`, not in this plan.
- **D7: demos stay.** They are where architectural decisions are seen
  in practice, marked `lifecycle: experimental`, and the node type demo
  gets its own permission.

### The questions as asked

- **D1. Minimum core version.** `^10.3 || ^11` is declared but the
  code already uses 11.3 APIs (`NodePreviewMode`, `ToConfig`, the new
  `hook_entity_operation` signature). Recommend `^11.1 || ^12`, which
  also allows `#[Hook]` classes and autowiring.
- **D2. May build-event alters widen?** Today the extras refiner widens
  a choice list after seal, by accident of the weak check. Recommend:
  alters may add definitions, mount third-party keys, and add refiners,
  but a refiner may never widen; the narrowing check applies to every
  link, including alter-added ones. The builder handed to the event
  should expose only that additive API.
- **D3. Empty and required semantics.** Not yet reviewed in practice.
  Recommend: `''` and NULL are
  "not configured" for every type, always coerced to NULL by accept;
  required means configured, so FALSE, 0, and `[]` are configured
  values, not absences; a required violation is always the surface's
  own message; casting never loses information (a fractional string on
  an integer is left for the constraint to refuse).
- **D4. `LabeledChoice` as a `Choice` subclass.** Recommend yes: every
  `Choice`-aware consumer, including the Tool API normalizer and core's
  own `AllowedValues`, then sees it, and the upstream proposal becomes
  "add labels to Choice" rather than a new constraint.
- **D5. Naming.** Recommend one prefix, `DataSurface`, for every class
  in `src/` (`DataSurfaceBlockBase`, `DataSurfaceOptionsResolver`), one
  glossary in the README (surface, definition, refiner, resolver,
  target, host, provider), and `NodeTypeSurfaceProvider` either
  implementing the provider interface or renamed.
- **D6. Refinement path syntax.** Needed before `mount()` and before
  any dependency inside a map. Recommend dotted paths in the refinement
  map and in `getRefinementDependencies()`, with
  `third_party_settings.<provider>.<key>` as the first existing case.
- **D7. Demo scope.** The demos double as the functional test
  fixtures. Recommend keeping all five submodules, marking them
  `lifecycle: experimental`, and giving the node type demo its own
  permission rather than reusing `administer content types` through a
  second route nobody has reviewed.

## Part 5: deliberately not in scope

- Nested surfaces (`mount()`) beyond settling D6.
- Storage settings for field types and the `$has_data` lock (Group C,
  second half). Note B1.1 must land first or that lock loses data.
- Field widgets collecting content (Group G).
- JSON Schema emission (phase 3). Package 5's cacheability and package
  2's interfaces are its prerequisites.
- Retiring `config_surface`; it stays as the comparison baseline until
  the functional tests exist.

All ten packages have landed. What comes next lives in ROADMAP.md,
which consolidates the open threads above with the clean-room design
review and the Laravel review.
