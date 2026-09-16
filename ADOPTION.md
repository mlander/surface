# data_surface: the shared pipeline and where core can adopt it

> **Design notes from the build.** The current documentation lives in
> [`docs/`](docs/). Kept for the reasoning record.

Companion to PLAN.md. Two things live here: the design of the one entry
point every consumer calls to validate and store surface values, and a
catalogue of the places in core whose "collect values, validate, store"
pattern that entry point can replace. Everything in the catalogue was
verified against core 11.4 source; paths are relative to `web/core`.

Context: https://www.drupal.org/project/drupal/issues/3622144 names the
pipeline (accept, validate, prepare) and comment 5 argues for prepared
values as a preview artifact and for a commit step that can be pointed at
a temporary storage. This document is the concrete shape of both.

## Part 1: the pipeline

### What exists today, honestly

- **Validate** is shared: validation refines against the values and
  returns path-aware violations, and every consumer calls it. (It has
  since moved off the surface and onto the pipeline, as
  `DataSurfacePipelineInterface::validate()`, so that a surface stays
  pure data and reaches no service.)
- **Accept** exists only on the form path. Casting and empty-to-NULL live
  in the widgets' `extractValue()`, so a caller that hands the surface a
  JSON payload gets no coercion and validates raw strings.
- **Prepare and commit** are bespoke per host. The PoC block and formatter
  write into `$this->configuration`, the standalone demo writes to State,
  and the node type provider has an `apply()` that fans out to an entity
  and two base field overrides. No shared signature.

The requirement, from the issue: whether values arrive through Form API, a
service endpoint, a config action, or an AI call, they are coerced,
validated, and transformed to storage shape by the same code, and the
storage write is separable so it can be previewed or redirected.

### Stages

```
input ──accept──▶ values ──validate──▶ values ──prepare──▶ prepared ──commit──▶ storage
        (surface)          (surface)            (target)              (target)
```

1. **accept(surface, input, current)**. Produces a complete, typed value
   set from partial, untyped input. Merges in order: surface defaults,
   then `current` (the stored values, so partial updates are legal), then
   the input cast through each definition (numeric strings to int/float,
   checkbox strings to bool, `''` to NULL for optional and non-string
   keys, recursion through maps). Locked keys take the surface's value and
   ignore input. Unknown keys are refused here, not silently dropped, so
   an agent that misspells a key learns about it.
2. **validate(surface, values)**. Refine against the accepted values, then
   run constraints. Already exists; unchanged.
3. **prepare(surface, values, target)**. The target transforms surface
   values into its storage shape without writing: a plugin configuration
   array with host-owned keys preserved, a `Config` object with properties
   set, an unsaved config entity, a display component array. Returns a
   `PreparedValues` object holding the artifact, the accepted values, and
   calculated dependencies. This is the preview artifact from the issue:
   everything a renderer or a diff needs, nothing stored.
4. **commit(prepared, target)**. Writes the artifact. The only stage with
   side effects, and the only one a dry run skips.

One split the Field UI hosts forced into the open: when the host owns
the write (Field UI copies form-state values straight onto the entity
and saves it), the form path runs `prepare()` inside its validate
handler, hands the storage-shaped artifact back to the host, and never
calls `commit()`. The target still owns the transform; the host owns
the write. Both the formatter and the field type shims work this way.

### Interfaces

```php
interface DataSurfaceTargetInterface {
  /** Storage values in surface shape; the `current` argument of accept(). */
  public function load(DataSurfaceInterface $surface): array;
  /** Surface values to storage shape; no writes. */
  public function prepare(DataSurfaceInterface $surface, array $values): PreparedValues;
  /** Writes a prepared artifact. */
  public function commit(PreparedValues $prepared): void;
}

final class PreparedValues {
  public function __construct(
    public readonly array $values,        // accepted, validated surface values
    public readonly mixed $artifact,      // storage-shaped: array, Config, entity, ...
    public readonly array $dependencies,  // config/content/module, for calculateDependencies()
  ) {}
}

final class DataSurfaceResult {
  public readonly array $values;
  public readonly array $violations;     // name => [{message, path}]
  public readonly ?PreparedValues $prepared;
  public readonly bool $committed;
  public function isValid(): bool;
}

interface DataSurfacePipelineInterface {
  public function accept(DataSurfaceInterface $surface, array $input, array $current = []): array;
  public function validate(DataSurfaceInterface $surface, array $values): array;
  public function prepare(DataSurfaceInterface $surface, array $values, DataSurfaceTargetInterface $target): PreparedValues;
  public function commit(PreparedValues $prepared, DataSurfaceTargetInterface $target): void;
  /** accept → validate → prepare → (commit unless dry run). Stops at the first failing stage. */
  public function submit(DataSurfaceInterface $surface, array $input, DataSurfaceTargetInterface $target, bool $dry_run = FALSE): DataSurfaceResult;
}
```

Rules:

- `submit()` is what every interface calls. Forms, the MCP bridge, a
  config action, a REST controller: all four are one line each.
- The surface never learns about storage; the target never learns about
  forms. A surface plus a target is a complete "configurable thing".
- Coercion moves out of the widgets into `accept()`. Widgets keep shaping
  the submitted tree, but the form path calls the same `accept()` as a
  JSON payload, so the two cannot drift. The widget tests that cover
  casting move to pipeline tests.
- `prepare()` does not validate against config schema. That stays a
  target concern: the config object and config entity targets may run
  typed-config validation on the artifact and fold violations back into
  the result, which is how the surface's own constraints and the storage
  schema's constraints both get enforced without either knowing the other.

### Scope of a surface, and composition

Rule: **a surface is exactly what one commit writes.** It is the complete
set of values one target accepts in one operation. Three tests decide
whether something is one surface: one submit stores all of it, one
owner declares it, and one operation context applies. Add and edit of
the same entity are one surface with the operation as build-time
context (locking is how the context shows). When the tests fail, there
are two surfaces, and they compose by nesting.

**Nest, never merge.** A nested surface appears inside another as a map
definition: its definitions become the map's properties, its
refinement map is re-keyed under the path, its refiners are wrapped so
they see their own values, and its violations come back path-prefixed.
The third-party mount is already this for one provider under one key;
`mount(string $name, DataSurface $child)` on the builder generalizes
it, and `CompositeTarget` routes the nested path to the child's target.
Ownership is why: an image effect's surface is the same object in its
standalone form and inside the image style form, and the effect's own
target commits it in both. Merging two surfaces into one loses the
owner, invites key collisions, and breaks the one-commit rule.

The alter event adding sibling definitions is not a merge of two
surfaces. It is one owner's surface extended under the narrowing
contract by another module, and it stays flat because the owner's
target stores those keys.

Forms follow from this. A page showing two independent surfaces renders
two containers and calls `submit()` twice with two targets. It does not
need a merged surface. Only values that store together nest.

The standalone demo form is the smallest live proof that a surface is
independent of its target: the block's own surface, committed through
`PluginConfigurationTarget` in its host and through `StateTarget` from
the form, with no merge and no second declaration. The form asks the
plugin manager for the contract rather than the host for a form.

Design cost, to settle before implementing `mount()`: refinement keys
become dotted paths rather than top-level names, and a nested refiner
must be able to depend on a parent-level key (an effect narrowing by
the style's own settings). The refinement map format and
the refinement dependencies accessor, now `DefinitionMap::refinementDependencies()`, change shape, and the form builder's
AJAX wiring keys on the same names. Decide the path syntax once, with
`third_party_settings.<provider>.<key>` as the first existing case.

### Targets to ship

| Target | Wraps | Used by |
| --- | --- | --- |
| `PluginConfigurationTarget` | a plugin's `$configuration` array, preserving host-owned keys | blocks, conditions, actions, effects, layouts, and every other `ConfigurableInterface` plugin |
| `ConfigObjectTarget` | a `Config` object plus a property-path map with optional to/from callables, mirroring `ConfigTarget` | simple config forms, image toolkits |
| `ConfigEntityTarget` | a config entity plus a property map; `third_party_settings` mounts map straight to `setThirdPartySetting()` | node type demo, any config entity form |
| `DisplayComponentTarget` | an entity display component's `settings` and `third_party_settings` | formatters, widgets |
| `CompositeTarget` | an ordered list of targets sharing one value set | the node type demo's three destinations |
| `StateTarget` | a State key | the standalone demo; also handy in tests |
| `FieldSettingsTarget` | a field config entity's `settings`, through two shape callables (input shape to storage shape and back) | field types (Group C); the address port |
| `BaseFieldOverrideTarget` | a bundle's overrides of an entity type's base fields: per-bundle label and default value | the node type demo's third destination, now a named class instead of a save method |

### Storage swap and dry run

The survey found exactly one per-object swap point in core: a `Config`
object holds its own `StorageInterface`, so `ConfigObjectTarget` can be
handed a config built against `MemoryStorage` and commit for real into a
bin. Config entities cannot be redirected per call; `ConfigEntityStorage`
writes through the container's config factory. For entities, the interim
dry run is `prepare()` itself: the artifact is the built, unsaved entity,
which is what core's own `EntityForm::buildEntity()` produces for node
preview. The full temporary-bin story for entities is a core change and
stays out of scope, as the issue comment says.

### Build order

1. **Done.** `PreparedValues`, `DataSurfaceResult`,
   `DataSurfaceTargetInterface`, `DataSurfacePipeline` with `accept()`
   absorbing the widgets' casting; `StateTarget` and
   `PluginConfigurationTarget`; kernel coverage that feeds the same
   input through a form and through `submit()` directly and asserts
   identical results.
2. `ConfigObjectTarget` and `ConfigEntityTarget` with typed-config
   validation folded into the result; `CompositeTarget`.
3. `DisplayComponentTarget`.
4. The demo ports in PLAN.md phase 2 then go through the pipeline, and
   the base classes described there call `submit()` from their submit
   handlers rather than writing configuration themselves.

## Part 2: adoption catalogue

Grouped by how much adapter work each family needs. Within a group the
same adapter serves every member.

### Group A: the plain plugin triple (one adapter serves all)

`ConfigurableInterface` for storage plus `PluginFormInterface`'s
`buildConfigurationForm` / `validateConfigurationForm` /
`submitConfigurationForm`. Adapter, now built:
`DataSurfaceConfigurationTrait` for storage and
`DataSurfacePluginFormTrait` for the triple, both over
`PluginConfigurationTarget`. The three form stages live one level down
in `DataSurfaceHostFormTrait` so a host that renames them reaches the
same bodies, which is what makes a per-host base class thin:
`DataSurfaceBlockBase` is the composition plus the block's three renamed
methods and its host-owned keys.

| Family | Base class | Storage destination | Quirk to absorb |
| --- | --- | --- | --- |
| Conditions | `lib/Drupal/Core/Condition/ConditionPluginBase.php` | owning entity's `visibility.<id>` | shallow `+` merge; `negate` and `context_mapping` are host-owned and written by the parent's submit; `id` is prepended by `getConfiguration()` rather than stored. Built: `DataSurfaceConditionBase`, five one-line translations |
| Actions | `lib/Drupal/Core/Action/ConfigurableActionBase.php` | `system.action.*.configuration` | none: the host supplies no build or submit, so the trait's triple is the whole form. Built: `DataSurfaceActionBase`, zero translation lines |
| Image effects | `modules/image/src/ConfigurableImageEffectBase.php` | `image.style.*.effects.<uuid>.data` | form always nested under `data` with an explicit subform state; `RemovableDependentPluginInterface` |
| Layouts | `lib/Drupal/Core/Layout/LayoutDefault.php` | `Section::$layout_settings` | `context_mapping` injected from gathered contexts; resolved through `plugin_form.factory` |
| Search pages | `modules/search/src/Plugin/ConfigurableSearchPluginBase.php` | `search.page.*.configuration` | none |
| Display variants | `lib/Drupal/Core/Display/VariantBase.php` | contrib collections | `id`, `label`, `uuid`, `weight` are host-owned |
| Entity reference selection | `lib/Drupal/Core/Entity/EntityReferenceSelection/SelectionPluginBase.php` | `field.field.*.settings.handler_settings` | nested inside the field settings form |
| Media sources | `modules/media/src/MediaSourceBase.php` | `media.type.*.source_configuration` | submit creates the source field: a side effect that belongs in commit, not prepare |
| CKEditor 5 plugins | `modules/ckeditor5/src/Plugin/CKEditor5PluginConfigurableTrait.php` | `editor.editor.*.settings.plugins.<id>` | host drops a plugin whose values equal its defaults |
| Blocks | `lib/Drupal/Core/Block/BlockPluginTrait.php` | `block.block.*.settings` or a Layout Builder section component | `blockForm` / `blockValidate` / `blockSubmit` names; `id`, `label`, `label_display`, `provider` host-owned; submit is skipped when the form has errors |

Counted evidence for the DRY claim, with three families built: the
block base needs four translation methods, the condition base five
one-line ones, the action base none. One set of traits serves all
three, and the count tracks how much the host itself insists on doing.

Host ids handed to the factory are namespaced `<host type>:<id>`
(`block:foo`, `field_formatter:foo`, `field_type:address`,
`entity_type:node_type`), and the build event matches host classes with
`appliesTo()`, so subclasses keep their parents' extensions.

Two mechanics settled by the condition and action ports. A host base
class that composes `ConfigurableTrait` into itself (actions, search
pages, media sources, CKEditor 5) is replaced by an adopting subclass
that simply uses `DataSurfaceConfigurationTrait`: a trait used in the
child overrides methods the parent flattened from its own trait, no
`insteadof` is needed, and `insteadof` naming a parent's trait is
itself a fatal error. And the subform worry is settled: a host that
unwraps a subform state only in a local copy (conditions, and by the
same shape image effects) composes as the parent's build wrapped
around the trait's build, with nothing to absorb, because the host
trait already resolves in-progress AJAX input on the complete form
state.

Construction order, stated positively: a block that promotes its
collaborators in the constructor signature and then calls the parent
constructor is safe, because promoted properties are assigned before
the body runs and before the parent's `setConfiguration()` consults the
surface. Assigning collaborators in `create()` after construction is
what fails.

Cross-cutting trap: merge semantics are not uniform. `ConfigurableTrait`
deep-merges defaults, while conditions and CKEditor 5 shallow-merge. The
pipeline's `accept()` defines one rule (defaults, then current, then
input, recursively through maps) and the adapter for each family sets
`$configuration` from the accepted values rather than calling the host's
`setConfiguration()`, so the host's merge never runs on surface keys.

### Group B: settings protocols with no validate or submit hook

The host harvests raw form values itself. Adapter, now built:
`DataSurfaceFormatterTrait`, an `#element_validate` on the surface
container that extracts, validates, flags, and writes the accepted
values back into form state, plus a static defaults shim reading the
class's `DataSurfaceAware` attribute. `DataSurfaceFormatterBase` is that
trait plus the one line answering static `defaultSettings()`.

| Family | Base class | Storage destination | Quirk to absorb |
| --- | --- | --- | --- |
| Field formatters | `lib/Drupal/Core/Field/FormatterBase.php` | display component `settings` | static `defaultSettings()`; `prepareConfiguration()` prunes unknown keys, so mounted third-party keys must be declared there too |
| Field widgets | `lib/Drupal/Core/Field/WidgetBase.php` | form display component `settings` | same as formatters |

A second face of the static-defaults problem, found by the formatter
port: the static array is not only what the host prunes against, it is
what `getSettings()` fills from, and it declares `third_party_settings`
as an empty array. That empty array shadows a default declared on a
definition another module mounts at build time, so a mounted default
never shows on the first render of an untouched formatter. The default
is still on the surface and every non-form consumer sees it; only the
form's first render misses it. Fix if wanted: have the formatter shim
overlay surface defaults for keys whose stored value is empty.
| Text filters | `modules/filter/src/Plugin/FilterBase.php` | `filter.format.*.filters.<id>.settings` | `settingsForm` only; defaults come from the plugin definition, not a method |
| Text editors | `modules/editor/src/Plugin/EditorBase.php` | `editor.editor.*.settings` | full triple, but defaults come from a non-static `getDefaultSettings()` and it is not `ConfigurableInterface` |

The static-defaults problem is solved for attribute-declared surfaces by
reading the attribute from the class; that rule lives once, in
`DataSurfaceHostTrait::surfaceDeclaredDefaults()`, and both the
formatter and the field type shims read it. Runtime surfaces still need
the array, and the shims throw a clear message when neither exists.

### Group C: one plugin, two surfaces

| Family | Base class | Surfaces | Quirk to absorb |
| --- | --- | --- | --- |
| Field types | `lib/Drupal/Core/Field/FieldItemBase.php` | storage settings and field settings | different signatures (`storageSettingsForm(&$form, $form_state, $has_data)`); `storageSettingsToConfigData()` / `fieldSettingsToConfigData()` is an existing prepare step, and the target must call it; `$has_data` is a refinement input (lock what cannot change once data exists) |

This is the family the issue calls out as "described by nothing more than
the form", and the `$has_data` lock is a perfect use of build-time
locking. Address-style per-country narrowing is a refinement chain.

#### Exemplar: the address field type (contrib)

Built and green: `modules/data_surface_address` (class swap through
`hook_field_info_alter()`; the settings are declared in a
`#[DataSurfaceAware]` attribute on `SurfaceAddressItem`, which also
holds the two shape callables), adapter `DataSurfaceFieldTypeTrait` plus
`FieldSettingsTarget`, kernel coverage in `AddressFieldSurfaceTest`.
Storage settings (`storageSettingsForm` with its `$has_data` lock) are
the untouched half of Group C; address has none.

Chosen because its settings form has no dependent settings at all, so
everything a caller can get wrong is meaning and shape, which is the
gap the typed version must close. Verified from
`web/modules/contrib/address`:

| Setting | Stored shape | What only the form knows |
| --- | --- | --- |
| `available_countries` | map `CC => CC` from a multi-select; empty means all | labels from `address.country_repository`; `array_filter` drops falsy entries |
| `langcode_override` | string or NULL | options are non-locked languages; element hidden on monolingual sites but settable programmatically |
| `field_overrides` | `<camelCaseField> => ['override' => hidden|optional|required]` | keys are addressing-library constants (`givenName`, not `given_name`); only 12 of 14 properties may appear; labels from `LabelHelper`; a validate handler strips empty rows; an empty override string makes `FieldOverrides` throw |
| `fields` (deprecated) | list | when non-empty it silently wins over `field_overrides` |

Surface (input shape, deliberately not the storage shape):

- `available_countries`: list of string, each item carrying the address
  module's `Country` constraint (needs the options widget to render a
  list-of-choices as a multi-select).
- `langcode_override`: optional string with the `LanguageExists`
  constraint, which leaves out the locked languages.
- `field_overrides`: map of the 12 overridable fields, each an optional
  string with `LabeledChoice` hidden/optional/required and the generic
  field label; NULL means no override.
- `fields` is not on the surface.

The two live lists are not in the declaration at all: the `CountryOptions`
and `LanguageExistsOptions` resolvers derive them from those constraints,
which is what makes the surface literal enough to be declared on the
class (see PLAN.md, "Labeled options"). One piece could not follow:
core's `MapDataDefinition` takes only its definition array in its
constructor and gains its property definitions through
`setPropertyDefinition()`, so the twelve override properties are
declared in a static method on the item class and merged in
`getFieldSurface()`. That is the concrete limit on attribute-declared
surfaces today — lists are fine, because `ListDataDefinition` takes its
item definition as a constructor argument; maps are not.

Target `FieldSettingsTarget(FieldConfigInterface)`:
`load()` flattens `['override' => x]` to `x` and re-keys the country
map to a list; `prepare()` does the reverse, drops NULL overrides, and
writes `fields => []` so the deprecated key can never shadow the
overrides; `commit()` is `setSettings()` and `save()`. That prepare
step is the address module's form validate handler plus its accessor
logic, given one visible home.

Adoption without forking contrib: a `data_surface_address` submodule
swaps the `address` field type class to a subclass that uses the field
type trait, via `hook_field_info_alter()`. Field UI then renders the
generated settings form; nothing in the address module changes.

Tool API comparison: `tool_belt:field_add` and `field_update` derive
their `settings` input from config schema, which for address yields
types only (no labels, no defaults, no choices, and a nested
`override` key the caller must guess). A `data_surface_tool` bridge
exposes `data_surface:field_add` / `field_update` whose `settings` input
is derived from the field type's surface (definitions converted to
tool input definitions, labels and choices intact) and whose execution
runs `submit()` through `FieldSettingsTarget`. Same site, same field
type, two tools: that is the A/B for "is an agent better at configuring
the fully typed version".

### Group D: multiple named forms

`PluginWithFormsInterface` resolves a form class per operation through
`plugin_form.factory`. Model, now built: one surface per operation,
`DataSurfaceProviderInterface::getDataSurface(string $operation =
'configure')`, and one generic `DataSurfacePluginForm` class (extends
`PluginFormBase`, so it is a `PluginFormInterface` and a
`PluginAwareInterface`) that any plugin can list under any operation in
its `forms` key. No per-plugin form classes at all. The operation it
serves is a constructor argument, so an operation other than
`configure` names a service ID in the `forms` key rather than the bare
class, which is how the class resolver passes the operation along.

| Family | Operations | Note |
| --- | --- | --- |
| Blocks | `configure`, `settings_tray` | settings tray auto-fills the block class when undeclared; a surface can subset itself for the tray |
| Workflow types | `configure`, `state`, `transition` | `$configuration` also holds structural `states` and `transitions`, so those keys are host-owned |
| Icon extractors | `settings` | form class is already a pure pass-through to the plugin |
| Media sources | `media_library_add` | not a plugin form: a plain `FormInterface`. The generic form class must not assume every `forms` entry is a plugin form |

### Group E: config entities

Today: `EntityForm::copyFormValuesToEntity()` sets every form value on
the entity untyped, there is no `validate()` on config entities, and
`Config::save()` casts but never validates. Adapter: an entity form base
that builds the form from a surface and routes submit through
`ConfigEntityTarget`, which sets mapped properties, applies mounted
third-party settings through `setThirdPartySetting()`, runs typed-config
validation on the unsaved entity's export array, and commits with
`save()`. `#[ActionMethod]` methods on the entity are candidate prepare
callables, which also gives config actions the same transform.

First candidates, in order: node types (already demoed, three
destinations), then bundle entities generally (`ConfigEntityBundleBase`
has no value seam of its own), then image styles and text formats, which
are collections of Group A plugins and prove nesting.

Two things the node type port (done, `modules/data_surface_demo_node_type`)
settled. Ordering is part of a composite's contract: a bundle-scoped
target must come after the target that creates the bundle and resolve
its field definitions lazily, because on add the bundle does not exist
while values are prepared; preparing unsaved overrides is legal, only
committing needs the bundle to be real. And a surface key can be the
identity of the thing a sibling target writes into: when the bundle id
is a surface value, the target cannot be built from stored state alone,
so the provider builds it from the accepted values. Nested surfaces
under `mount()` will hit the same question, so settle a "resolve target
from values" seam before that lands.

Longer term: a surface can be derived from config schema as a starting
point (the `Mapping` and `Sequence` definitions already are typed data),
which is the ingestion direction in the issue.

### Group F: simple config forms

`ConfigFormBase` with `#config_target` is core's own half of this idea:
defaults from config, `toConfig` and `fromConfig` transforms, typed-config
validation, violations mapped to elements. What it lacks is a form that
is generated and a contract readable outside PHP. `ConfigObjectTarget`
mirrors `ConfigTarget` one for one, so a surface-driven config form
keeps everything `#config_target` gives and adds the surface. Note
`ConfigFormBase::copyFormValuesToConfig()` is private static, so the
surface form cannot extend it; it replaces it.

### Group G: field widgets collecting content (refinable field items)

Distinct from Group B, which is about a widget's own settings. This is
the widget as the form bridge for the values of a field item, and it is
the one family where core already has the definitions: every field item
declares `propertyDefinitions()` and constraints. What is missing is the
refinement map and a bridge that consumes it. A field item surface is:

- definitions: the item's property definitions, unchanged;
- refinements: declared by the field type (an attribute or a
  `getDataSurfaceRefinements()` on the item class), with the item class
  or a service as the refiner. Examples: address, where country narrows
  administrative area and postal code format; entity reference, where
  the target bundle narrows the referenceable set; link, where the URI
  kind narrows the allowed options; list fields, whose allowed values
  today come from the instance-level `OptionsProviderInterface`, which
  refiners generalize.

Bridge: one generic field widget, `data_surface`, applicable to any
field type whose item exposes a surface. `formElement()` builds the
surface form for each delta with refinement AJAX scoped to that delta;
`extractFormValues()` runs `accept()` and `validate()` and sets the
accepted values on the item. `WidgetBase` keeps owning the delta loop
and add-more behavior, which is why per-item surfaces sidestep the
list-widget gap in PLAN.md phase 4. Commit is not the widget's job: the
content entity form saves, and entity validation runs as today.

Violation paths already line up. Surface violations are
`property.subpath`; content entity forms flag by
`field.delta.property` through `flagWidgetsErrorsFromViolations()`, so
the widget prefixes the delta and nothing else changes.

The second consumer is the reason to do this: JSON:API and REST already
run entity validation, but constraints that depend on sibling values are
today either absent or hand-written validators. With a field item
surface, the same refinement that narrows the form narrows the payload,
so a decoupled client gets the same rejection with the same message as
the form. That is the "runs identically everywhere" claim applied to
content, which the issue names as Field API's content-side gap.

Scope boundary: refinement within one item. Cross-delta rules and
cross-field rules on one entity (field B narrows by field A) need an
entity-level surface, which is a later design. The caching split from
comment 4 on the issue applies here with force, since field surfaces are
built per field definition and refined per request.

Target: `FieldItemTarget`, whose prepare sets values on an unsaved item
and whose commit is a no-op inside an entity form and an entity save
outside one. That is what lets a test or an agent write one field
through the pipeline without a form.

### Group H: different shape, later

| Family | Why later |
| --- | --- |
| Views plugins | `defineOptions()` is a definition language of its own (defaults, `contains`, `bool`), state is `$this->options`, forms are by reference. Worth ingesting `defineOptions()` into a surface eventually; not an adapter job |
| Menu link forms | write to the menu tree table, not config; have their own `extractFormValues()` |
| Image toolkits | write to a `system.image.<toolkit>` config object with no plugin configuration; `ConfigObjectTarget` fits once Group F exists |
| Migrate | no configuration forms at all |
| Layout Builder inline blocks | configuration holds a serialized content entity |

### Non-form consumers the pipeline unlocks

- **Config actions and recipes.** Core validates config actions only
  after the write, and only when the schema is marked `FullyValidatable`.
  A `dataSurface` config action (`<host>: <values>`) runs `submit()`,
  so validation happens before the write with the same messages a form
  shows, and `${input}` placeholders flow through `accept()`.
- **MCP and agents.** Built: `modules/data_surface_tool` exposes
  `data_surface:field_add` and `data_surface:field_update`, whose
  `settings` input is derived from the field type's surface and whose
  execution runs `submit()`. `modules/data_surface_tool/COMPARISON.md`
  is the recorded A/B against `tool_belt:field_add` for the address
  field type: named override properties with `enum` and labels, a live
  country `enum`, a language `enum`, and no deprecated `fields` key,
  against an unnamed array of objects with bare strings. The same
  surface that generates the Field UI form generates the tool schema,
  with no second description. A dry-run flag on the tools is the next
  step for propose-and-preview.
- **REST or JSON:API-style endpoints.** A controller is `accept`,
  `submit`, serialize the result.
- **Tests.** Kernel tests exercise a host's configuration without a form.

### Suggested order for the core conversation

1. Group A with conditions and image effects first: pure triple, many
   core implementations, small classes, and a config entity that
   collects them (block visibility, image styles) to prove nesting.
2. Blocks and formatters, already demonstrated in the PoC.
3. Node type as the config entity exemplar, then field types (Group C),
   because those are the two the issue names as having no definitions
   at all today.
4. Workflow types for the multiple-forms model.
5. Field widgets collecting content (Group G) with address-style
   narrowing as the demo, because it is the first family where the
   definitions already exist in core and the same refinement reaches
   JSON:API.
6. Config actions, once targets exist, because it turns validation from
   post-save rollback into a gate.

## Core facts worth keeping straight

Verified during the survey; some contradict common assumptions.

- `ConfigEntityBase` has no `validate()`; config entities are never
  schema-validated on save.
- `Config::save()` casts through schema but never validates.
  `Config::validate()` does not exist; only `ConfigImporter::validate()`.
- `ConfigFormBase::copyFormValuesToConfig()` is private static despite
  the class docblock inviting overrides.
- `FullyValidatable` is a marker whose validator is a no-op; only the
  config action manager checks it, and only after saving.
- The config action deriver is `EntityMethodDeriver`.
- `StorageReplaceDataWrapper` lives in `modules/config/src`, not core lib.
- Formatter and widget settings are pruned against static
  `defaultSettings()` on display save.
- `BlockPluginTrait::submitConfigurationForm()` skips `blockSubmit()`
  when the form has any error.
- `TypedDataManager::getPropertyInstance()` caches field item prototypes
  by root data type and property path, not by field config object, so
  two unsaved `FieldConfig` objects with the same name share one
  prototype and an item can point at a stale field definition. Build
  items from `FieldConfigBase::getItemDefinition()` instead; candidate
  core issue.
- Tool API `Choice` constraints added for schema purposes also validate,
  so a surface-derived tool input is refused at the input boundary; the
  pipeline stays the authority for what constraints cannot express
  (unknown keys, shape transforms). Tool API gaps found by the bridge
  are listed in `modules/data_surface_tool/README.md`: `LabeledChoice`
  is invisible to its schema normalizer, map and list defaults and falsy
  scalar defaults never reach the schema, context definitions drop type
  settings, there is no examples slot, and `setLocked()` is ignored
  below the top level.
