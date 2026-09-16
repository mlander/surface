# data_surface: plan, gaps, and what carries over

> **Design notes from the build.** The current documentation lives in
> [`docs/`](docs/). Kept for the reasoning record.

The standalone spin-off of the `config_surface` PoC: no Tool API
dependency, built on core's `DataDefinitionInterface`. The rule for this
module is to use core's definitions to their fullest before extending
anything; every place core's vocabulary falls short is recorded here as a
gap, with the interim spelling we chose and the core issue it maps to.

## Core DataDefinition gaps (and interim spellings)

| Gap | Interim spelling here | Upstream |
| --- | --- | --- |
| No default value on definitions | Defaults live **on the definition**, under the definition array key `default_value` the core draft proposes, written and read through `DefinitionMetadata` (`setDefaultValue()` / `hasDefaultValue()` / `getDefaultValue()`). Each accessor delegates to the definition's own core method when it exists, so the class becomes a pass-through the day core lands. `DataSurfaceBuilder::setDefault()` is sugar over it; `DataSurface::getDefault()` / `getDefaultValues()` read through it and assemble a complex definition's default from its property definitions. | Core issue drafted (defaults on data definitions) |
| No locked/immutable concept | **Surface-level**: `DataSurfaceBuilder::lock()`, `DataSurface::isLocked()`. Arguably better than a definition flag — locking is context (add vs edit), not an intrinsic property. | Possibly none needed; revisit |
| No labeled choices (Choice holds bare values) | `LabeledChoice`, now a `Choice` subclass carrying `choices` + `labels` + `descriptions` (map spelling still accepted when `labels` is absent; integer-valued sets must use the canonical spelling), resolved to options by the resolver service. `LanguageExists` follows core's existence-constraint style (`allowLocked`, placeholder message). | Core issue drafted: labels on Choice (issue-core-labeled-choice.md at project root) |
| No `text`/multiline string type | Definition **setting** `multiline` on a string definition; `StringWidget` renders a textarea. (Tool API registered its own `text` data type; settings avoid that.) | Core issue candidate: `text` primitive, parity with config schema's `type: text` |
| No examples | Definition array key `examples`, the spelling the core draft proposes, through `DefinitionMetadata::setExamples()` / `getExamples()` — same pass-through to the core methods once they exist. `StringWidget` and `NumberWidget` render the first example as `#placeholder`. | Core issue drafted (examples) |
| No `patternProperties`/`additionalProperties` on maps | Not needed by current consumers; maps are closed here | [#3556242] |
| Required conflates non-empty/nullable/present | Same pragmatic semantics as the PoC: NULL/'' is "not configured" | Union/nullability architecture discussion |
| `required` defaults differ from the input world | Core's `DataDefinition` is **optional by default** (`isRequired()` falsy unless set); Tool's `InputDefinition` defaulted required. Providers here must say whether a definition is required — caught live by the first test run. | None; a convention to document |

## What carries over from the Tool API era, and what improved

**Carried over intact (proven in the PoC):**
- The surface model: immutable `DataSurface` (definitions + refinement map
  + refiner chains + narrowing contract enforced against the advertised
  definition, `any` escape hatch), mutable `DataSurfaceBuilder` sealed
  after a build event, `DataSurfaceFactory` as the single alter-aware
  entry point, third-party mounting under `third_party_settings`.
- The refinement-aware form layer: refine-before-build, AJAX rebuild on
  refinement dependencies via a wrapper marker, path-aware violation
  flagging, locked = disabled render + extraction authoritative from the
  surface.
- `DataSurfaceRefinerInterface` replaces tool's
  `InputDefinitionRefinerInterface` with the same shape and contract.

**Improved over the Tool API adapters (the ledger gaps, fixed natively
instead of masked):**
1. **Value-based widget API.** Widgets are
   `buildElement($definition, $value)` / `extractValue(...)` — plain
   values in and out, no typed-data objects threaded through the form
   path and **no SubformState created** anywhere. The one place a host's
   own subform state is met, it is unwrapped to the complete form state
   the absolute `#parents` are absolute in, so the entire class of
   subform coordinate-frame landmines from the PoC cannot occur.
2. **No `value` wrapper nesting.** A definition's element is the input
   (or container) itself, so submitted trees are already in the clean
   shape: no flatten step, no `reduceRawValue`, nothing for host
   protocols to mangle.
3. Defaults populate recursively through maps (tool's MapAdapter
   discarded them).
4. Casting lives in the pipeline's `accept()` (numeric strings become
   integers and floats, checkbox spellings become booleans, empty means
   "not configured"), and the form path calls it once the widgets have
   collected the raw tree — tool returned raw form strings, and casting
   in the widgets would have left the raw strings in every payload that
   did not come from a form.
5. Optional selects get an empty choice natively.
6. Constraint-to-element mapping: Length → `#maxlength`, Range →
   `#min`/`#max`.
7. Locked handling native in build + extraction.

**Deliberately not carried over (yet):**
- Schema emission/serializer (Tool API's normalizer). Phase 2; the
  surface holds plain core definitions, so core's `json_schema` work is
  the natural emitter to lean on.
- The transform-event coercion pipeline. Casting through typed data
  covers the form path; revisit if non-form consumers need more.
- List/multiple-value widgets. Tool's ListAdapter is an interactive
  add-more widget with its own state; that is its own project. Partly
  covered since the options work: a list definition whose item
  definition names a value list renders as one multiple select, and
  `accept()` normalizes what it submits into a clean list. What is not
  covered is a list of anything else, which is where the add-more
  machinery starts.

## Labeled options: one declaration

The problem: a value/label list is needed twice, once as a constraint
(is this value allowed) and once as form `#options` (what do the values
mean), and today those are two spellings that can drift: a `Choice`
constraint listing bare values and a `choice_labels` setting repeating
them as keys. Core has the same split. `OptionsProviderInterface` hangs
labels off the instantiated data object, not the definition, and the
`AllowedValues` validator only works because it can reach that object
at validation time. Nothing that works from definitions alone can see
labels, which is the "validation without meaning" line in the issue.

### Principle

The constraint is the single source of truth. Options are *derived*
from constraints, never declared beside them. Two mechanisms, both
small:

1. **A constraint that carries labels.** `LabeledChoice`, a Drupal
   constraint plugin whose `choices` option is a `value => label` map
   (labels may be `TranslatableMarkup`). Its validator has exactly
   `Choice` semantics over the keys; labels play no part in validation.
   Optional `descriptions` map (value => text) for per-option help,
   which JSON Schema can carry too.

   ```php
   $definition->addConstraint('LabeledChoice', ['choices' => [
     0 => new TranslatableMarkup('Disabled'),
     1 => new TranslatableMarkup('Optional'),
     2 => new TranslatableMarkup('Required'),
   ]]);
   ```

2. **An options resolver.** A small plugin type,
   `DataSurfaceOptionsResolver`, with `applies(Constraint): bool` and
   `resolve(Constraint, DataDefinitionInterface): OptionSet` where an
   `OptionSet` is `value => label` plus `CacheableMetadata`. A
   `DataSurfaceOptions` service walks a definition's constraints, asks
   each resolver, and returns the first option set (or the intersection
   when more than one constraint resolves, since every constraint must
   hold). Stock resolvers:

   | Constraint | Values from | Labels from | Cacheability |
   | --- | --- | --- | --- |
   | `LabeledChoice` | its keys | its map | permanent |
   | `Choice` | its list | the value itself | permanent |
   | `PluginExists` | the named manager's definitions (filtered by `interface` when set) | array key, then public property, then `getLabel()`/`getAdminLabel()` on definition objects (core's `EntityType` keeps its label behind an accessor; without the third spelling an entity type select shows machine names) | the manager's cache tags |
   | `EntityBundleExists` | bundle info for the entity type | bundle labels | `entity_bundles` tag |
   | `LanguageExists` | the language manager; locked languages only when the constraint asks for them | language names | `config:configurable_language_list` tag |
   | `Country` (the address module's) | `address.country_repository`, narrowed to the constraint's `availableCountries` when it names any | localized country names | `countries` tag, `languages:language_interface` context |
   | `ExtensionExists` | installed extensions of the type | extension names | `config:core.extension` |
   | `ConfigExists` (prefix form) | config names matching the prefix | the config entity label when it is one | `config:` list tags |

   The resolver is the only place that knows how to turn a constraint
   into a list, so widgets, schema emission, and any future consumer
   share it.

### The lesson: declare the constraint, resolve the list

Prefer an existence or validity constraint plus a resolver over a
`LabeledChoice` whose choices are assembled at build time. The address
port is the worked example. Its countries and languages started as
labeled choices filled from the country repository and the language
manager, and the cost was not the code: a definition that cannot be
written down without services forces the whole surface to be built at
runtime, in a service, away from the class it describes. Said as the
`Country` and `LanguageExists` constraints instead, the definitions
became literal enough to sit in a `DataSurfaceAware` attribute on the
field item itself, harvestable without instantiation, and the live
lookup moved to the one place that already answers for freshness. So:
when a list is "every one of a kind that this site has", say that, and
write the resolver; keep `LabeledChoice` for a vocabulary that really is
literal, such as hidden/optional/required.

`LanguageExists` is a constraint this module adds because core has none
— core validates that a plugin, a bundle or an extension exists, but
nothing says "this is a language code" — and it is the obvious fourth
existence constraint to propose upstream beside the three that exist.

### Consumers

- **`OptionsWidget`** applies when the resolver returns an option set
  for the definition, and reads `#options` from it. The `choice_labels`
  setting and the `Choice.choices` special case are deleted. Optional
  definitions still get the empty option from the widget.
- **Validation** is unchanged: the constraints validate as they always
  did. There is nothing to keep in sync because there is only one list.
- **Refinement** narrows by replacing the constraint with a subset:
  `addConstraint('LabeledChoice', $subset)` (Drupal's `addConstraint`
  replaces by name). The narrowing check compares option keys, and a
  refiner that resolves a dynamic set (bundles for the chosen entity
  type) may emit a `LabeledChoice` with the resolved labels so the
  refined definition is self-contained and cacheable as plain values.
- **JSON Schema emission (phase 3)** maps an option set to `enum` for
  the keys and, when labels differ from values, the standard
  `oneOf: [{const, title, description?}]` form, so labels reach
  decoupled clients and agents without a Drupal-specific keyword.
- **Field item surfaces (ADOPTION.md group G)** bridge core's existing
  mechanism: at surface build time an `AllowedValues` constraint on an
  item whose class is an `OptionsProviderInterface` is resolved through
  `getSettableOptions($account)` into a `LabeledChoice`, tagged
  per-user because settable options are access-dependent. That is the
  request-context half of the cache split from comment 4 on the issue.

### What this replaces and what it maps to upstream

- Deletes the `choice_labels` setting and the `Choice`-only check in
  the options widget.
- Restores the block demo's entity type select without Tool API: the
  `PluginExists` resolver does what the tool select adapter did.
- Upstream: the natural core landing is either labels on `Choice`
  itself or `LabeledChoice` as a core constraint, plus resolvers for
  the four existence constraints. Both fit #3557353 (metadata on
  definitions) as the constraint-carried variant of that metadata. The
  resolver plugin type is the piece to propose alongside, since it is
  what lets `OptionsProviderInterface` semantics exist at definition
  level. Interim names are provisional; only the resolver service and
  the widget reference them, so a rename is contained.

The node type demo port retired the last cosmetic option list:
`preview_mode` carries `NodePreviewMode::asOptions()` on a
`LabeledChoice`, so the form's options and the validating list are one
declaration.

### Build order

1. **Done.** `LabeledChoice` constraint and validator, kernel test
   proving `Choice` parity.
2. **Done.** `DataSurfaceOptionsResolver` plugin type, `OptionSet`, the
   `DataSurfaceOptions` service, resolvers for `LabeledChoice` and
   `Choice`; `OptionsWidget` switched to the service; `choice_labels`
   removed and its tests moved.
3. **Done.** `PluginExists` and `EntityBundleExists` resolvers, needed
   by the block demo port; the remaining two (`ExtensionExists` and
   `ConfigExists`) when a consumer appears.
4. **Done.** The `LanguageExists` constraint and its resolver, and the
   `Country` resolver for the address module's constraint — the pair
   that turned the address field's surface into a static declaration on
   its own item class.
5. The `AllowedValues` bridge lands with the field widget work.

Fits after the pipeline core and before the demo ports in the phase 2
sequence, since the block port needs step 3.

## Semantics

The accept/validate rules (what is configured, the casting table,
required, shape mismatches, locked keys) are enforced by
`Pipeline\ValueState::isConfigured()` and the pipeline's casting table,
specified by the unit matrix in
`tests/src/Unit/DataSurfacePipelineCastingTest.php`, and documented for
users in `docs/semantics.md`. Two adopter-visible consequences: a
cleared required string is NULL (never `''`), and a map's declared
`default_value` merges over its property defaults rather than
replacing them. A list key absent from a payload keeps the stored
list; `[]` empties it.

## Phases

1. **Done here:** surface layer + widget plugin type + form builder +
   kernel coverage, no dependencies beyond core.
2. Port the demos (block, formatter shim, node type add/edit) onto
   data_surface; retire config_surface.

   **Progress: the progressive-adoption layer exists.**
   `DataSurfaceProviderInterface` (with the operation argument for
   hosts with named forms), the `DataSurfaceAware` attribute for
   statically declared surfaces, the `data_surface.awareness` service
   that reads a class without instantiating it, and the traits holding
   all of the logic: `DataSurfaceConfigurationTrait` for
   `ConfigurableInterface`, `DataSurfaceHostFormTrait` for the three
   form stages, `DataSurfacePluginFormTrait` naming those three for
   `PluginFormInterface`, `DataSurfaceFormatterTrait` for the Field UI
   settings protocol, and `DataSurfaceFieldTypeTrait` for field type
   settings (the address port is its first consumer). On top of them the thin per-host base classes
   `DataSurfaceBlockBase` and `DataSurfaceFormatterBase`, plus
   `DataSurfacePluginForm`, the one generic form class any plugin can
   list under any operation. A plugin adopting surfaces now holds its
   declaration, its refiner if it has one, and its output.

   **Progress: the demos are ported.** Block, formatter and the
   standalone form live in `modules/data_surface_demo`, the build-event
   mount and chained refiner in `modules/data_surface_demo_extras`, the
   content type add/edit exemplar in `modules/data_surface_demo_node_type`,
   and the address field type adoption in `modules/data_surface_address`.
   The ported block lost its four form and default methods; the
   formatter lost `defaultSettings()` entirely. What remains of phase 2
   is retiring `config_surface`.
3. JSON Schema emission from surfaces via core's serializer work;
   ingestion after.
4. List widgets, and whatever the union/nullability conversation
   produces.
