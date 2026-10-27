# Adoption catalog

Drupal core is full of places with the same shape: collect values from a
form, validate them somewhere, store them somewhere else, and describe
them nowhere. This page catalogs those places, grouped by how much
adapter work each family needs, and says which of them this module has
already built.

Within a group, one adapter serves every member. That is the claim the
grouping exists to test.

This page is about where core's forms could adopt the pattern. What core
itself would have to change, in the order it could be added and with
the issue that carries each step, is [Core gaps](core-gaps.md): read
that page for the core proposal.

Everything here was verified against core 11.4; paths are relative to
`web/core`. The long-form survey, with the per-family quirks and the
reasoning, is in `ADOPTION.md` at the module root.

| Group | The shape | Status |
| --- | --- | --- |
| [A](#group-a-the-plain-plugin-triple) | `ConfigurableInterface` plus the `PluginFormInterface` triple | **Built.** Three families ported. |
| [B](#group-b-settings-protocols-with-no-hook) | A settings form the host harvests itself, with no validate or submit hook | **Built.** Formatters ported. |
| [C](#group-c-one-plugin-two-sets-of-settings) | Field types: storage and instance settings on one plugin | **Half built.** Instance settings ported; storage settings described and written by the field tools, with no Field UI host. |
| [D](#group-d-multiple-named-forms) | A form class resolved per operation | **Built.** One generic form class. |
| [E](#group-e-config-entities) | Config entity forms | **Built for one.** Node types ported. |
| [F](#group-f-simple-config-forms) | `ConfigFormBase` with `#config_target` | **Storage built.** `ConfigObjectStorage`; no core form ported, and `#config_target` is not read. |
| [G](#group-g-field-widgets-collecting-content) | A widget as the form bridge for a field item's values | **Designed, not built.** |
| [H](#group-h-different-shape-later) | Families whose shape does not fit yet | Out of scope. |

## What adopting means

Every built group adopts the same way, in two files:

1. **The resource or structure**, a class in the adopting module's
   `src/Resource/` or `src/Structure/`: its keys in `defineInputs()`, one
   `#[RefinesInput]` method per key whose allowed values depend on
   another. A class a plugin configures carries `#[Structure]`, because
   the plugin's host supplies the operation and the storage. A class
   asked for on its own carries `#[Resource]`, names its storage and an
   access class on the attribute, and declares `#[Operation]` static
   methods. See [Declaring a resource](declaring-a-resource.md).
2. **The host**, which keeps doing what only it does (rendering,
   evaluating, executing) and names the structure with
   `#[UsesStructure(SomeStructure::class)]` on the plugin class, extending
   the group's base class or using its trait. The host supplies the
   context and the storage, because only it holds the instance.

An adopting module holds what it wrote to the pattern's invariants with
one kernel test extending `ResourceTestBase`, naming its modules and its
resource ids ([Testing a resource](testing-a-resource.md)).

```php
use Drupal\Core\Condition\Attribute\Condition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Plugin\Condition\DataSurfaceConditionBase;
use Drupal\data_surface\Resource\Attribute\UsesStructure;

#[Condition(id: 'data_surface_test_condition', label: new TranslatableMarkup('Data surface test condition'))]
#[UsesStructure(TestConditionStructure::class)]
final class DataSurfaceTestCondition extends DataSurfaceConditionBase {

  public function evaluate() { /* ... */ }

  public function summary() { /* ... */ }

}
```

A config entity or a simple config object has no plugin host. Its
resource names its own storage and access class and declares the
operations it is asked in, and a route serves it through the generic
operation form (Group E). A class you do not own is adopted by
subclassing it, naming the resource or structure on the subclass, and
swapping the subclass in (Group C).

## Group A: the plain plugin triple

`ConfigurableInterface` for storage, plus `buildConfigurationForm` /
`validateConfigurationForm` / `submitConfigurationForm` over a
configuration array. Conditions, actions, image effects, layouts, search
pages, display variants, entity reference selection, media sources,
CKEditor 5 plugins, and blocks.

**Built.** `DataSurfaceConfigurationTrait` for storage and
`DataSurfacePluginFormTrait` for the triple, both over
`PluginConfigurationStorage`, with the three stages one level down in
`DataSurfaceHostFormTrait` so a host that renames them reaches the same
bodies. Blocks, conditions and actions are ported, as
`DataSurfaceBlockBase`, `DataSurfaceConditionBase` and
`DataSurfaceActionBase`. The evidence for the DRY claim is the count of
translation methods each needed: four for the block, five one-line ones
for the condition, none at all for the action. The count tracks how much
the host insists on doing, not what the data surface layer costs.

The one cross-cutting trap is that merge semantics are not uniform:
`ConfigurableTrait` deep-merges defaults while conditions and CKEditor 5
shallow-merge. The pipeline defines one rule and the adapters set the
configuration property from the accepted values rather than calling the
host's `setConfiguration()`, so the host's merge never runs on the
structure's keys. See [Generated forms](forms.md) for the rest.

## Group B: settings protocols with no hook

The host asks for a settings form and then harvests the raw value tree
itself. Field formatters and field widgets; text filters and text editors
are close relatives with their own defaults spellings.

**Built.** `DataSurfaceFormatterTrait` runs the whole pipeline in an
`#element_validate` on the container the data surface is built into, plus
a static defaults shim reading the structure the class names.
`DataSurfaceFormatterBase` is that trait and one line.

Two host realities it had to absorb. `defaultSettings()` is static and
cannot consult an instance's data surface, so it is answered from the
`#[UsesStructure]` attribute, which is readable off the class, through
`ResourcesInterface::defaults()`: the structure's own declaration alone. And
the host prunes what it saves against that same static array
(`EntityDisplayBase::setComponent()` intersects through
`prepareConfiguration()`), so keys another module mounts onto the
structure at build time have to appear in it too, which is why the shim
always declares `third_party_settings`.

One known gap: the static array declares `third_party_settings` as an
empty array, and that empty array shadows a default declared on a
definition another module mounts at build time. The default is still on
the data surface and every non-form consumer sees it; only the form's first
render of an untouched formatter misses it.

## Group C: one plugin, two sets of settings

Field types. `FieldItemBase` has storage settings and instance settings,
with different signatures, and
`storageSettingsToConfigData()` / `fieldSettingsToConfigData()` is an
existing prepare step the storage must call. `$has_data` is a refinement
input: lock what cannot change once data exists.

This is the family the core issue calls out as "described by nothing more
than the form".

**Half built.** `DataSurfaceFieldTypeTrait` plus `FieldSettingsStorage`
covers instance settings, and `data_surface_address` is the worked
example: the address field type's settings declared as a resource,
`AddressFieldSettingsResource`, which the swapped item class builds for
Field UI and which is the address variant of the field instance
resource's settings for the tool bridge, with the address module
unmodified.

Storage settings are described but have no Field UI host.
`FieldStorageResource` in `data_surface_tool` attaches variants at
`settings` by field type, from a `#[Variant]` or, for every other
field type the UI offers, from `field.storage_settings.<type>` through
`FieldStorageSettingsSchemaVariants`, so the field tools set and edit
them: a string's `max_length` is `storage.settings.max_length` on
`data_surface:field.instance:create`. `$has_data` is read where it bites:
`FieldStorageResource`'s update operation keeps cardinality from
shrinking, and `FieldStorageStorage` refuses at prepare a settings change
that alters the columns of a field with data. What is untouched is the
field type's own `storageSettingsForm()`; address has no storage settings.

Address was chosen because its settings form has no dependent settings at
all, so everything a caller can get wrong is meaning and shape, which is
exactly what the config schema for `field.field_settings.address` cannot
say. It gives three types, a nested `override` key with no vocabulary, no
labels, no defaults, and no hint that one of its four keys is deprecated
and silently overrules another.

## Group D: multiple named forms

`PluginWithFormsInterface` resolves a form class per operation through
`plugin_form.factory`. Blocks (`configure`, `settings_tray`), workflow
types (`configure`, `state`, `transition`), icon extractors, media
sources.

**Built.** The plugin names one structure with `#[UsesStructure]`, and
one generic `DataSurfacePluginForm` any plugin can list under any
operation builds it in a context whose id is the form's operation. No
per-plugin form classes at all. The operation is a constructor argument,
so an operation other than `configure` names a service id in the `forms`
key rather than the bare class, which is how the class resolver passes
the operation along.

## Group E: config entities

Today `EntityForm::copyFormValuesToEntity()` sets every form value on the
entity untyped, config entities have no `validate()`, and
`Config::save()` casts but never validates.

**Built for two.** `ConfigEntityStorage` maps the resource's keys to
entity properties or setter methods, named on a subclass, applies
mounted third-party settings through `setThirdPartySetting()`, runs
typed-config validation on the unsaved entity's export array, refuses an
id in use on add, commits with `save()`, and deletes for an operation
that deletes. Two resources use it. `data_surface_block` makes a placed
block one, `block.placement`, its plugin choosing its settings
(why: [decision](decisions.md#a-placed-block-is-a-resource)).
`data_surface_demo_node_type` is the other: one resource,
`NodeTypeResource`, with a create and an update operation, the machine
name locked on an update because the update operation knows it, and its storage,
`NodeTypeStorage`, writing the node type entity plus its base field
overrides (through the engine's `BaseFieldOverrideStorage`); two routes
serve it through `DataSurfaceOperationForm`, with no form class.

Two things that port settled, and both generalize. Ordering is part of a
composite's contract: a bundle-scoped storage class must come after the
one that creates the bundle, and must resolve its field definitions
lazily, because on add the bundle does not exist while values are
prepared. And a resource's key can be the identity of the thing a sibling
storage class writes into, so when the bundle id is a submitted value the
storage cannot write from stored state alone and builds what it writes
from the accepted values in `prepare()`.

Next candidates: bundle entities generally, then image styles and text
formats, which are collections of Group A plugins and prove nesting.

## Group F: simple config forms

`ConfigFormBase` with `#config_target` is core's own half of this idea:
defaults from config, `toConfig` and `fromConfig` transforms,
typed-config validation, violations mapped to elements. What it lacks is
a form that is *generated* and a contract readable outside PHP.

**Storage built, no core form ported.** `ConfigObjectStorage` keeps a
singleton resource's values in one simple config object, which a
subclass names: prepare lays the values over what is stored and
validates the whole object against its config schema, and commit writes
it. `data_surface_first` and the examples use it
([Storage](storage.md#a-simple-config-object)). An earlier engine class
mirroring core's `ConfigTarget` was removed for want of an adopter; this
one came back once every settings resource had written the same class.
It does not read `#config_target` metadata or its `toConfig` and
`fromConfig` transforms, so a form already carrying them and a resource
do not yet describe the same thing. Note that
`ConfigFormBase::copyFormValuesToConfig()` is private static, so a
generated config form cannot extend it; it replaces it.

## Group G: field widgets collecting content

Distinct from Group B, which is about a widget's own settings. This is
the widget as the form bridge for the *values* of a field item, and it is
the one family where core already has the definitions: every field item
declares `propertyDefinitions()` and constraints. What is missing is the
refinement map and a bridge that consumes it.

**Designed, not built.** The design is one generic field widget
applicable to any field type whose item exposes a structure, with
refinement AJAX scoped per delta, `WidgetBase` keeping the delta loop,
and a `FieldItemStorage` whose commit is a no-op inside an entity form.

The reason to do it is the second consumer, not the first. JSON:API and
REST already run entity validation, but constraints that depend on
sibling values are today either absent or hand-written validators. With a
field item structure, the same refinement that narrows the form narrows the
payload, so a decoupled client gets the same rejection with the same
message as the form.

Scope boundary: refinement within one item. Cross-delta and cross-field
rules need an entity-level resource, which `data_surface_entity` now
derives ([Content entities](entities.md)); no field refines another there
yet.

## Group H: different shape, later

| Family | Why later |
| --- | --- |
| Views plugins | `defineOptions()` is a definition language of its own; state is `$this->options`, forms are by reference. Worth ingesting eventually, but not an adapter job. |
| Menu link forms | Write to the menu tree table, not config, and have their own `extractFormValues()`. |
| Image toolkits | Write to a `system.image.<toolkit>` config object with no plugin configuration. `ConfigObjectStorage` (Group F) fits; not ported. |
| Migrate | No configuration forms at all. |
| Layout Builder inline blocks | Configuration holds a serialized content entity. |

## Non-form consumers

The forms are the visible half. The point of separating accept, validate,
prepare and commit is the callers that never render anything.

- **Config actions and recipes.** Core validates config actions only
  after the write, and only when the schema is marked
  `FullyValidatable`, whose validator is a no-op. A `dataSurface` config
  action would run `submit()`, so validation happens before the write,
  with the same messages a form shows, and `${input}` placeholders flow
  through `accept()`.
- **MCP and agents.** Built. See below.
- **REST and JSON:API-style endpoints.** A controller is `accept()`,
  `submit()`, serialize the result.
- **Tests.** Kernel tests exercise a host's configuration without a form,
  which is most of this module's own test suite.

### The Tool API A/B

`data_surface_tool` is the recorded experiment. Its field tools are
generated from the field instance resource's operations
(`data_surface:field.instance:create`, `:reuse` and `:update`), and
`data_surface:field.instance:reuse` answers the question Tool Belt's
`field_add` answers, adding an existing storage's field to a bundle; the
difference is the answer to "what may the settings be?". A field type
with its own settings class answers from it; every other field type offered
in the UI answers from its config schema, through derived variants, so
the tools add any field the UI can.

Tool Belt refines `settings` from the field type's config schema, which
for the address field type yields three types, a nested `override` key
with nothing to put a property name in, a deprecated `fields` key offered
as an ordinary one, and no vocabulary, labels or defaults anywhere below
the top level. The resource-derived version gives named override
properties with `enum` and labels, a live country `enum`, a language
`enum`, and no deprecated key at all.

Both JSON Schema documents are printed side by side in
`modules/data_surface_tool/COMPARISON.md`, generated by the comparison
test rather than written by hand. The same resource that generates the
Field UI form generates the tool schema, with no second description.

The Tool API gaps the bridge found, and did not work around, are listed
in that submodule's README and summarized in [Core gaps](core-gaps.md).

### A setting another module added

The field tools compare two descriptions of settings the tool's author
knew about. `data_surface_demo_node_type_tool` compares what happens to
settings nobody told the tool about. `data_surface_demo_extras` adds two
review settings to every content type, once through an alter of the
content type resource and once through an ordinary form alter on core's
content type form. `data_surface:node.type:create`, a tool generated from
the content type resource's create operation that names no key of a content
type at all, advertises both (the deadline as the amount and unit a
person says, the tags with their pattern) and holds every caller to
them. The classic side is given the most a schema can say: the
deadline's config schema is an integer with its full Range, 3600 to
2592000. That schema is accurate but does not say the integer is
seconds, so an agent reading it alone has to infer the unit. A capable
one will read the bounds as an hour and thirty days and guess right; the
claim is not that it cannot, but that its guess is unverified inference,
found right or wrong only after the value is stored, where the resource's
caller names the unit because it is part of the contract. Tool Belt's
`entity_bundle_add` does not advertise the settings at all; an agent
that already knew the key can still send them through its open
`properties` map, and nothing on the classic write path asks the schema.
Business days are the case no reading of the schema answers: both sides
convert ten of them to twelve calendar days, 1036800 seconds, by one
rule, while a schema-only agent's best inference, 864000, is in range,
accepted by the classic tool, and wrong. The resource stores both
through a storage layout the extras module hands it, and refuses
forty-five days on the amount, in the caller's own units.

The schemas and a table of outcomes, each cell observed rather than
written, are in `modules/data_surface_demo_node_type_tool/COMPARISON.md`,
generated by the same script as the field tools' comparison and held to
it by `NodeTypeToolComparisonTest`.

## Suggested order for adopting families

The order in which core's families of forms could adopt the pattern,
once the core changes they need exist; the order of those changes is
[Core gaps](core-gaps.md)'s.

1. **Group A**, with conditions and image effects first: pure triple,
   many core implementations, small classes, and a config entity that
   collects them (block visibility, image styles) to prove nesting.
2. **Blocks and formatters**, already demonstrated here.
3. **Node type** as the config entity exemplar, then **field types**,
   because those are the two the issue names as having no definitions at
   all today.
4. **Workflow types** for the multiple-forms model.
5. **Field widgets collecting content**, with address-style narrowing as
   the demo, because it is the first family where the definitions already
   exist in core and the same refinement reaches JSON:API.
6. **Config actions**, now that a config object storage class exists,
   because it turns validation from post-save rollback into a gate.
