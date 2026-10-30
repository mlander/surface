# Concepts

Use this page to look up an idea by the question that makes you need
it, once you know its name. Each idea is defined in plain words in
[the introduction](introduction/index.md), and the Introduced in column
links the page that defines it.

Every idea in the module, one line each: the question that makes you
need it, and the sentence that lets you skip it. The four marked **1**
are all [Your first resource](first-resource.md) uses; a working settings
page needs nothing else. Read a row only when its question is yours. A
time of day, a multi-select, a text area and the other keys a settings
page often asks for are [Common settings recipes](recipes.md), one
snippet each. Six rows are ways to make one resource or structure of
others (an attached child, a mixin, a port, variants, a collection, keys
and variants from the site);
[Which way to compose](resources.md#which-way-to-compose) sets them side
by side. Where a value asked for one way is stored another, what runs
because something was saved, and where a value nobody types comes from,
is no concept of its own: [Transformations](transformations.md) puts
coercion, layouts, the commit, ECA, outputs and `prepare()` side by
side. The Example column is the example that shows the concept, in
the order the examples index, `/surface-examples`, reads them, and the
last column a page that shows it on a site, of the examples module
(`surface_examples`, drawn in React by `surface_react`) or the
first resource's (`surface_examples_first`); a dash where none does.

| Concept | Introduced in | You need it when you ask | Skip it when | Example | Page |
| --- | --- | --- | --- | --- | --- |
| **1** Resource, `#[Resource]` | [Resources](introduction/02-resources.md) | What thing do I want a form, a tool and a schema for? | Never: everything starts here. | 1 | `/admin/config/services/surface-newsletter`, `/surface-examples/1` |
| **1** Operation, `#[Operation]` | [Operations](introduction/09-operations.md) | How is it asked for, what does a call do (`description`), which permission gates it, whose access it borrows for the thing a call is about, per item of a list too (`gate:`, [Resources](resources.md#a-gate-borrowed-from-another-thing)), and does a call create (`creates`), change nothing (`readOnly`), destroy what is there (`destructive`) or remove the thing, taking no values (`deletes`)? ([Resources](resources.md#operations-create-update-and-the-rest)) | Never: a route and a tool are made from one. On the class it needs no method; leave `creates`, `readOnly`, `destructive` and `deletes` out when a call does none of them; whether it is safe to repeat is derived. | 1, 10, 15, 17 | `/admin/config/services/surface-newsletter`; read only, `/surface-examples/10/check`; a listing and a reorder, `/surface-examples/15`; gates, `/surface-examples/17` (with `surface_examples_retitle`) |
| **1** Storage, `ConfigObjectStorage` | [Storage](introduction/10-storage.md) | Where do its values live? | A settings page: subclass `ConfigObjectStorage`, name the config object, and write its config schema and install file from the resource with `drush surface:config-schema --write` ([Storage](storage.md#a-schema-generated-from-the-resource)), done. A config entity: subclass `ConfigEntityStorage`, name the entity type and the property each key is stored under ([Storage](storage.md#a-config-entity)); for an entity type of your own, the same command writes its schema too. | 1, 15 | `/admin/config/services/surface-newsletter`; a config entity, `/surface-examples/15`, and `/admin/structure/block/surface/add` (with `surface_examples_block`) |
| **1** `defineInputs()` and `add()` | [Keys](introduction/05-keys.md), [Rules on a key](introduction/06-rules-on-a-key.md) | What keys does it take, of what type, allowing what (a constraint), defaulting to what? A site sets its own default over the declared one in `surface.defaults` ([Declaring a resource](declaring-a-resource.md#a-sites-own-defaults)). | Never. | 1 | `/admin/config/services/surface-newsletter` |
| Shape, `#[Shape]` | [Shapes](introduction/04-shapes.md) | Is one value, such as an event license, asked for at several keys with the same type, pattern and words? ([Declaring a resource](declaring-a-resource.md#named-shapes)) | The value is asked for at one key: write its constraints there. | 4, 8 | `/surface-examples/8/desk` |
| Declared rule, `narrow()->when(Condition::...)` | [Rules between keys](introduction/07-rules-between-keys.md) | Does one key narrow when another holds a value, by a condition a schema can state, such as no license, no more than a hundred? ([Refinement](refinement.md#declaring-a-rule)) | Declare the rule when its condition and effect fit the vocabulary; write a refiner when the effect must be computed (the stewards for a capacity, `ceil(capacity / 50)`) or the condition is runtime. | 4, 7, 14 | `/surface-examples/7` |
| Refiner, `#[RefinesInput]` | [Rules between keys](introduction/07-rules-between-keys.md) | Do one key's allowed values depend on another key's value? ([Your second resource](second-resource.md)) | Every key stands alone. | 2 | `/admin/config/services/surface-store`, `/surface-examples/2` |
| A key that does not apply, `narrow($key)->when(...)->doesNotApply()` | [Rules between keys](introduction/07-rules-between-keys.md) | Does a key mean nothing for some values of another, as a send day for a monthly bulletin? A declared rule says so; a refiner whose effect must be computed answers `DoesNotApply::here()` ([Refinement](refinement.md#when-a-key-does-not-apply)). | Every key applies, whatever the others hold. | 7, 14 | `/surface-examples/7`, `/surface-examples/14/webhook` |
| Runtime refiner, `runtime: TRUE` | [Rules between keys](introduction/07-rules-between-keys.md) | Does a rule read site state or do a lookup no schema can state, such as uniqueness? | The rule depends only on the values it watches. | 4 | `/surface-examples/4` |
| Identity and known keys | [Which one: identity](introduction/08-identity.md) | Is there more than one of it, each with a machine name or id? | It is a singleton, as a settings page is. | 15 | `/surface-examples/15` |
| Operation context options (`known`, `withConstraint()`, `withDefaults()`) and child operations (`withChild()`), on a method | [Operations](introduction/09-operations.md) | Does an operation know part of the identity, narrow by where it is asked, or start a new thing from other values? Should it hand a child a context of its own, or invoke the child's operation? A child with operations of its own is invoked through the one its parent names, else the one with its parent's id, else the one of its parent's kind ([Child operations](resources.md#child-operations)). | It needs nothing more than its attribute, so it stands on the class with no method; no child has storage of its own. | - | - |
| An operation's own keys, `#[Operation(structure:)]` | - | Is an operation about the things together, a listing's page or a reorder's list, so it takes other keys than the thing's? ([Resources](resources.md#an-operation-that-takes-keys-of-its-own)) | It takes the resource's own keys, as a create or an update does. | 15 | `/surface-examples/15` |
| Access class, `access:` | [Access](introduction/15-access.md) | Does who may change or see some keys depend on the account's permissions or roles (static: stated in the full schema and the tool listing for that account), or on anything else, such as the thing itself (runtime: marked there, settled once it is known)? Another module answers for a thing it does not own with an alter implementing the same `OperationAccessInterface`, after this answer, the stricter standing. ([Resources](resources.md#static-and-runtime-access)) | The operation's permission is the whole answer; narrowing a key's values by account is a runtime refiner. | 10 | `/surface-examples/10` |
| Structure, `#[Structure]` | [Structures](introduction/03-structures.md) | Is there a group of keys something else holds, or several resources share? | The keys are all one resource's own. | 3 | `/surface-examples/3` |
| Attached child, `attach()` | [Resources made of structures](introduction/11-resources-made-of-structures.md) | Should a group of keys sit under one key, as a fieldset? | Every key is a top level key. | 3, 8 | `/surface-examples/3`, `/surface-examples/8` |
| Mixin, `mixIn()` | [Resources made of structures](introduction/11-resources-made-of-structures.md) | Are some keys shared members that are part of this thing, such as timestamps or an owner, which other resources have too? ([Resources](resources.md#mixins)) | They are a thing of their own (attach them) or this resource's alone. | 8 | `/surface-examples/8` |
| Port, `reads()` and `attach(..., reads:)` | [Resources made of structures](introduction/11-resources-made-of-structures.md) | Does a structure need a value its container holds, such as the capacity it counts stewards for? ([Resources](resources.md#ports)) | The structure's keys depend only on each other. | 4, 8, 9 | `/surface-examples/8`, `/surface-examples/8/desk` |
| Variants, `attachVariants()`, `#[Variant]` | [Resources made of structures](introduction/11-resources-made-of-structures.md) | Does one key's value choose which further keys apply? | No answer changes which keys there are. | 3 | `/surface-examples/3` |
| Collection, `attachList()` | [Resources made of structures](introduction/11-resources-made-of-structures.md) | Does it hold a list of repeated groups of keys? | Nothing repeats. | 8, 15, 17 | `/surface-examples/8/desk`, `/surface-examples/15/add`, `/surface-examples/17` |
| Alter, `#[Alters]` | [Other modules](introduction/13-other-modules.md) | Am I adding keys or rules to a resource another module owns, attaching a structure of mine to it, or answering who may change or see it (`OperationAccessInterface`, as an access class does, asked after the owner's answer, the stricter standing)? ([Resources](resources.md#an-alters-answer)) | You own it: change the class. | 4, 10, 14 | `/surface-examples/4`; an access answer, `/surface-examples/10` with `surface_examples_workflow`; a managed key, `/surface-examples/14/webhook` with `surface_examples_keys` |
| Mounted keys and their config schema | [Other modules](introduction/13-other-modules.md) | Does my alter's module store keys on someone else's config object, or may another module's alter store keys on mine? Both sides' config schema says so: the owner of a simple config object has `third_party_settings`, a sequence typed by the module key, and the mounting module its part; generated, each is written by `drush surface:config-schema --write` with the mounting module on ([Storage](storage.md#keys-another-module-mounts), [a whole alter](resources.md#a-whole-alter)). | You write no alter, and no other module will mount keys on your config object. An owner whose schema lacks it leaves every later alter refused until the owner's schema is written again; an owner that is a config entity declares nothing, since core describes its `third_party_settings`. | 4, 14 | `/surface-examples/4` |
| `describe()` and `extendChoices()` | [Other modules](introduction/13-other-modules.md) | Must my alter reword or place an owner's key, or offer one more value on its list? | Your alter only adds keys of its own. | 4, 14 (`describe()`; no numbered example calls `extendChoices()`, `surface_examples_extras` does) | `describe()`, `/surface-examples/4` |
| Secret, `DefinitionMetadata::setSecret()` | - | Is a value write-only, such as an API key? A secret of a variant not chosen is kept as stored. | Every value may be read back. | 14 | `/surface-examples/14` |
| Resolve, `resolve($key, from:, using:)` | - | Should the code that uses a resource read another value at a key than the one stored, such as a managed key's value in place of a pasted one, while another key names it? Read with `Resources::resolved()`; never stored, and stated by no document ([Resources](resources.md#standing-in-for-a-key-at-use)). | The code reads what is stored, or the owner declares both sources itself, as variants. | 14 | `/surface-examples/14/webhook` (with `surface_examples_keys`) |
| Computed key, `compute()` | - | Does a key follow others, a value nobody types, such as a slug from a name, set once or again whenever they change? ([Declaring a resource](declaring-a-resource.md#computed-keys)) | A caller types it; or it needs a service or the clock, such as a changed time, which a storage's `prepare()` adds. | 15 | `/surface-examples/15/add` |
| Machine name, `compute($key, 'machine_name', ..., using: MachineName::class, when: Compute::UNLESS_SENT)` | - | Does a thing have a name and a machine name suggested from it, which a person may type over, held once, and fixed once the thing exists? ([Recipe](recipes.md#a-title-and-a-machine-name)) | The identity is a number storage hands out, or nobody names the thing. | 15 | `/surface-examples/15/add`; `/admin/structure/types/surface-add` (with `surface_examples_node_type`) |
| Examples, `DefinitionMetadata::setExamples()` | - | Should a caller see what a valid value looks like? Both documents state it; a form shows it only as a placeholder the key's arrangement declares. | The label says enough. | 4, 8 | `/surface-examples/8/desk` |
| Arrangement, `$inputs->describe($key, group: ...)` | - | Should keys sit in a group (a fieldset or a details), in another order, or show a placeholder, a prefix, a suffix or a help text, on every form that draws them and in React? ([Generated forms](forms.md#arranging-the-keys)) | Declaration order and the labels are the form you want. | - | `/admin/structure/types/surface-add` (with `surface_examples_node_type`) |
| Layout, `StorageLayoutInterface` | - | Is a value stored differently from how a person is asked for it? | It is stored as asked. | - | - |
| Outputs, `defineOutputs()` | - | Does running the host emit values a caller relies on, such as a listing's page? ([Outputs](outputs.md)) | Nothing is emitted: settings pages emit nothing. | 15 | `/surface-api/event.venue?limit=2` (with `surface_react`) |
| Plugin host, `#[UsesStructure]` | [Structures](introduction/03-structures.md) | Is it a block's, formatter's, condition's, action's or field type's settings? | No plugin holds it. | 13 | - |
| Cosmetic layer, `_surface_cosmetics` | - | Does one operation form need a borrowed element (vertical tabs), markup, a message or a redirect, with nothing meaning something else? Grouping and order are the arrangement's. | The generated form, arranged, is the form you want. | - | - |
| Panel, `_surface_panel` | - | Should the contract be shown beside the form? | Nobody needs to see it there. | 1 | `/surface-examples/1` |
| Options resolver | - | Is the list of allowed values the site's (entity types, bundles), not a literal? Which constraint to write is a ladder: a stock constraint the schema states, then a list the site decides, then one of your own ([Options](options.md#which-constraint-to-write)). | It is a literal list: `LabeledChoice`. | 2, 14 | `/surface-examples/2`; `/surface-examples/14/webhook` (with `surface_examples_keys`) |
| Reference key, `add($key, 'entity:<type>[:<bundle>]')` and `add($key, 'entity')` | - | Does a key hold an entity's id: a user, an article, a venue, or an entity of any type? Its candidates are core's selection handler's for the account, listed up to `surface.settings:reference_list_limit` and looked up past it with the resource's `:lookup` ([Options](options.md#a-key-that-refers-to-an-entity)). | It holds a value of its own, not an entity's id. | 16 | `/surface-examples/16` (with `surface_examples_references`) |
| Widget plugin | - | Does a key need an element no stock widget draws? Checkboxes, radios, rows for a list of free values, a time input and a date input are stock, asked for with `setSetting('widget', ...)` where the rule does not pick them ([Widgets](widgets.md#which-element-a-list-of-choices-is)). Should my module's widget draw every key of a kind on the site, every string whose `format` setting is `color`? It claims them, `claims:`, and a key whose owner asks for a widget keeps its own ([Widgets](widgets.md#a-module-serves-a-widget-to-every-key-of-a-kind)). | The stock widgets draw it. | 11 | The stock widgets, `/surface-examples/11` |
| Stale values | - | What happens to a stored value a refiner no longer allows? ([Value semantics](semantics.md#stale-values-the-third-state)) | No refiner narrows a key that has a stored value. | 2 | `/surface-examples/2` |
| Tool and read tool | [Doors](introduction/12-doors.md) | Should the command line (`drush tool:run`), ECA, FlowDrop, Maestro, an MCP server or an AI agent run it? One tool made from an operation can be run by everything that runs Tool API tools. | You do not enable `surface_tool`; there is nothing to write either way. | 5 | `/surface-examples/5` |
| Served contract and React | [Doors](introduction/12-doors.md) | Will a client with no PHP read, render or write it over HTTP, the path its resource and the method its operation? ([The served contract](served-contract.md#the-api)) | The form and the tool are enough. | 6 | `/surface-api/registration.step2`; in React, `/surface-react/registration.step2/configure` |
| OpenAPI document | [Doors](introduction/12-doors.md) | Will an OpenAPI client, generator or UI call its paths? ([An OpenAPI document](served-contract.md#an-openapi-document)) | Nobody reads OpenAPI: leave `surface_openapi` off. | 6 | `/surface-api/openapi.json`; try it in `/surface-api/docs` |
| Pipeline, dry run | [A value sent in](introduction/17-a-value-sent-in.md) | Do I submit values from my own code? ([The pipeline](pipeline.md)) | The form and the tool submit for you. | 5 | `/surface-examples/5` |
| Data surface | [Sealed](introduction/16-sealed.md) | Do I call the build or the pipeline myself? | You write resources: the build is the module's. | - | - |
| Keys and variants from the site, `addEach()`, `attachEach()`, `attachVariants(..., from:)` | [Resources made of structures](introduction/11-resources-made-of-structures.md) | Is there one key per thing the site has, such as a checkbox per form or a select per character another module may add? Or does each thing choose a group of keys of its own, which a JSON Schema or a structure class already describes, such as a component's props or a field type's settings? One provider lists them, a `SiteListInterface` whose `list()` answers a `SiteList`, each variant read from its schema ([Reading a schema](reading-a-schema.md)) ([Keys](resources.md#keys-that-come-from-the-site), [variants](resources.md#variants-from-the-site)). | Your class can list every key, and each variant can be a class: `#[Variant]`; or each thing is a resource of its own, with operations or storage, which a deriver makes. When each variant the site may offer is a structure your code names, the provider is closed, `ClosedSiteListInterface`, and storage describes them all. | 9, 12, 14 | `/surface-examples/9`, `/surface-examples/12`; closed, `/surface-examples/14` |
| Derived resource, `#[DerivesResources]` | - | Are the keys the site's (one per bundle, one per field), not the code's? | Your class says every key. | - | - |
| Catalog | - | What resources and operations does this site have? ([Catalog](catalog.md)) | You know your own. | - | - |
| Version, `version:`, and the contract diff | - | Will a change I make break the values already stored, or a client that read the contract? Which changes are compatible, and what does a breaking one need? ([Evolving a resource](evolution.md)) | Nothing is stored or read yet: leave it at 1, and pin a baseline so the contract diff says when that stops being true. | - | - |

## Namespaces

The classes in this table are the module's own, written to prove the
pattern. Not every one is a finished design: some exist only to show
an idea or to try it on a Drupal thing. Where Drupal core has a system
that does the job (typed data definitions, validation constraints,
config schema, Form API), the module uses it, and where core has one
that is close, the aim is to evolve that system rather than keep a
class here. [Core gaps](core-gaps.md) records where core falls short
today and what the module does instead, and
[Decisions](decisions.md) records each choice.

Every class of the module a snippet in these pages names, beside the
`use` line that imports it. A snippet that quotes a whole file carries
its own `use` lines; the others leave them out and link here, from the
section they sit in. A class a snippet borrows from a submodule or an
example (`NewsletterStorage`, `DemoBlockStructure`) is in that module's
`src/`, by the same rule: the namespace is the module's, then the
directory. `NamespacesDocsTest` fails when a snippet names a class of
the module that it does not import and this table does not list.

A class in `Storage\` is one you name on `#[Resource(storage:)]` or
extend (`ConfigObjectStorage`, `ConfigEntityStorage`), or one such a
class works with: a layout, the secret codec, `SchemaViolations`. The
storages the engine writes through for a plugin's host or State implement
the pipeline's `SurfaceStorageInterface` and live in
`Pipeline\Storage\` beside it; naming one on `#[Resource(storage:)]` is
refused.

| Class | Import |
| --- | --- |
| `AccessAnswer` | `use Drupal\surface\Resource\AccessAnswer;` |
| `Alters` | `use Drupal\surface\Resource\Attribute\Alters;` |
| `AltersInputsInterface` | `use Drupal\surface\Resource\AltersInputsInterface;` |
| `AltersStorageLayoutInterface` | `use Drupal\surface\Resource\AltersStorageLayoutInterface;` |
| `Arrangement` | `use Drupal\surface\Resource\Arrangement;` |
| `ArrangementGroup` | `use Drupal\surface\Resource\ArrangementGroup;` |
| `BaseFieldOverrideStorage` | `use Drupal\surface\Pipeline\Storage\BaseFieldOverrideStorage;` |
| `BlueprintAdditionsInterface` | `use Drupal\surface\Resource\BlueprintAdditionsInterface;` |
| `BlueprintInterface` | `use Drupal\surface\Resource\BlueprintInterface;` |
| `ClosedSiteListInterface` | `use Drupal\surface\Resource\ClosedSiteListInterface;` |
| `ComponentProps` | `use Drupal\surface\Variants\ComponentProps;` |
| `CompositeStorage` | `use Drupal\surface\Pipeline\Storage\CompositeStorage;` |
| `Compute` | `use Drupal\surface\Resource\Compute;` |
| `ComputesValueInterface` | `use Drupal\surface\Resource\ComputesValueInterface;` |
| `Condition` | `use Drupal\surface\Refinement\Condition;` |
| `ConfigEntityStorage` | `use Drupal\surface\Storage\ConfigEntityStorage;` |
| `ConfigObjectStorage` | `use Drupal\surface\Storage\ConfigObjectStorage;` |
| `ConfigSchemaGenerator` | `use Drupal\surface\Storage\ConfigSchemaGenerator;` |
| `DefinesOutputsInterface` | `use Drupal\surface\Resource\DefinesOutputsInterface;` |
| `DefinitionMetadata` | `use Drupal\surface\DefinitionMetadata;` |
| `DeletableStorageInterface` | `use Drupal\surface\Resource\DeletableStorageInterface;` |
| `DerivedOperation` | `use Drupal\surface\Resource\DerivedOperation;` |
| `DerivedResource` | `use Drupal\surface\Resource\DerivedResource;` |
| `DerivesResources` | `use Drupal\surface\Resource\Attribute\DerivesResources;` |
| `DoesNotApply` | `use Drupal\surface\Refinement\DoesNotApply;` |
| `FieldSettingsStorage` | `use Drupal\surface\Pipeline\Storage\FieldSettingsStorage;` |
| `FieldSurfaceProviderInterface` | `use Drupal\surface\Form\FieldSurfaceProviderInterface;` |
| `HeldToConfigSchemaInterface` | `use Drupal\surface\Resource\HeldToConfigSchemaInterface;` |
| `HeldToConfigSchemaTrait` | `use Drupal\surface\Storage\HeldToConfigSchemaTrait;` |
| `KnowsClearableKeysInterface` | `use Drupal\surface\Resource\KnowsClearableKeysInterface;` |
| `KnowsDerivativeListTagsInterface` | `use Drupal\surface\Resource\KnowsDerivativeListTagsInterface;` |
| `MachineName` | `use Drupal\surface\Compute\MachineName;` |
| `NotEmitted` | `use Drupal\surface\Pipeline\NotEmitted;` |
| `Operation` | `use Drupal\surface\Resource\Attribute\Operation;` |
| `OperationAccessInterface` | `use Drupal\surface\Resource\OperationAccessInterface;` |
| `OperationContext` | `use Drupal\surface\Resource\OperationContext;` |
| `OptionSet` | `use Drupal\surface\Options\OptionSet;` |
| `PluginConfigurationStorage` | `use Drupal\surface\Pipeline\Storage\PluginConfigurationStorage;` |
| `PreparedValues` | `use Drupal\surface\Pipeline\PreparedValues;` |
| `RefinesInput` | `use Drupal\surface\Resource\Attribute\RefinesInput;` |
| `Resource` | `use Drupal\surface\Resource\Attribute\Resource;` |
| `ResourceCallable` | `use Drupal\surface\Resource\ResourceCallable;` |
| `ResourceDeriverInterface` | `use Drupal\surface\Resource\ResourceDeriverInterface;` |
| `ResourceInterface` | `use Drupal\surface\Resource\ResourceInterface;` |
| `ResourceStorageInterface` | `use Drupal\surface\Resource\ResourceStorageInterface;` |
| `SchemaReader` | `use Drupal\surface\Contract\SchemaReader;` |
| `SchemaViolations` | `use Drupal\surface\Storage\SchemaViolations;` |
| `Shape` | `use Drupal\surface\Resource\Attribute\Shape;` |
| `ShapeInterface` | `use Drupal\surface\Resource\ShapeInterface;` |
| `SiteList` | `use Drupal\surface\Resource\SiteList;` |
| `SiteListInterface` | `use Drupal\surface\Resource\SiteListInterface;` |
| `StateStorage` | `use Drupal\surface\Pipeline\Storage\StateStorage;` |
| `StorageLayoutInterface` | `use Drupal\surface\Storage\StorageLayoutInterface;` |
| `StoredValueMigrationInterface` | `use Drupal\surface\Resource\StoredValueMigrationInterface;` |
| `Structure` | `use Drupal\surface\Resource\Attribute\Structure;` |
| `StructureInterface` | `use Drupal\surface\Resource\StructureInterface;` |
| `SurfaceAccess` | `use Drupal\surface\SurfaceAccess;` |
| `SurfaceFieldTypeTrait` | `use Drupal\surface\Form\SurfaceFieldTypeTrait;` |
| `SurfaceInterface` | `use Drupal\surface\SurfaceInterface;` |
| `SurfaceOptionsResolver` | `use Drupal\surface\Attribute\SurfaceOptionsResolver;` |
| `SurfaceOptionsResolverBase` | `use Drupal\surface\Options\SurfaceOptionsResolverBase;` |
| `SurfaceResult` | `use Drupal\surface\Pipeline\SurfaceResult;` |
| `SurfaceStorageInterface` | `use Drupal\surface\Pipeline\SurfaceStorageInterface;` |
| `SurfaceWidget` | `use Drupal\surface\Attribute\SurfaceWidget;` |
| `SurfaceWidgetBase` | `use Drupal\surface\Widget\SurfaceWidgetBase;` |
| `TypedBlockBase` | `use Drupal\surface\Plugin\Block\TypedBlockBase;` |
| `TypedConditionPluginBase` | `use Drupal\surface\Plugin\Condition\TypedConditionPluginBase;` |
| `TypedFormatterBase` | `use Drupal\surface\Plugin\Field\FieldFormatter\TypedFormatterBase;` |
| `UsesStructure` | `use Drupal\surface\Resource\Attribute\UsesStructure;` |
| `Variant` | `use Drupal\surface\Resource\Attribute\Variant;` |
| `Violation` | `use Drupal\surface\Pipeline\Violation;` |
| `ViolationSet` | `use Drupal\surface\Pipeline\ViolationSet;` |
