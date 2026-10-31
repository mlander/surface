# Core gaps

What Drupal core lacks so that one description of a group of values (in
core's terms, the properties of a map definition, such as the keys of
one config object) can serve a form, an API, an agent and a JSON Schema
alike, in the order it could be added. This page is written for a core
maintainer who has not seen this module. Each step says the gap in
core's own terms, what this module does instead today, what core would
change, which issue carries the change, and what a site gains from that
step alone if nothing after it lands. No step needs a later one.

The module is the evidence, not the proposal: it is experimental, and
the conversation belongs to [the core
issue](https://www.drupal.org/project/drupal/issues/3622144). "Drafted,
unfiled" below means the issue's text is written and has not yet been
posted to drupal.org; each draft is named by its working title. No
patch exists for any of them yet: the code this page points at is the
module's, which shows that each change works, not how core should write
it.

## The order

| Step | What core gains | Carried by |
| --- | --- | --- |
| [1](#step-1-facts-on-a-data-definition) | Eleven changes to typed data, validation and the Field API, each small and additive, and one to the coding standards | Eleven drafts, drafted, unfiled; one item and one constraint not drafted |
| [2](#step-2-operations-and-access-for-simple-config) | Operations, a permission each, and per-key access for a simple config object, as entity types have | Not drafted |
| [3](#step-3-third-party-settings-for-simple-config) | A place in a simple config object for another module's keys, as config entities have | Not drafted |
| [4](#step-4-a-served-contract) | A JSON Schema of what an operation accepts, never stricter than validation | Not drafted as one; its parts are step 1's |
| [5](#step-5-form-api-last) | A form derived from the definitions | Not drafted; three drafts name its first follow-ups |

Bottom-up, because each lower step pays off in code core already has
(typed data validation, typed config, the serialization module's JSON
Schema normalizers) or in a hand-written form that reads one more fact,
and none asks Form API to change. Form API is last because a generated
form is one more reader of what steps 1 to 4 state; this module's own
forms are built that way ([Generated forms](forms.md)).

## The module in brief

Only what the "Here today" lines need. A
[definition](https://github.com/mlander/surface/blob/main/README.md#glossary) is core's `DataDefinitionInterface`,
unchanged. A [resource](https://github.com/mlander/surface/blob/main/README.md#glossary) is a PHP class that
declares a group of definitions for one thing with identity (the keys
that say which one it is: a content type's machine name; a settings
page has none, since there is one of it) and
[storage](https://github.com/mlander/surface/blob/main/README.md#glossary), the class that loads and saves its
values, such as one simple config object. A
[structure](https://github.com/mlander/surface/blob/main/README.md#glossary) is a group of definitions with no
storage of its own, such as a block's settings, which the block plugin
stores in its configuration; the module calls that plugin the
structure's [host](https://github.com/mlander/surface/blob/main/README.md#glossary). A resource is asked for
through its [operations](https://github.com/mlander/surface/blob/main/README.md#glossary) (`configure`, `create`,
`update`), each carrying a permission, and from each operation the module
generates a route with a form, a tool for the contributed Tool API
(which agents, MCP clients and Drush call) and two JSON Schema
documents, written by the module's emitter
(`src/Contract/ContractEmitter.php`) with one extension keyword of its
own, `x-surface`. Each of these ways in (the form, the tool, an HTTP
API, a test) the module calls a door.

A [refiner](https://github.com/mlander/surface/blob/main/README.md#glossary) is a static method that returns a
narrower definition for one key given the values of the siblings it
reads. Another module's [alter](https://github.com/mlander/surface/blob/main/README.md#glossary) adds keys to a
resource it does not own and may narrow it, never widen it. Narrowing is
checked on every call (`src/Refinement/Narrowing.php`), so every value
later accepted is one the schema read before allowed. Every door's
values go through one pipeline: access; accept, which coerces (`"5"` to
`5`); validate; prepare, a dry run in which the storage builds what it
would write and runs its own checks, with no side effect and no lock;
and commit ([The pipeline](pipeline.md)). [Your first resource](first-resource.md) is the whole of it for a settings page.

## Step 1: facts on a data definition

Twelve items: eleven in typed data, validation and the Field API, one in
the coding standards. They are listed so that each uses only items above
it, and each says what it gives without the ones below. Each pays off at
once, because core already has readers of definitions that would use it.

### 1.1 A typed data prototype keyed by its definition

**The gap.** `TypedDataManager::getPropertyInstance()` keeps one
prototype per root data type, root settings and property path, never per
the property's own definition, and clones it for every later request.
So two definitions of one type that differ only in a property's
constraints validate against whichever was built first in the process,
and two unsaved `FieldConfig` objects of one field type share one item,
so the second writes to the first one's entity.

**Here today.** Every place the module turns a definition into typed data
goes through `DefinitionTypedData::create()`, which hands core a copy of
the root carrying a digest of the definition in its settings, so the
cache key names the definition (`src/DefinitionTypedData.php`; why: [decision](decisions.md#a-definition-is-validated-as-itself)).
Field items are built from `FieldConfigBase::getItemDefinition()`
instead (`Form\SurfaceFieldTypeTrait`). It was found when one tool
call's narrowing leaked into the next call in the same process, and
`RefinementIsolationTest` pins the fix. The contributed Tool API meets
the same cache and clears it through reflection
([#3616328](https://www.drupal.org/project/drupal/issues/3616328)).

**The core change.** Key the prototype by a digest of the property
definition's type, settings and constraints at every depth, and include
a field definition's identity for field items. The draft carries a
core-only reproduction: two `map` definitions whose one property differs
only in a `Range` minimum, validated one after the other. The cost is one
digest per root validated, and one prototype per distinct set of rules
rather than per path.

**Carried by.** "TypedDataManager caches field item prototypes by data
type and property path, so two unsaved FieldConfig objects of the same
type share one prototype", drafted, unfiled.

**Alone, it gives.** A correctness fix for any code that validates two
definitions of one type in one request: a field creation service, an
import, the Tool API. It comes first because everything below that
narrows a definition per request is only safe with it.

### 1.2 Constraints as JSON Schema keywords

**The gap.** The serialization module's schematic normalizers
(`SchematicNormalizerTrait`, `PrimitiveDataNormalizer::getNormalizationSchema()`,
emitting JSON Schema 2020-12) write a value's type, format and
description and never call `getConstraints()`: a string with `Length:
{max: 50}` is advertised as `{"type": "string"}`, so a client learns the
limit from the refusal.

**Here today.** The emitter maps `Range`, `Length`, `Count`,
`UniqueItems` (1.5), `Email`, `NotBlank`, `Regex`, `Sequentially` and
`AtLeastOneOf` to keywords, a
`Choice` to its values, and names every other constraint under
`x-surface.checkedOnServer`, with what it checks, so a client knows a
server-side rule exists
(`PropertySchema::keywords()` in `src/Contract/PropertySchema.php`). A
`Regex` is written only when every construct in its pattern is on an
allowlist of those PHP and JavaScript read alike, and is otherwise left
to the server (`src/Contract/PatternTranslation.php`; why: [decision](decisions.md#a-pattern-both-engines-read-alike)).

**The core change.** The normalizer reads `getConstraints()` and asks
each constraint for its keywords through an optional interface on the
constraint class, so a contributed constraint maps itself; a constraint
with no keyword is named in an extension keyword.

**Carried by.** "The JSON Schema normalizer should translate validation
constraints into schema keywords", drafted, unfiled.

**Alone, it gives.** Every schema core already emits states its bounds.

### 1.3 A setter contract

**The gap.** `DataDefinitionInterface` declares getters and one setter,
`addConstraint()`. The other setters (`setRequired()`,
`setDescription()` and the rest) exist on `DataDefinition`, `ListDataDefinition`,
`MapDataDefinition`, `FieldDefinition` and `BaseFieldDefinition` with no
shared contract, so code handed a definition to tighten has to name a
class.

**Here today.** Where the module tightens or rewords a definition it was
handed, it checks the class first (`instanceof DataDefinition` in
`Refinement\Rule::narrow()` and `ResourceBuild\BlueprintAdapterBase::describe()`).

**The core change.** A `MutableDataDefinitionInterface` (name open)
declaring the setters the core definition classes share, without
`setDataType()`, since changing a type is not a narrowing. Checking the
five classes' signatures against it, and whether `FieldConfig` declares
it, is the draft's first remaining task.

**Carried by.** "DataDefinitionInterface has no setter contract, so
anything that narrows a definition must depend on the DataDefinition
class", drafted, unfiled.

**Alone, it gives.** Code that tightens a definition it was handed (a
module rewording a label it did not declare, a form builder marking a
key required) type-hints an interface and accepts every implementation.
Nothing changes behavior.

### 1.4 Default values and example values

**The gap.** A definition cannot say what value it starts from, or what
a valid one looks like. Field API (`getDefaultValueLiteral()`), plugin
contexts (`ContextDefinition::getDefaultValue()`) and config
(`config/install`) each keep defaults their own way, so JSON Schema's
`default` and `examples` can never be filled from typed data, and a form
has nothing to put in `#placeholder`.

**Here today.** Both live on the definition, under the definition array
keys the drafts propose, `default_value` and `examples`, written and read
through one class that delegates to core's own method once one exists
(`src/DefinitionMetadata.php`). The emitter writes `default` and
`examples` from them. A form draws no example as a placeholder: an
empty field showing a value reads as filled, so a placeholder is the
key's declared arrangement (`describe($key, placeholder: ...)`).

**The core change.** `setExamples()` and `getExamples()` on
`DataDefinition`, and a default value as plain metadata: nothing
instantiates or validates with either. The default's method names are
open, because they collide: `BaseFieldDefinition` extends
`ListDataDefinition`, which extends `DataDefinition`, and already has a
`getDefaultValue(FieldableEntityInterface $entity)` that means something
else. The module tells the two apart by signature
(`DefinitionMetadata::supportsDefaultValue()`); core would rather pick a
name that does not collide, and the draft has to.

**Carried by.** "Allow data definitions to declare a default value" and
"Allow data definitions to declare example values", both drafted,
unfiled.

**Alone, it gives.** Schemas with `default` and `examples`; a
hand-written form that sets `#placeholder` from the definition; one
spelling that plugin contexts and other wrappers could later delegate to.

### 1.5 Two constraints core is missing

**The gap, for languages.** Core has `PluginExists`, `EntityBundleExists`,
`ExtensionExists` and `ConfigExists`, and nothing for a language code,
so `langcode` keys in config are left unchecked or checked by a
hand-written constraint, and cannot offer their allowed values.

**Here today.** `LanguageExists`, in the style of `PluginExists`: the
list resolved through the language manager when asked, an `allowLocked`
option as `PluginExists` has `allowFallback`, and a resolver offering
the languages with their names
(`src/Plugin/Validation/Constraint/LanguageExistsConstraint.php`,
`src/Plugin/SurfaceOptionsResolver/LanguageExistsOptions.php`).

**The core change.** The same constraint in
`core/lib/Drupal/Core/Validation/Plugin/Validation/Constraint/`; config
schema keys of `type: langcode` may then declare it.

**Carried by.** "Add a LanguageExists validation constraint beside
PluginExists, EntityBundleExists, ExtensionExists and ConfigExists",
drafted, unfiled.

**Alone, it gives.** Validated language codes in config.

**The gap, for lists.** Core registers `Count` for a list's size,
JSON Schema's `minItems` and `maxItems`, and nothing for `uniqueItems`.
Symfony has the `Unique` constraint and core does not register it, so a
list whose items must differ (tags, days, roles) is checked by a
hand-written constraint, de-duplicated in form code, or not checked.

**Here today.** `UniqueItems`, registered the way core registers
`Count`: Symfony's `Unique`, under an id of its own, with a message in
Drupal's placeholder style ("The item %value is listed more than
once.") and no option but the message. Its validator is Symfony's,
which compares items strictly, handed a list's plain values. The
emitter writes it as `uniqueItems: true` and the schema reader reads it
back (`src/Plugin/Validation/Constraint/UniqueItemsConstraint.php`,
`UniqueItemsConstraintValidator.php`).

**The core change.** The same constraint beside `Count` in
`core/lib/Drupal/Core/Validation/Plugin/Validation/Constraint/`; a
config schema `sequence` may then declare it, and the normalizer of 1.2
writes it as `uniqueItems`.

**Carried by.** Not drafted. It belongs with the `LanguageExists` draft
or in one of its own.

**Alone, it gives.** Lists in config whose items are held to differ, by
a constraint any schema reader can read.

### 1.6 Labels on `Choice`

**The gap.** Core registers Symfony's `Choice` class directly as the
`Choice` constraint (`ConstraintManager::registerDefinitions()`). It
knows which values are allowed and not what they mean:
`node.type.*.preview_mode` allows 0, 1 and 2
(`core/modules/node/config/schema/node.schema.yml`), while the words
Disabled, Optional and Required exist only in the `NodePreviewMode` enum,
which only `NodeTypeForm` reads (`NodePreviewMode::asOptions()`). Every
`#config_target` select restates its `#options` beside the schema's
`Choice`, and nothing checks that the two agree.

**Here today.** `LabeledChoice`, a subclass of that same Symfony class
with two optional options, `labels` and `descriptions`, which play no
part in validation, so code that recognizes a `Choice` with `instanceof`
recognizes this one too; code that matches the plugin id `Choice` does
not (`src/Plugin/Validation/Constraint/LabeledChoiceConstraint.php`,
`LabeledChoiceTest`). A [resolver](https://github.com/mlander/surface/blob/main/README.md#glossary) reads one
constraint, a `LabeledChoice` or the `LanguageExists` of 1.5, as a list
of values with labels, so the list that validates is the list a form
offers and the schema writes as `oneOf` of `const` and `title`
([Options and resolvers](options.md)).

**The core change.** Core registers a `Choice` class of its own under
the same id, a subclass of Symfony's carrying the two optional options,
so every existing use keeps working; config schema YAML may give
`labels` beside `choices` (how those are translated is the draft's open
question), and the normalizer emits `oneOf` with `const` and `title`
where labels exist. A separable second step: a service answering "the
options for this definition" from its constraints, one resolver per
constraint type, `LanguageExists` among them. Core's constraints with a
list (`Choice`, `PluginExists`, `EntityBundleExists`) could answer
through the same `OptionsProviderInterface` field items implement; a
resolver is what that looks like for a constraint, and this module's
`SurfaceOptions::provider()` answers that interface from one
([Core's question](options.md#cores-question)).

**Carried by.** "Allow Choice constraints to carry option labels, so
allowed values are declared once for validation, config schema, Form
API and JSON Schema", drafted, unfiled.

**Alone, it gives.** Config exports, recipes and API clients see what a
value means, and a hand-written `#config_target` form can read its
`#options` from the schema through the second step's service instead of
restating them. Writing the labels into a schema uses 1.2.

### 1.7 What narrowing means

**The gap.** Core has no way to ask whether one definition allows no
more than another. Without it, nothing can promise that a rule which
tightens a definition for some sibling values (1.8, 1.9) never loosens
it, and a schema written from the loose definition could be contradicted
by what is later accepted.

**Here today.** A conservative comparison, run on every narrowing:
adding a constraint, dropping allowed values, raising a minimum or
lowering a maximum of `Length`, `Range` or `Count`, and changing a type
to one of its derivatives (`entity` to `entity:node`) are narrower;
removing a constraint, turning off required, adding or removing a map
property, and replacing the options of any other constraint are
refused, so two `Regex` patterns or a contributed constraint's options
are never compared, only kept or refused
(`src/Refinement/Narrowing.php`, whose docblock is the full table;
`NarrowingTypesTest`, `NarrowingInsideMapsTest`; [the narrowing table](refinement.md#the-narrowing-table)).

**The core change.** The same comparison as a typed data service,
conservative by design: a wrong "narrower" is worse than a refused
change.

**Carried by.** Not drafted. The `refined_by` draft (1.9) names it as a
larger subject that belongs in its own issue.

**Alone, it gives.** A check any module can run on a definition it
tightens, such as a form alter narrowing a field's allowed values.

### 1.8 Conditions that only narrow

**The gap.** "When pricing is paid, the ticket needs a price" has no
declaration on a definition. Form API says it with `#states`, which only
the browser reads. Config schema can swap a sibling's whole type
(`type: ticket.[%parent.pricing]`), but a dynamic type is resolved only
when typed config builds the data, and it can swap a type, not add a
constraint to a key the type already has or make a key required; and
nothing can emit JSON Schema's `if`/`then` for it.

**Here today.** A rule declared as data, in the class that declares the
keys:

```php
$inputs->narrow('capacity')
  ->when(Condition::absent('license'))
  ->constrain('Range', ['max' => 100]);
```

Each class's `use` line: [Namespaces](concepts.md#namespaces).

Conditions come from a closed list (`present`, `absent`, `equals`, `in`,
`atLeast`, `atMost`, `above`, `below`, `not`, `allOf`, `anyOf`), each one
exact JSON Schema `if`; effects only narrow (a tighter bound or list,
required, or "this key does not apply here"), held to 1.7's check when
the class is first built, so a widening rule never runs. Validation
enforces it, and both schemas write it as one `if`/`then`
(`src/Refinement/Condition.php`, `src/Refinement/Rule.php`,
`DeclaredNarrowingTest`; [Declaring a rule](refinement.md#declaring-a-rule); why: [decision](decisions.md#a-rule-declared-as-data)).
In the honeypot port described at the end of this page, the settings
form's `#states` dependency became one such rule, stated in the schema
and refused by name at the form, the tool and the HTTP API alike ([the log](adopters-2026-10-19.md#what-was-expressed-better)).

**The core change.** `ComplexDataDefinitionInterface` gains
`addCondition(when:, then:, required:)`: `when` maps a property name to
the value it must hold, `then` maps a property name to a narrower
`DataDefinitionInterface` for it, and `required` lists properties that
become required. A config schema `mapping` gains a matching
`conditions:` key. `addCondition()` refuses a `then` that 1.7 does not
find narrower than the property's own definition, at the time it is
added; validation applies the conditions that match the values before
validating the properties, through 1.3's setters; and the normalizer
emits `if`/`then`, or `dependentRequired` for a condition that only
requires.

**Carried by.** "Let a complex data definition declare conditions, so a
property can be narrowed or required by a sibling's value and JSON
Schema can emit it", drafted, unfiled.

**Alone, it gives.** One declaration enforced by typed data and typed
config alike, where a `#states` rule today is enforced on the server
only if a `validateForm()` repeats it, and an `if`/`then` in every schema
core emits.

### 1.9 Which siblings a definition depends on

**The gap.** Some rules no condition states: a bundle list depends on
the entity type, a room list on the venue, through a lookup. Today such
a dependency exists only in a form's `#ajax` callback. Config schema can
bind a constraint option to a sibling (`%parent.key`), but the
placeholder is replaced only when typed config builds the constraints
for validation, so nothing reading the schema's definition sees which
sibling it names.

**Here today.** A refiner names the key it narrows on its attribute,
`#[RefinesInput('room')]`, and the siblings it reads as its parameters;
the module records them per key (`Entry::staticDependencies()` in
`src/Entry.php`) and both schemas state them as `x-surface.dependsOn`
([Your second resource](second-resource.md),
[Refinement](refinement.md)).

**The core change.** A definition may carry the sibling keys that narrow
it, `$definition->setSetting('refined_by', ['entity_type'])` read through
a getter, with no behavior in core beyond storing it. The normalizer
writes a `$comment` naming those siblings; where 1.8's conditions state
the cases, those are written instead.

**Carried by.** "Let a data definition declare which sibling values it
is refined by, so JSON Schema can emit the dependency as a conditional",
drafted, unfiled.

**Alone, it gives.** A schema that stops presenting a dependent list as
final, and a fact a hand-written form can wire `#ajax` from. A way for
the declaring code to supply the narrower definition for given sibling
values is step 4's.

### 1.10 Field defaults and field access without an entity

**The gap.** `FieldDefinitionInterface::getDefaultValue()` takes the
entity being made, because a default value callback may read it, and
`EntityAccessControlHandlerInterface::fieldAccess()` takes the field's
items. Node's handler reads them: its answer for `revision_log` calls
`$items->getEntity()` (`NodeAccessControlHandler::checkFieldAccess()`).
So describing "add an article" from the bundle alone means building a
throwaway entity, as `EntityForm` does.

**Here today.** The content entity submodule asks field access of a
throwaway entity of the bundle when adding, and states only literal
defaults, naming a callback default in the field's notes instead
(`modules/surface_examples_entity/src/Access/FieldItemAccess.php`,
`modules/surface_examples_entity/src/FieldItemDerivation.php`; holes 1 and 2
in [Content entities](entities.md#holes)).

**The core change.** A way to ask each question without an entity: a
default that says whether it is known without one (a sibling method or
a nullable parameter), and `fieldAccess()` documented and implemented so
that `$items === NULL` asks the general question, cacheable by field and
account.

**Carried by.** "Field default values and field access require an
entity to answer questions that are mostly static", drafted, unfiled.

**Alone, it gives.** A schema, a tool or an add form for a bundle with
no stub entity. The module's entity side is paused (see [the last
section](#what-this-module-is-evidence-for-and-what-it-is-not)), so this
draft rests on less evidence than the others.

### 1.11 The global `t()` in static context

**The gap.** This one is the coding standards', not core's code. The
standards steer object-oriented code from the global `t()` to an
injected `$this->t()`, which a static method or a declaration cannot
have; the alternative used there, `new TranslatableMarkup()`, builds the
same lazy object `t()` returns, at twenty-two more characters a label.
Declarations of definitions are mostly static.

**Here today.** Static declarations call the global `t()` with a literal
string, and `new TranslatableMarkup` appears only inside attribute
arguments, where a call is not allowed (why: [decision](decisions.md#a-resource-or-structure-is-static)). The guard that
stops a static refiner reading site state lets `t()` through, because
`t()` only builds the lazy object; the language and the translation are
read when it is rendered, after the refiner has returned
(`src/Pipeline/PipelineGuard.php`).

**The core change.** None in core: the standards name `t()` as the
sanctioned spelling in static and declarative context.

**Carried by.** "Coding standards: the global t() should be the
sanctioned spelling in static and declarative context, not a smell",
drafted, unfiled, for the coding standards queue.

**Alone, it gives.** Definitions declared in static methods read as
plain code, with the literal `t()` calls string extraction already
finds.

## Step 2: operations and access for simple config

**The gap.** An entity type has what this step asks for, and a simple
config object has none of it:

| | Entity types | Simple config |
| --- | --- | --- |
| Operations | `view`, `update`, `delete` asked through `EntityAccessControlHandlerInterface::access()`, and `createAccess()`; a form class per operation among the type's `form` handlers; routes from a route provider | A `ConfigFormBase` subclass and a route with `_permission`; nothing names an operation |
| Access | An access handler, and `fieldAccess()` per field | The route's permission, which only the route reads |
| Validation on write | A content entity's `validate()`, which `ContentEntityForm` and JSON:API run (a config entity has none) | `Config::save()` casts values to the schema and checks no constraint; a config action validates after the write, and only when the schema is marked `FullyValidatable` (`ConfigActionManager::applyAction()`) |

So a Drush command run as an account, a config action, a REST endpoint
or an agent that writes a settings object asks no permission and runs no
validation unless its author wrote them, and the form's rules stay in
the form.

**Here today.** A resource over a config object declares each operation
as a static method carrying `#[Operation(permission: ...)]`. A route that
names the resource and the operation is a working form with no form
class (`src/ResourceBuild/OperationRoute.php`,
`Form\SurfaceOperationForm`), and one tool per operation is derived
from it. One answer gates every door: the operation's permission, then
an optional access class, asked through `ResourcesInterface::access()`
by the route requirement `_surface_operation_access`, the form, the
tool and the pipeline ([Access](resources.md#access)). For the resource
as a whole the answer is allowed or denied; for a group of keys inside
it, the access class answers open, locked (shown and fixed) or omitted
(`src/Resource/AccessAnswer.php`). Whether an answer can be stated ahead
of time is read from its cacheability and never declared: one that
varies only by the account's permissions or roles is static, and is
stated in a cached schema or tool listing for the account that asks;
anything else (a cache tag, which means it read stored data that can
change while the account does not; the `user` context; a max-age other
than permanent) is
runtime, and is marked there and settled when the thing is loaded
(`AccessAnswer::kindOf()`, `StaticRuntimeAccessTest`; [Static and runtime access](resources.md#static-and-runtime-access)). A write
validates the whole config object against its schema before anything is
saved (`ConfigObjectStorage::prepare()` in
`src/Storage/ConfigObjectStorage.php`; [A simple config object](storage.md#a-simple-config-object)). In the honeypot port, three
`validateForm()` rules that used to run only in the form, and one
permission, now hold at the route, the form, the tool and the HTTP API
alike ([the log](adopters-2026-10-19.md#what-was-expressed-better)).

**The core change.** A simple config object may name its operations, at
least the one that edits it, each with a permission, and an optional
access handler that answers per key, as `fieldAccess()` does per field,
with cacheability that says what it read; a write through an operation
validates against the config schema before it saves. Where they are
named is open; one spelling is an `operations:` key on the object's
`config_object` root in its config schema, beside the constraints
already allowed there. A config object described by a data definition
needs no schema of its own: typed config validation would read the
types, constraints and nullability from the definition rather than from
a mapping (`TypedConfigManager::buildDataDefinition()` builds the same
definition from YAML today), config translation the translatable keys,
and `FullyValidatable` which keys are always held, so the schema this
module writes from a resource (`drush surface:config-schema`,
[Storage](storage.md#a-schema-generated-from-the-resource)) would go.
Core's cache rules already make the static and
runtime split readable: an answer whose only cache contexts are
`user.permissions` or `user.roles`, with no tag and a permanent max-age,
can be stated for an account ahead of time.

**Carried by.** Not drafted. The entity-free `fieldAccess()` of 1.10 is
the entity side of the same per-key question.

**Alone, it gives.** A settings object every caller writes through one
permission and one validation: the form, a config action (refused before
the write rather than after it), Drush, REST, an agent. A hand-written
`ConfigFormBase` can ask the same operation, and can disable or hide the
keys a per-key answer locks or omits.

## Step 3: third-party settings for simple config

**The gap.** A config entity carries `third_party_settings`, typed in
config schema as `[%parent.%parent.%type].third_party.[%key]` and
dropped when the module that wrote them is uninstalled
(`ConfigEntityBase::onDependencyRemoval()`). A simple config object has
no such place, so a module adding a setting to another module's
settings page stores it in an object of its own and draws it with
`hook_form_alter()`: the setting is in no description of the page it
appears on. Core already does it once: the `theme_settings` type
declares a `third_party_settings` sequence typed
`theme_settings.third_party.[%key]`
(`core/config/schema/core.data_types.schema.yml`).

**Here today.** An alter's keys are stored inside the owner's config
object, under `third_party_settings.<module>`; the owner's schema
declares that sequence, typed by module name, and the module that adds
keys describes its own part. The storage refuses a module's keys that no
schema describes (`ConfigObjectStorageTest::testMountedSettingsNeedTheirOwnSchema`;
[Keys another module mounts](storage.md#keys-another-module-mounts); a
whole alter with both schemas is quoted in
[Resources](resources.md#a-whole-alter)).

**The core change.** The `config_object` type gains the optional
`third_party_settings` sequence that `theme_settings` already has, typed
by module name, with accessors on `Config`. Removal on uninstall is the
open part: a simple config object records no dependencies, so core
would find the objects holding a module's keys some other way, for
instance by reading, at uninstall, every object whose schema declares
the sequence.

**Carried by.** Not drafted.

**Alone, it gives.** Another module's setting stored with the object it
configures: exported with it and validated by its schema, with no
change to forms; until step 5 the form alter that draws it stays. Not
covered: a module that adds entries to a list or map the owner itself
stores (pathauto's punctuation hook), which a place under the module's
own name does not answer (open, below).

## Step 4: a served contract

**The gap.** The serialization module's JSON Schema describes what a
normalizer outputs. Nothing in core describes what an operation on a
config object or an entity accepts, with the dependencies between its
keys, and nothing promises that such a description is never stricter
than the validation that will judge the write. A client that wants to
send a valid write finds the rules by being refused.

**Here today.** From one resource built for one operation, the emitter
writes two JSON Schema 2020-12 documents by one code path
(`ContractEmitter::emit()` and `emitFull()`; [Two documents](served-contract.md#two-documents)):

- **The schema for these answers**, for one set of values: each list
  narrowed for the siblings as they stand (the rooms of the venue
  chosen). A form is drawn from it, and it is true of those values only.
- **The full schema**, which reads no value and so holds for any: every
  key as declared, and every dependency whose cases can be listed
  written as `if`/`then` on the parent (one per venue). A validator, or
  an agent planning its values, reads this one. Where a rule cannot be
  stated it stays at the declared width and says why: `checkedOnServer`
  names a constraint with no keyword, `refine` a dependency on a value
  whose cases cannot be listed (free text, or more combinations than a
  cap), `live` a rule that reads site state, such as a lookup.

One document cannot do both: a list that depends on a sibling is either
narrowed for one value of it, and wrong for the others, or written for
all of them as conditionals, which a form would need a JSON Schema
evaluator to draw from.

Neither is ever stricter than the pipeline, judged on what the pipeline
accepts after coercion; where a rule is only marked, the schema is
wider, never narrower ([Never stricter than the runtime](served-contract.md#never-stricter-than-the-runtime)). An audit
written on 2026-10-18 found where that failed and recorded what each
case became ([the audit](audit-never-stricter.md)), and matrix tests run
each example's accepted and refused documents through the pipeline and
both schemas with a standard validator
(`tests/src/Kernel/Matrix/AdvertisedSchemaAcceptanceTest.php`,
`tests/src/Kernel/Matrix/InputCoercionMatrixTest.php`). A React app
renders a form from these documents alone (`modules/surface_examples_react`),
and committed baselines catch a breaking change to them ([Evolving a resource](evolution.md)).

**The core change.** For an operation from step 2, compose a schema of
what it accepts from the definitions step 1 enriched (keywords, labels,
defaults, examples, conditions, dependencies), in the same 2020-12
dialect core's normalizers already write, and serve it beside the
operation, with the rule, and the tests, that it is never stricter than
validation. The schema for these answers also needs the narrower
definition for the values at hand: from 1.8's conditions where they
state it, and otherwise from a callback the declaring code names beside
1.9's `refined_by`, held to 1.7's check.

**Carried by.** Not drafted as one issue; its parts are 1.2, 1.4, 1.6,
1.7, 1.8 and 1.9.

**Alone, it gives.** A decoupled client or an agent that can plan a
valid write before sending it, and a stated contract that a change can
be checked against. It needs steps 1 and 2; step 3 only for other
modules' keys.

## Step 5: Form API, last

**The gap.** In core, the rules for a group of values often live only in
its form: `#options`, `#states`, `#element_validate`, `validateForm()`
and `#ajax` callbacks, none of which anything but the form can read.
`ConfigFormBase` with `#config_target` is core's own half of this idea
(defaults from config, typed config validation, violations shown on
their elements), but the form is still written by hand, and
`ConfigFormBase::copyFormValuesToConfig()` is private.

**Here today.** One generic form per operation, built from the
definitions. A widget is chosen per definition from the definition
alone, asked for with a setting where the definition does not settle it
(`setSetting('widget', 'radios')`; [Widgets](widgets.md);
why: [decision](decisions.md#a-widget-is-chosen-from-the-definition)). A key
another depends on gets `#ajax`, and a change replaces only the
elements that depend on it ([Wrapper ids and AJAX](forms.md#wrapper-ids-and-ajax)). Presentation (grouping, sizes, a
borrowed element) lives in a separate layer that cannot change what a
value means ([The cosmetic seam](forms.md#the-cosmetic-seam)).

Counted in code lines without blanks or comments ([line counts](adopters-2026-10-19.md#line-counts)): honeypot's form class was
201 lines and its port is 216 lines of PHP, of which the resource class
is 48. Pathauto's was 195 and its port is 441, most of it code that
lists keys from site state (one per entity type, one per punctuation
mark), its own constraints and one widget. The ports are not smaller;
what changed is that every rule holds at every door.

**What Form API would need.** Two things, both of which this module
works around rather than through:

- **States and validation as data.** `#states` runs in the browser, and
  an element's own checks (`#min`, `#maxlength`, a machine name's) run
  before any form-level handler; a form state keeps only the first error
  set for an element (`FormState::setErrorByName()` sets one only where
  none is set), so a definition's rule and its message lose to the
  element's. The module clears the element's server-side check and
  keeps the limits as attributes (`min`, `max`, `maxlength`), hints
  the browser still reads, so the rule speaks (why: [decision](decisions.md#an-element-hints-and-the-rule-speaks)).
  Form API reading `#states` from a definition's conditions (1.8) and
  its checks from its constraints would make the element a hint and the
  definition the rule.
- **Extraction owned by the element.** `#parents` are absolute from the
  form's root while a subform state reads relative to its fragment, so a
  value read through the wrong one comes back `NULL`, silently ([The SubformState lesson](forms.md#the-subformstate-lesson)); and an element
  swapped for another (checkboxes for a select) posts a different
  structure. An element that reads its own value back in the structure
  its definition describes, wherever it sits, is what lets one
  definition render the same way everywhere ([Extraction returns raw values](widgets.md#extraction-returns-raw-shapes)).

**Carried by.** Not drafted. Three drafts name its first follow-ups: the
conditions draft (1.8) and the `refined_by` draft (1.9) both name
`#ajax` wired from the declaration, and the labels draft (1.6) names
`#options` derived from a labeled `Choice`.

**Alone, it gives.** With steps 1 to 4 in place, a settings page, a
block's settings or a content type's form with no form class, which
cannot disagree with the API because both read one declaration.

## What this module is evidence for, and what it is not

- **Fresh eyes, 2026-10-18.** An agent given only the documentation
  built a newsletter settings page and a second module extending it, and
  every piece worked the first time it was wired
  ([the log](fresh-eyes-2026-10-18.md)). Its verdict was yes for the
  schema and API half, not yet for forms; its three suggestions (a
  storage class for a config object with a settings page recipe, "this
  key does not apply" as an idea of its own, and the rule's own message
  reaching the person) have since been built.
- **Fresh eyes, 2026-10-19.** A second agent built a library's opening
  hours, with a group of keys reused by two resources, a declared rule, a
  runtime cap and another module's alter, and every rule agreed at the
  form, the tool, the schema and the React page
  ([the log](fresh-eyes-2026-10-19.md)). Its verdict was yes for the
  schema and API half, and yes for an admin settings page whose form is
  right in what it accepts though plain to look at. Both readers were
  agents; the same exercise by a person has not been run.
- **Two adopters, 2026-10-19.** Honeypot's and pathauto's settings forms,
  which this project did not write, were ported by an agent in sandbox
  modules, outside this repository, with both originals untouched
  ([the log](adopters-2026-10-19.md)). Honeypot's validation rules now
  hold at every door and its `#states` is a stated conditional.
  Pathauto's showed the gaps a contributed module meets first: keys
  listed from site state cost 107 lines against the original's loop of
  about 20; one checkbox in a list shown checked and disabled while the
  others stay editable cannot be said; and a hook that adds entries to
  the owner's list has no equivalent.
- **The entity side is paused.** A resource is derived for each content
  entity bundle, and a basic page is added and edited through it
  ([Content entities](entities.md)). Multi-value fields, references,
  images and path aliases through every door, the roadmap's definition
  of done for that side, are not built, so 1.10 and anything about
  content entities rest on less.
- **Weight.** The concept inventory lists forty concepts, each with the
  question that makes a developer need it, and a working settings page
  uses four: a resource, an operation, a storage, and the keys declared
  ([Concepts](concepts.md), [Your first resource](first-resource.md)).
  The two adopter ports between them used about a dozen and never needed
  fourteen others.
- **Open.** Not built, each found by the runs above (`ROADMAP.md`, item
  14): a fixed state per option in a list; an alter adding entries to a
  map the owner stores; a default read from a sibling key; a time-of-day
  type (the widget exists, the type does not); keys listed from site
  state costing a class of their own; and, through the tool, required
  keys sent again on every edit, a decision still pending. Stored-value
  migrations are designed and not built, so a breaking change cannot yet
  be versioned, and nothing is promised stable before a tagged release
  ([What is promised](evolution.md#what-is-promised)).

What it is evidence for: that the definitions core already has, plus the
facts of step 1, are enough to state a settings object's rules once and
hold a form, a tool, an HTTP API and a schema to them. What it is not:
evidence that this order is the only one, that this module's PHP API is
the one core should adopt, or that content entities fit the same way.

## Smaller gaps, outside the sequence

| Gap | Here today | Upstream |
| --- | --- | --- |
| No multiline string type | The definition setting `multiline` on a `string`, drawn as a textarea | Candidate: a `text` type, as config schema's `type: text` |
| No `patternProperties` or `additionalProperties` on maps | Not needed yet; maps are closed here | [#3556242](https://www.drupal.org/project/drupal/issues/3556242) |
| `required` mixes non-empty, nullable and present | `NULL` and `''` mean not configured; anything else is a value ([Value semantics](semantics.md#configured-or-not)) | No issue named here |
| Nothing says a value is fixed in this context | The identity keys an operation knows are locked by the resource, not by a definition flag | Possibly nothing; revisit |
| A simple config object records no dependencies | A config object's references to content are stored by uuid, so they deploy, but an export and an uninstall do not know what they name; a config entity's are recorded under `dependencies` ([A reference is stored by uuid](storage.md#a-reference-is-stored-by-uuid)) | No issue named here |
| A config entity's dependencies come only from its class | Core recalculates them on every save from plugins and third party settings; a key that refers to an entity is neither, so a save through `ConfigEntityStorage` adds them in a presave hook, and a save made any other way drops them | No issue named here |

The contributed Tool API has gaps of its own: it reads `Choice` and
`AllowedValues` by plugin id, so a subclass such as `LabeledChoice`
does not reach its schema; a context definition carries no settings or
examples; defaults reach its schema only for truthy scalars. They are
listed in [The Tool API door](tools.md#notes-for-the-tool-api) and belong to that
project, not core.

Facts about core this page relies on, each checked against core 11.4:

- `ConfigEntityBase` (`core/lib/Drupal/Core/Config/Entity/ConfigEntityBase.php`)
  has no `validate()`, and `Config` has none either: config is validated
  only when something asks typed config for it
  (`TypedConfigManager::createFromNameAndData()->validate()`), as
  `ConfigFormBase::validateForm()` does for `#config_target` elements and
  a config action does after its write.
- `FullyValidatableConstraintValidator::validate()` is a no-op; only
  `ConfigActionManager::applyAction()`
  (`core/lib/Drupal/Core/Config/Action/ConfigActionManager.php`) reads
  the marker, after `$action->apply()` has written.
- `ConfigFormBase::copyFormValuesToConfig()`
  (`core/lib/Drupal/Core/Form/ConfigFormBase.php`) is private static,
  although the class docblock invites overriding it.
- `Config::save()` (`core/lib/Drupal/Core/Config/Config.php`) casts the
  data through the schema when one exists, and otherwise checks only
  that each value is a scalar or an array.
