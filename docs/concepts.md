# Concepts

Every idea in the module, one line each: the question that makes you
need it, and the sentence that lets you skip it. The four marked **1**
are all [Your first resource](first-resource.md) uses; a working settings
page needs nothing else. Read a row only when its question is yours. A
time of day, a multi-select, a text area and the other keys a settings
page often asks for are [Common settings recipes](recipes.md), one
snippet each. Six rows are ways to make one resource or structure of
others (an attached child, a mixin, a port, a slot, a collection, keys
from the site); [Which way to compose](resources.md#which-way-to-compose)
sets them side by side. The last column is a page that shows the concept
on a site, of the examples module (`data_surface_examples`, drawn in
React by `data_surface_react`) or the first resource's
(`data_surface_first`), and a dash where none does.

| Concept | You need it when you ask | Skip it when | Example |
| --- | --- | --- | --- |
| **1** Resource, `#[Resource]` | What thing do I want a form, a tool and a schema for? | Never: everything starts here. | `/admin/config/services/surface-newsletter`, `/surface-examples/1` |
| **1** Operation, `#[Operation]` | How is it asked for, which permission gates it, and does a call change nothing (`readOnly`) or destroy what is there (`destructive`)? ([Resources](resources.md#operations-add-edit-and-the-rest)) | Never: a route and a tool are made from one. Leave `readOnly` and `destructive` out when a call does neither; whether it is safe to repeat is derived. | `/admin/config/services/surface-newsletter`; read only, `/surface-examples/10/check` |
| **1** Storage, `ConfigObjectStorage` | Where do its values live? | A settings page: subclass `ConfigObjectStorage`, name the config object, done. | `/admin/config/services/surface-newsletter` |
| **1** `defineInputs()` and `add()` | What keys does it take, of what type, allowing what (a constraint), defaulting to what? | Never. | `/admin/config/services/surface-newsletter` |
| Shape, `#[Shape]` | Is one value, such as an event licence, asked for at several keys with the same type, pattern and words? ([Declaring a resource](declaring-a-resource.md#named-shapes)) | The value is asked for at one key: write its constraints there. | `/surface-examples/8/desk` |
| Declared rule, `narrow()->when(Condition::...)` | Does one key narrow when another holds a value, by a condition a schema can state, such as no licence, no more than a hundred? ([Refinement](refinement.md#declaring-a-rule)) | Declare the rule when its condition and effect fit the vocabulary; write a refiner when the effect must be computed (the stewards for a capacity, `ceil(capacity / 50)`) or the condition is runtime. | `/surface-examples/7` |
| Refiner, `#[RefinesInput]` | Do one key's allowed values depend on another key's value? ([Your second resource](second-resource.md)) | Every key stands alone. | `/admin/config/services/surface-store`, `/surface-examples/2` |
| A key that does not apply, `DoesNotApply::here()` | Does a key mean nothing for some values of another, as a send day for a monthly bulletin? ([Refinement](refinement.md#when-a-key-does-not-apply)) | Every key applies, whatever the others hold. | `/surface-examples/7` |
| Runtime refiner, `runtime: TRUE` | Does a rule read site state or do a lookup no schema can state, such as uniqueness? | The rule depends only on the values it watches. | `/surface-examples/4` |
| Identity and known keys | Is there more than one of it, each with a machine name or id? | It is a singleton, as a settings page is. | - |
| Operation context options (`creates`, `known`, `withConstraint()`, `withStarting()`) | Does an operation create, know part of the identity, or narrow by where it is asked? | It is one `new OperationContext('configure')`. | - |
| Access class, `access:` | Does who may change or see some keys depend on the account's permissions or roles (static: stated in the full schema and the tool listing for that account), or on anything else, such as the thing itself (runtime: marked there, settled once it is known)? ([Resources](resources.md#static-and-runtime-access)) | The operation's permission is the whole answer; narrowing a key's values by account is a runtime refiner. | `/surface-examples/10` |
| Structure, `#[Structure]` | Is there a group of keys something else holds, or several resources share? | The keys are all one resource's own. | `/surface-examples/3` |
| Attached child, `attach()` | Should a group of keys sit under one key, as a fieldset? | Every key is a top level key. | `/surface-examples/3`, `/surface-examples/8` |
| Mixin, `with()` | Are some keys shared members that are part of this thing, such as timestamps or an owner, which other resources have too? ([Resources](resources.md#mixins)) | They are a thing of their own (attach them) or this resource's alone. | `/surface-examples/8` |
| Port, `reads()` and `attach(..., reads:)` | Does a structure need a value its container holds, such as the capacity it counts stewards for? ([Resources](resources.md#ports)) | The structure's keys depend only on each other. | `/surface-examples/8`, `/surface-examples/8/desk` |
| Slot and variant, `attachBy()`, `#[Variant]` | Does one key's value choose which further keys apply? | No answer changes which keys there are. | `/surface-examples/3` |
| Collection, `attachList()` | Does it hold a list of repeated groups of keys? | Nothing repeats. | `/surface-examples/8/desk` |
| Alter, `#[Alters]` | Am I adding keys or rules to a resource another module owns, or attaching a structure of mine to it? | You own it: change the class. | `/surface-examples/4` |
| Mounted keys and their config schema | Does my alter's module store keys on someone else's config object, or may another module's alter store keys on mine? Both sides declare config schema: the owner of a simple config object declares `third_party_settings` up front, a sequence typed by the module key, before any alter exists, and the mounting module declares its part ([Storage](storage.md#keys-another-module-mounts), [a whole alter](resources.md#a-whole-alter)). | You write no alter, and no other module will mount keys on your config object. An owner that skips it leaves every later alter needing an edit of the owner's schema; an owner that is a config entity declares nothing, since core describes its `third_party_settings`. | `/surface-examples/4` |
| `describe()` and `extendChoices()` | Must my alter reword or place an owner's key, or offer one more value on its list? | Your alter only adds keys of its own. | `describe()`, `/surface-examples/4` |
| Secret, `DefinitionMetadata::setSecret()` | Is a value write-only, such as an API key? | Every value may be read back. | - |
| Examples, `DefinitionMetadata::setExamples()` | Should the form show what a valid value looks like? | The label says enough. | `/surface-examples/8/desk` |
| Settings layout, `SettingsLayoutInterface` | Is a value stored differently from how a person is asked for it? | It is stored as asked. | - |
| Outputs, `defineOutputs()` | Does running the host emit values a caller relies on? | Nothing is emitted: settings pages emit nothing. | - |
| Plugin host, `#[UsesStructure]` | Is it a block's, formatter's, condition's, action's or field type's settings? | No plugin holds it. | - |
| Cosmetic layer, `_data_surface_cosmetics` | Does the form need grouping, weights or a borrowed element, with nothing meaning something else? | The generated form is the form you want. | - |
| Panel, `_data_surface_panel` | Should the contract be shown beside the form? | Nobody needs to see it there. | `/surface-examples/1` |
| Options resolver | Is the list of allowed values the site's (entity types, bundles), not a literal? | It is a literal list: `LabeledChoice`. | - |
| Widget plugin | Does a key need an element no stock widget draws? Checkboxes, radios, rows for a list of free values and a time input are stock, asked for with `setSetting('widget', ...)` where the rule does not pick them ([Widgets](widgets.md#which-element-a-list-of-choices-is)). | The stock widgets draw it. | The stock widgets, `/surface-examples/11` |
| Stale values | What happens to a stored value a refiner no longer allows? ([Value semantics](semantics.md#stale-values-the-third-state)) | No refiner narrows a key that has a stored value. | `/surface-examples/2` |
| Tool and read tool | Should an agent, MCP or Drush call it? | You do not enable `data_surface_tool`; there is nothing to write either way. | `/surface-examples/5` |
| Served contract and React | Will a client with no PHP render or validate it? ([The served contract](served-contract.md)) | The form and the tool are enough. | `/surface-examples/2`; in React, `/surface-react/registration.step2/configure` |
| Pipeline, dry run | Do I submit values from my own code? ([The pipeline](pipeline.md)) | The form and the tool submit for you. | `/surface-examples/5` |
| Data surface | Do I call the build or the pipeline myself? | You write resources: the build is the module's. | - |
| Child operations, `withChild()` | Should a parent's operation hand a child a context of its own, or invoke the child's operation? | No child has storage of its own. | - |
| Keys from the site, `addEach()` and `attachEach()` | Is there one key per thing the site has, such as a checkbox per form or a select per character another module may add? ([Resources](resources.md#keys-that-come-from-the-site)) | Your class can list every key; or each thing is a resource of its own, with operations or storage, which a deriver makes. | `/surface-examples/9` |
| Derived resource, `#[DerivesResources]` | Are the keys the site's (one per bundle, one per field), not the code's? | Your class says every key. | - |
| Derived variants | Can a slot's variants be read from a description that exists, such as config schema? | Each variant is a class. | - |
| Catalogue | What resources and operations does this site have? ([Catalogue](catalogue.md)) | You know your own. | - |
| Version, `version:`, and the contract diff | Will a change I make break the values already stored, or a client that read the contract? Which changes are compatible, and what does a breaking one need? ([Evolving a resource](evolution.md)) | Nothing is stored or read yet: leave it at 1, and pin a baseline so the contract diff says when that stops being true. | - |

## Namespaces

Every class of the module a snippet in these pages names, beside the
`use` line that imports it. A snippet that quotes a whole file carries
its own `use` lines; the others leave them out and link here, from the
section they sit in. A class a snippet borrows from a submodule or an
example (`NewsletterStorage`, `DemoBlockStructure`) is in that module's
`src/`, by the same rule: the namespace is the module's, then the
directory. `NamespacesDocsTest` fails when a snippet names a class of
the module that it does not import and this table does not list.

| Class | Import |
| --- | --- |
| `AccessAnswer` | `use Drupal\data_surface\Resource\AccessAnswer;` |
| `Alters` | `use Drupal\data_surface\Resource\Attribute\Alters;` |
| `AltersInputsInterface` | `use Drupal\data_surface\Resource\AltersInputsInterface;` |
| `BaseFieldOverrideStorage` | `use Drupal\data_surface\Storage\BaseFieldOverrideStorage;` |
| `BlueprintAdditionsInterface` | `use Drupal\data_surface\Resource\BlueprintAdditionsInterface;` |
| `BlueprintInterface` | `use Drupal\data_surface\Resource\BlueprintInterface;` |
| `CompositeStorage` | `use Drupal\data_surface\Storage\CompositeStorage;` |
| `Condition` | `use Drupal\data_surface\Refinement\Condition;` |
| `ConfigEntityStorage` | `use Drupal\data_surface\Storage\ConfigEntityStorage;` |
| `ConfigObjectStorage` | `use Drupal\data_surface\Storage\ConfigObjectStorage;` |
| `DataSurfaceAccess` | `use Drupal\data_surface\DataSurfaceAccess;` |
| `DataSurfaceBlockBase` | `use Drupal\data_surface\Plugin\Block\DataSurfaceBlockBase;` |
| `DataSurfaceConditionBase` | `use Drupal\data_surface\Plugin\Condition\DataSurfaceConditionBase;` |
| `DataSurfaceFieldTypeTrait` | `use Drupal\data_surface\Form\DataSurfaceFieldTypeTrait;` |
| `DataSurfaceFormatterBase` | `use Drupal\data_surface\Plugin\Field\FieldFormatter\DataSurfaceFormatterBase;` |
| `DataSurfaceInterface` | `use Drupal\data_surface\DataSurfaceInterface;` |
| `DataSurfaceOptionsResolver` | `use Drupal\data_surface\Attribute\DataSurfaceOptionsResolver;` |
| `DataSurfaceOptionsResolverBase` | `use Drupal\data_surface\Options\DataSurfaceOptionsResolverBase;` |
| `DataSurfaceResult` | `use Drupal\data_surface\Pipeline\DataSurfaceResult;` |
| `DataSurfaceStorageInterface` | `use Drupal\data_surface\Pipeline\DataSurfaceStorageInterface;` |
| `DataSurfaceWidget` | `use Drupal\data_surface\Attribute\DataSurfaceWidget;` |
| `DataSurfaceWidgetBase` | `use Drupal\data_surface\Widget\DataSurfaceWidgetBase;` |
| `DefinitionMetadata` | `use Drupal\data_surface\DefinitionMetadata;` |
| `DerivedOperation` | `use Drupal\data_surface\Resource\DerivedOperation;` |
| `DerivedResource` | `use Drupal\data_surface\Resource\DerivedResource;` |
| `DerivesResources` | `use Drupal\data_surface\Resource\Attribute\DerivesResources;` |
| `DoesNotApply` | `use Drupal\data_surface\Refinement\DoesNotApply;` |
| `FieldSettingsStorage` | `use Drupal\data_surface\Storage\FieldSettingsStorage;` |
| `FieldSurfaceProviderInterface` | `use Drupal\data_surface\Form\FieldSurfaceProviderInterface;` |
| `HasOutputsInterface` | `use Drupal\data_surface\Resource\HasOutputsInterface;` |
| `HasStorageLayoutInterface` | `use Drupal\data_surface\Resource\HasStorageLayoutInterface;` |
| `KeyedList` | `use Drupal\data_surface\Resource\KeyedList;` |
| `KeysFromSiteInterface` | `use Drupal\data_surface\Resource\KeysFromSiteInterface;` |
| `Omitted` | `use Drupal\data_surface\Pipeline\Omitted;` |
| `Operation` | `use Drupal\data_surface\Resource\Attribute\Operation;` |
| `OperationAccessInterface` | `use Drupal\data_surface\Resource\OperationAccessInterface;` |
| `OperationContext` | `use Drupal\data_surface\Resource\OperationContext;` |
| `OptionSet` | `use Drupal\data_surface\Options\OptionSet;` |
| `PluginConfigurationStorage` | `use Drupal\data_surface\Storage\PluginConfigurationStorage;` |
| `PreparedValues` | `use Drupal\data_surface\Pipeline\PreparedValues;` |
| `RefinesInput` | `use Drupal\data_surface\Resource\Attribute\RefinesInput;` |
| `Resource` | `use Drupal\data_surface\Resource\Attribute\Resource;` |
| `ResourceCallable` | `use Drupal\data_surface\Resource\ResourceCallable;` |
| `ResourceDeriverInterface` | `use Drupal\data_surface\Resource\ResourceDeriverInterface;` |
| `ResourceInterface` | `use Drupal\data_surface\Resource\ResourceInterface;` |
| `ResourceStorageInterface` | `use Drupal\data_surface\Resource\ResourceStorageInterface;` |
| `SchemaViolations` | `use Drupal\data_surface\Storage\SchemaViolations;` |
| `SettingsLayoutInterface` | `use Drupal\data_surface\Storage\SettingsLayoutInterface;` |
| `Shape` | `use Drupal\data_surface\Resource\Attribute\Shape;` |
| `ShapeInterface` | `use Drupal\data_surface\Resource\ShapeInterface;` |
| `StateStorage` | `use Drupal\data_surface\Storage\StateStorage;` |
| `StoredValueMigrationInterface` | `use Drupal\data_surface\Resource\StoredValueMigrationInterface;` |
| `Structure` | `use Drupal\data_surface\Resource\Attribute\Structure;` |
| `StructureInterface` | `use Drupal\data_surface\Resource\StructureInterface;` |
| `UsesStructure` | `use Drupal\data_surface\Resource\Attribute\UsesStructure;` |
| `Variant` | `use Drupal\data_surface\Resource\Attribute\Variant;` |
| `Violation` | `use Drupal\data_surface\Pipeline\Violation;` |
| `ViolationSet` | `use Drupal\data_surface\Pipeline\ViolationSet;` |
