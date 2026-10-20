# Naming and API consistency

## 1. Standpoint

I review as a naming and API-consistency reviewer: I read every public name an author meets (the attributes and their arguments, the blueprint verbs, the value objects, the interfaces, the sentinels, the storage classes, the `x-surface` keywords, the glossary and the doc titles) and ask whether one idea always has one word and one word always means one idea. I hold the module first to its own promise, that the glossary's twenty-three words are used "in exactly one sense each" (README.md:131), then to Drupal core's conventions, then to the ecosystems it borrows from (Smithy, JSON Schema, PSR-7, MCP).

## 2. The five renames and naming rules that would most improve authoring

### 1. `#[Variant]` names the slot and the value it is for

**Problem.** The parent declares the slot as `$inputs->attachBy('ticket', by: 'pricing')` (modules/data_surface_examples/src/Resource/RegistrationStep3Resource.php:57). The child answers `#[Variant(of: RegistrationStep3Resource::class, key: 'ticket', value: 'free')]` (modules/data_surface_examples/src/Structure/FreeTicketStructure.php:20). Read as written, `key: 'ticket', value: 'free'` says "the ticket is free". It means "pricing is free", and the variant cannot name `pricing`, by the wall. Everywhere else in the authoring API `$key` is the key being declared or narrowed (`add($key)`, `narrow($key)`, `#[RefinesInput(key)]`, src/Resource/Attribute/RefinesInput.php:91) and a value is a value of that key; only here is `key` a container and `value` a value of a different key. The glossary word is **Slot** (README.md:245), the engine's class is `Slot` (src/Slot.php:30), the emitter's method `slot()` (src/Contract/ContractEmitter.php:1888), the widget hint `slot` (docs/served-contract.md:283), and the contract already words the value as "which value it is for" (docs/served-contract.md:292). Only the attribute the author writes avoids the word.

**Change.** Rename the two arguments: `slot:` and `for:` (PHP accepts a reserved word as a named argument; I checked on the local PHP 8.4). Keep `attachBy()`: it belongs to a coherent family (`attach`, `attachList`, `attachEach`, `attachBy`, each attaching a child, the suffix saying how many or chosen how), and the misreading is on the child's side, not the parent's. Give the slot its own heading in `docs/resources.md`: the compose table links the slot row to `#children` (docs/resources.md:30) while every other row links a heading named for its glossary word.

```php
// Before: modules/data_surface_examples/src/Structure/FreeTicketStructure.php:20
#[Variant(of: RegistrationStep3Resource::class, key: 'ticket', value: 'free')]

// After
#[Variant(of: RegistrationStep3Resource::class, slot: 'ticket', for: 'free')]
```

**Cost.** A rename of two attribute parameters (src/Resource/Attribute/Variant.php:29-33), 23 `#[Variant(` lines, and the docs. **Breaks.** Every variant class an adopter wrote, at discovery, loudly. No contract changes: the emitted `x-surface.by`, `variants`, `chosen` and `variant` (docs/served-contract.md:291-292) never carried the parameter names.

### 2. `with()` becomes `mixIn()`

**Problem.** In the files an author writes, `with` already has a meaning: the immutable copy. `OperationContext::withId()`, `withKnown()`, `withConstraint()`, `withStarting()` and `withChild()` (src/Resource/OperationContext.php:73, 86, 103, 118, 165) each return a new context and leave the old one alone, the PSR-7 convention PHP developers carry. `BlueprintAdditionsInterface::with()` (src/Resource/BlueprintAdditionsInterface.php:268) mutates the blueprint, returns `$this`, and means "merge this structure's members into my own keys". `$inputs->with(ReviewStructure::class)` also reads as "with a review", which is what `attach()` gives. The glossary word is **Mixin** (README.md:255), the compose table and its rule of thumb say "mix in" (docs/resources.md:28, 34-35), and the engine already names the step `Resources::mixIn()` (src/ResourceBuild/Resources.php:851), `SchemaResolver::mixIn()` (src/Contract/SchemaResolver.php:134) and `mixIn()` in the React app (modules/data_surface_react/app/src/contract.ts:371). The author's verb is the only generic one. I know `with` is Smithy's word (`structure X with [Mixin]`), and the module took its nouns from Smithy (docs/decisions.md:39-46); but there it is a declaration keyword, and in PHP the prefix is taken.

**Change.** `mixIn()`, same arguments; `with()` kept one release as a deprecated alias if wanted.

```php
// Before: modules/data_surface_examples/src/Resource/PressResource.php:39-41
$inputs->attach('contact', PersonStructure::class, reads: ['listed' => 'public'])
  ->setLabel(t('Press contact'));
$inputs->with(ReviewStructure::class);

// After
$inputs->attach('contact', PersonStructure::class, reads: ['listed' => 'public'])
  ->setLabel(t('Press contact'));
$inputs->mixIn(ReviewStructure::class);
```

**Cost.** A rename, 41 lines in 32 files. **Breaks.** Adopters' `with()` calls (none in a contributed module yet). No wire change: a mixin is emitted as `allOf`, and no keyword names it.

### 3. `src/Storage/` holds only what an author names

**Problem.** "Storage" names two interfaces with the same three verbs and different signatures: `ResourceStorageInterface::load(OperationContext)` (src/Resource/ResourceStorageInterface.php:40), which `#[Resource(storage:)]` names, and `DataSurfaceStorageInterface::load(DataSurfaceInterface)` (src/Pipeline/DataSurfaceStorageInterface.php:40), which the pipeline writes to; docs/storage.md:254-263 has to warn that "nothing an author writes implements the first". Yet the seven "engine storage classes" (docs/storage.md:409) share one namespace, `Drupal\data_surface\Storage`, and one suffix, and only one of them, `ConfigObjectStorage` (src/Storage/ConfigObjectStorage.php:63), is the kind a resource names; `StateStorage`, `ConfigEntityStorage`, `FieldSettingsStorage`, `BaseFieldOverrideStorage`, `PluginConfigurationStorage` and `CompositeStorage` are the pipeline's. The module's own naming rule says "a storage class [ends] in `Storage`" (docs/declaring-a-resource.md:334-335), so `#[Resource(storage: StateStorage::class)]` looks right and is refused at build with "is named as a ... but does not implement it" (src/ResourceBuild/Resources.php:597, 3205), which does not say why.

**Change.** Let the namespace say the layer. Move the six to `Drupal\data_surface\Pipeline\Storage\`, beside the interface they implement; `Storage\` keeps `ConfigObjectStorage`, `SchemaViolations`, the layout and the secret codec. The rule: a class in `Storage\` is one you name on `#[Resource(storage:)]` or extend. Short class names stay, so the README's pipeline snippet stays legible.

```php
// Before: docs/concepts.md:125, and README.md:117-121's snippet
use Drupal\data_surface\Storage\StateStorage;
use Drupal\data_surface\Storage\PluginConfigurationStorage;

// After
use Drupal\data_surface\Pipeline\Storage\StateStorage;
use Drupal\data_surface\Pipeline\Storage\PluginConfigurationStorage;
```

**Cost.** A namespace move of six classes; `DataSurfaceStorageInterface` appears on 82 lines in 31 files. **Breaks.** Adopters' imports of the six (the plugin host bases ship in this module, so most adopters are unaffected). Not the product rule: the `Drupal\data_surface` prefix stays.

### 4. One interface family: `Defines*` for the owner, `Alters*` for an alter, one word for a layout

**Problem.** The owner's two contracts are `DefinesInputsInterface::defineInputs()` (src/Resource/DefinesInputsInterface.php:27, 45) and `HasOutputsInterface::defineOutputs()` (src/Resource/HasOutputsInterface.php:23, 34): one pair, two prefixes. An alter's are `AltersInputsInterface::alterInputs()` (src/Resource/AltersInputsInterface.php:21, 29), `AltersOutputsInterface::alterOutputs()` (src/Resource/AltersOutputsInterface.php:10, 18), `AltersAccessInterface::access()` (src/Resource/AltersAccessInterface.php:39, 54), and `HasStorageLayoutInterface::storageLayout()` (src/Resource/HasStorageLayoutInterface.php:26, 34), alter-only by decision (docs/decisions.md:958) but without the prefix that would tell a reader so. What it returns is a `SettingsLayoutInterface` (src/Storage/SettingsLayoutInterface.php:39), while the glossary calls it a **Layout**, "how a storage lays accepted values out" (README.md:163), and the method calls it a storage layout: three names for one thing, and "settings" is wrong for a State value or a config entity's properties.

**Change.** `HasOutputsInterface` becomes `DefinesOutputsInterface`; `HasStorageLayoutInterface` becomes `AltersStorageLayoutInterface`, method unchanged; `SettingsLayoutInterface` becomes `StorageLayoutInterface`, with `EncryptedSettingsLayout` and `AddressSettingsLayout` following. `Has` is retired as a prefix. `AltersAccessInterface::access()` keeps its method: it is the same method as `OperationAccessInterface::access()` on purpose (src/Resource/AltersAccessInterface.php:32).

```php
// Before: modules/data_surface_demo_extras/src/Alter/NodeTypeAlter.php:65, 124
final class NodeTypeAlter implements AltersInputsInterface, HasStorageLayoutInterface {
  public function storageLayout(): SettingsLayoutInterface {

// After
final class NodeTypeAlter implements AltersInputsInterface, AltersStorageLayoutInterface {
  public function storageLayout(): StorageLayoutInterface {
```

**Cost.** Renames of three interfaces and two classes: `HasOutputsInterface` 30 lines in 19 files, `HasStorageLayoutInterface` 18 in 15, `SettingsLayout` 98 in 31. **Breaks.** Adopters implementing them (`DataSurfaceDemoFormatter`, modules/data_surface_demo/src/Plugin/Field/FieldFormatter/DataSurfaceDemoFormatter.php:52, is the shape of it). No wire change.

### 5. The glossary covers every word a name uses, each in one sense

**Problem.** README.md:131 promises twenty-three words in one sense each. It falls short three ways.

- *Words in names, missing from it.* **Variant** is an attribute and appears 119 times across the twelve pages a newcomer reads first; **owner** 130 times (docs/refinement.md:35 defines it inline), **child** 221, **container** 102, **mount** (the `third_party_settings.<module>` an alter's keys live under) 51. None is in the glossary. **Contributor** has its own role row (docs/refinement.md:32) and is the alter by another name; the concept inventory has no such row.
- *Words in a second sense.* "Site" is the Drupal site in keys from the site (`KeysFromSiteInterface`) and the point where a def is used in the contract docs: "On a site, the property where a child that reads ports is used" (docs/served-contract.md:302), "A port is stated at its site" (docs/served-contract.md:511). "Shape", fixed to Smithy's named type (docs/decisions.md:69 and the Blueprints entry at :173), still means an array's form in "Extraction returns raw shapes" (docs/widgets.md:169, 172) and in `Slot`'s docblock, "A key whose shape a sibling chooses" (src/Slot.php:13, 15). "Omitted" is an output not emitted (`Omitted::value()`, src/Pipeline/Omitted.php:71) and a key withheld from an account (`AccessAnswer::omitted()`, src/Resource/AccessAnswer.php:141). "The run-time part of access" (src/Resource/Attribute/Resource.php:42, src/Resource/Attribute/Structure.php:29) uses the word the access decision gives another meaning: an access class's answer may well be static (docs/decisions.md:1414).
- *Spelling.* Drupal writes US English; the module ships `Licence` and `event.licence` (modules/data_surface_examples_compliance/src/Shape/Licence.php:18), `catalogue` (`data_surface.resource_catalogue`, data_surface.services.yml:78; `ResourceCatalogue`), "labelled" (docs/entities.md:69) and "honour" (src/ResourceBuild/BlueprintAdapterBase.php:367), beside its own `LabeledChoice`.

**Change.** Add Variant, Owner, Child and Mount to the glossary, each one line; fold Contributor into "the owner and its alters". Say "where it is used" for the point of use. Reword the two strays of "shape". Rename the output sentinel `NotEmitted`. Replace "run-time part of access" with "what depends on more than the permission". Spell US English.

```php
// Before: src/Pipeline/Omitted.php:27, beside FeesWorkflowAlter.php:52's AccessAnswer::omitted()
'classes' => $variant === NULL ? Omitted::value() : ['v-' . $variant],

// After
'classes' => $variant === NULL ? NotEmitted::value() : ['v-' . $variant],
```

**Cost.** Docs for most of it; renames for the sentinel (16 lines in 5 files), `Licence` (627 lines in 53 files, most of them prose) and `catalogue` (197 lines in 37 files). No new concept: the four words are already used, the glossary only admits it. **Breaks.** `event.licence` is a `$defs` id and `licence` a key in examples 3 and 8, so their contract baselines move; no adopter contract does.

## 3. Borrow

- **From Smithy, the rule behind its words, not only the words.** The module took resource, structure, shape and operation from Smithy (docs/decisions.md:39-46). Smithy's discipline is that the IDL keyword is the concept's name (`resource`, `structure`, `operation`, `@mixin`, `list`). Applied here, each composition verb carries its glossary word or a family name that does (changes 1 and 2), which costs nothing against static declarations: the name is still read off an attribute or a static call.
- **From JSON Schema, its keywords stay its own.** The `x-surface` keywords follow JSON Schema's lowerCamel (`checkedOnServer`, `patternMessage`, `dependsOn`), which is right. The one lapse is `readOnly`: at the top of one document `readOnly: true` means the resource is locked whole (docs/served-contract.md:284), while `x-surface.operation.readOnly` means the call changes nothing (docs/served-contract.md:304). A contract a client reads beside JSON Schema should never reuse a JSON Schema keyword for another meaning; the engine already has the right word, `DataSurfaceInterface::changesNothing()` (src/DataSurfaceInterface.php:204). This serves the never-stricter promise: a client that confuses the two would refuse an edit it may make.
- **From PSR-7, `with` means a copy.** The basis of change 2.
- **From Drupal core, the prefix and suffix rules.** Interfaces end in `Interface`, bases in `Base`, attributes are bare nouns (`#[Block]`, `#[FieldType]`), and a value object's stored property is read with `get*` where core's sibling does (`AccessResult::getReason()`). The module follows all of this except `AccessAnswer::answer()` beside `getReason()` (src/Resource/AccessAnswer.php:282, 292). Core also avoided a bare `Resource`: REST's attribute is `#[RestResource]`, and `Drupal\rest\Plugin\ResourceInterface` (core/modules/rest/src/Plugin/ResourceInterface.php) shares a short name with the module's. I would not rename the module's (the concept word is right and the cost is the whole API), but the namespaces table should show the alias, as it should for `Refinement\Condition` beside core's `#[Condition]` plugin attribute (core/lib/Drupal/Core/Condition/Attribute/Condition.php) in a condition plugin that is its own structure.

None of these adds a concept, and the settings page stays at its four.

## 4. Cut

- **Contributor**, as a word: it is an alter, and the owner is the owner (docs/refinement.md:25-38). One role table with "Owner" and "Alter" says the same.
- **Starting**, as a word: `withStarting()` and `starting:` (src/Resource/OperationContext.php:57, 118) set what docs/decisions.md:409 calls exactly a default, "Starting values are defaults". `add(default:)`, `addEach(default:)` and `KeyedList::add(default:)` already say `default`. Make it `withDefaults()`; the refusal for a non-creating context (docs/decisions.md:402) keeps the distinction.
- **The odd return.** Every declaring verb returns what it declared (`add()`, `attach()`, `attachList()`, `attachBy()`, `addEach()`, `attachEach()`), except `addDefinition()`, which returns `$this` (src/Resource/BlueprintAdditionsInterface.php:78), so the long form cannot be labeled by chaining as the short form is.
- **Second titles.** Four pages carry one title in their H1 and another in the nav and README: `resources.md` "Resources and structures as classes" against "Resources and structures" (mkdocs.yml:35); `declaring-a-resource.md` "Declaring a resource or structure" against "Declaring a resource" (mkdocs.yml:38); `refinement.md` "Refinement, contributions, and cacheability" against "Refinement and contributions" (mkdocs.yml:53) and "Refinement" (README.md:371); `pattern.md` "The resource pattern" against "The pattern" (mkdocs.yml:33). Keep the H1 and copy it out. The nav's "Forms" group also holds the served contract, evolution and the audit (mkdocs.yml:45-51), and two pages are a "Catalogue" in different senses (`adoption.md`, `catalogue.md`).

## 5. Names and spelling that read wrong

"Crosses?" asks whether the proposal crosses [the product keeps its name](../decisions.md#the-product-keeps-its-name). "Wire" marks a rename a client reads, which the decision's own precedent defers to a contract version (docs/decisions.md:150).

| Current | Proposed | Reason | Cost | Crosses? |
| --- | --- | --- | --- | --- |
| `#[Variant(of:, key:, value:)]` (src/Resource/Attribute/Variant.php:29-33) | `#[Variant(of:, slot:, for:)]` | `key: 'ticket', value: 'free'` reads as "ticket is free"; the value belongs to the deciding key (change 1) | 2 parameters, 23 uses | No |
| `BlueprintAdditionsInterface::with()` (src/Resource/BlueprintAdditionsInterface.php:268) | `mixIn()` | `with*` is the copy prefix on `OperationContext`; engine already says `mixIn()` (change 2) | 41 lines, 32 files | No |
| `Storage\StateStorage`, `ConfigEntityStorage`, `FieldSettingsStorage`, `BaseFieldOverrideStorage`, `PluginConfigurationStorage`, `CompositeStorage` | the same names under `Pipeline\Storage\` | they implement the pipeline's interface, not the one `#[Resource(storage:)]` names (change 3) | 6 classes moved | No: the prefix stays |
| `HasOutputsInterface` (src/Resource/HasOutputsInterface.php:23) | `DefinesOutputsInterface` | pairs with `DefinesInputsInterface`, same `define*()` method (change 4) | 30 lines, 19 files | No |
| `HasStorageLayoutInterface` (src/Resource/HasStorageLayoutInterface.php:26) | `AltersStorageLayoutInterface` | alter-only; every other alter interface says `Alters` (change 4) | 18 lines, 15 files | No |
| `SettingsLayoutInterface`, `EncryptedSettingsLayout`, `AddressSettingsLayout` (src/Storage/SettingsLayoutInterface.php:39, src/Storage/EncryptedSettingsLayout.php:76, modules/data_surface_address/src/AddressSettingsLayout.php:38) | `StorageLayoutInterface`, `EncryptedStorageLayout`, `AddressStorageLayout` | glossary "Layout" of a storage; the method is `storageLayout()`; not all values are settings | 98 lines, 31 files | No |
| `#[Alters($resource)]` (src/Resource/Attribute/Alters.php:34) | `#[Alters($owner)]` | it also takes a structure or a shape (src/Resource/Attribute/Alters.php:26-29), and "owner" is the docs' word | parameter name; no named use exists | No |
| `OperationContext::withStarting()`, `$starting` (src/Resource/OperationContext.php:118, 57) | `withDefaults()`, `$defaults` | docs/decisions.md:409: a starting value is a default | 10 lines, 8 files | No |
| `#[DerivesResources(overrides:)]` (src/Resource/Attribute/DerivesResources.php:30) | `overriddenBy:` | `overrides: FieldItemStructure::class` (modules/data_surface_entity/src/Resource/ContentEntityResources.php:45) reads as if the deriver overrides that class; it names the attribute that marks overriding classes | 2 uses | No |
| `KeyedList::add($id, $label, ...)` (src/Resource/KeyedList.php:75) | `add($key, $label, ...)` | each entry becomes a key; the blueprint's `add()` calls its first argument `$key` | parameter name | No |
| `Pipeline\Omitted`, `Omitted::value()` (src/Pipeline/Omitted.php:71) | `NotEmitted::value()` | "omitted" is also `AccessAnswer::omitted()` (src/Resource/AccessAnswer.php:141), a different thing | 16 lines, 5 files | No |
| `AccessAnswer::answer()` beside `getReason()` (src/Resource/AccessAnswer.php:282, 292) | `getAnswer()` | one getter style in one class, core's | 1 method | No |
| `BlueprintAdditionsInterface::addDefinition()` returns `static` (src/Resource/BlueprintAdditionsInterface.php:78) | returns the definition | every other declaring verb returns what it declared | return type | No |
| `x-surface.operation.readOnly` (docs/served-contract.md:304) | `x-surface.operation.changesNothing` | JSON Schema's `readOnly: true` at the same top means locked whole (docs/served-contract.md:284); keep `#[Operation(readOnly:)]` (src/Resource/Attribute/Operation.php:84), MCP's word | wire | No; wire, waits for a contract version |
| `surface`, `situation` contract keys (docs/served-contract.md:193-194) | `resource`, `operation` | the old words, already decided and deferred (docs/decisions.md:150) | wire | Yes, by the decision, until a contract version |
| `Licence`, `event.licence`, key `licence` (modules/data_surface_examples_compliance/src/Shape/Licence.php:18) | `License`, `event.license`, `license` | US English, as Drupal writes it and as `LabeledChoice` already does | 627 lines, 53 files; examples' `$defs` id | No; wire for the examples only |
| `ResourceCatalogue`, `data_surface.resource_catalogue` (data_surface.services.yml:78), `docs/catalogue.md` | `ResourceCatalog`, `data_surface.resource_catalog`, `docs/catalog.md` | US English | 197 lines, 37 files | No: the `data_surface.` prefix stays |
| "labelled" (docs/entities.md:69), "honour" (src/ResourceBuild/BlueprintAdapterBase.php:367) | "labeled", "honor" | US English | prose | No |
| "The run-time part of access" (src/Resource/Attribute/Resource.php:42, src/Resource/Attribute/Structure.php:29, src/Resource/DerivedResource.php:46) | "What depends on more than the operation's permission" | "runtime" is a kind read from cacheability (docs/decisions.md:1414); an access class may answer statically | docblocks | No |
| "raw shapes", "the definition's shape" (docs/widgets.md:169, 172); "A key whose shape a sibling chooses" (src/Slot.php:13) | "raw values", "as the definition describes it"; "A key whose keys a sibling chooses" | "shape" is the named type only (docs/decisions.md:69) | prose | No |
| "site" as a point of use (docs/served-contract.md:302, 511) | "where it is used" ("A port is stated where it is used") | "site" is the Drupal site in keys from the site | prose, one anchor | No |
| "Contributor" role (docs/refinement.md:32) | "Alter", with "Owner" | one thing, two words; no concept row | prose; `Narrowing::assertNarrows($contributor)` (src/Refinement/Narrowing.php:101) | No |
| Glossary of 23 (README.md:131) | add Variant, Owner, Child, Mount | words in names and on every page, not glossed | four lines | No |
| "Keys that come from the site" (docs/resources.md:1630), "One key per thing the site has" (docs/recipes.md:235) | "Keys from the site", the glossary's | one heading per glossary word | headings, anchors | No |
| Slot row linking `#children` (docs/resources.md:30) | a "Slots and variants" heading | every other compose row has its own | docs | No |
| "Adoption catalogue" (docs/adoption.md:1) | "Where core can adopt it" | `catalogue.md` is the other catalogue | title | No |
| nav group "Forms" holding the served contract, evolution and the audit (mkdocs.yml:45-51) | "Forms" and "Contracts" | the group's name says what is in it | nav | No |
| `Refinement\Condition` (src/Refinement/Condition.php:47) | keep; the namespaces table shows an alias beside core's `#[Condition]` | a condition plugin that is its own structure imports both | docs | No |

**Checked and kept.** `narrow()` beside `#[RefinesInput]`: a declared rule is a refiner by decision (docs/decisions.md:770), `narrow` names the guarantee the README leads with (README.md:28), and it also serves an alter of a shape with no condition; two spellings of one concept, each fitting its place. `#[RefinesInput]` in the singular: it refines one input key, and echoes the Tool API's `input_definition_refiners` (web/modules/contrib/tool/src/Attribute/Tool.php:73). The `attach*` and `add*` families. `reads()` and `reads:`, the same direction seen from the structure. `Condition`'s constructors, each a predicate read after its key (`equals`, `in`, `atLeast`, `absent`, src/Refinement/Condition.php:103-212) with JSON Schema's combinators. `AccessAnswer::open()`, `locked()`, `omitted()` against core's three, since the three answers differ by decision (docs/decisions.md:1384). `DoesNotApply::here()`, which reads as the answer it is. The operation facts on the attribute, MCP's hints without `Hint`. And the tool listing's three "operations" (the Tool API's `Write`, the module's `configure`, the kind `update`, all in `drush tool:info data_surface:registration.step3:configure`): the Tool API owns the first, and "kind" is the right word for the third.

## 6. Verdict

I would adopt it: the names are mostly disciplined, the decisions record why each was chosen, and the product-name rule is the right line between what may move and what may not. My caveat is that the authoring surface still teaches four words its own engine and glossary do not use (`key:` on a variant, `with()`, `Has*`, `SettingsLayout`) and stores six pipeline classes where an author looks for storage. Landing changes 1 to 4 before an adopter outside this repository writes a variant or a mixin would make my answer an unqualified yes.
