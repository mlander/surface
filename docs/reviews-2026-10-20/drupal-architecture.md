# Review: Drupal architecture

## Standpoint

I review as a long-time Drupal core and contrib architect, reading for typed data and config schema, the plugin system, entity and field API, access and cacheability, Form API and config deployment. I hold the module to one test: where core already has a shape for something, the module uses that shape, or says why it cannot in terms a core maintainer would accept.

## The five changes that would most improve the authoring experience

### 1. Seed the blueprint from config schema: "derived, then layered" for config too

**Problem.** A key stored in config is declared twice and defaulted three times. `NewsletterResource` declares four keys with types and rules (`modules/data_surface_first/src/Resource/NewsletterResource.php:33-48`); its schema declares the same four keys with the same types (`modules/data_surface_first/config/schema/data_surface_first.schema.yml:5-16`); its defaults are both `default:` arguments (`NewsletterResource.php:38`, `:46`) and the shipped object (`config/install/data_surface_first.newsletter.yml:3-4`). The storage page admits it: a constraint written in both "says the same thing twice and keeps them in step by hand" (`docs/storage.md:101-102`). For core's own config it is worse. `NodeTypeResource` restates `node.type.*` (`modules/data_surface_demo_node_type/src/Resource/NodeTypeResource.php:86-96`), which core already marks `FullyValidatable`, types as `machine_name` with its Regex, and bounds at 32 characters and a `Choice` of 0, 1 and 2 (`web/core/modules/node/config/schema/node.schema.yml:13-29`, `:54`; `web/core/config/schema/core.data_types.schema.yml:153-162`). The restatement is looser in places (the description has no `NotBlank`), so the contract under-promises and `prepare()` refuses what it advertised. Where the module does read schema, `FieldSettingsSchemaVariants` builds the definition through typed config and then rebuilds a bare `DataDefinition`, dropping the constraints typed config had attached (`modules/data_surface_tool/src/FieldSettingsSchemaVariants.php:135`, `:150`).

The module already owns the rule that fixes this. Content entities are "derived, then layered" (`docs/decisions.md:2519`), and a key declared with a shape may only narrow it, checked at build. `ROADMAP.md:170-173` proposes the opposite direction, generating schema from PHP. Schema-first is the right direction in Drupal: both adopters already had schemas (`docs/adopters-2026-10-19.md:12-14`), core is moving its rules into schema, and the schema is what recipes, config actions and the schema checker read when this module's pipeline is not in the loop.

**Change.** When a resource's storage is described by config schema (`ConfigObjectStorage` now; config entities and plugin configuration next), the build seeds the blueprint from the schema mapping through `TypedConfigManager::buildDataDefinition()`: types, constraints, required from `nullable` (`web/core/lib/Drupal/Core/Config/TypedConfigManager.php:181`), and the default from the shipped object. `add()` on a seeded key must name a compatible type and may only narrow, held by the check shapes already get. `add()` of a key the schema does not list is refused at build, not at prepare as today (`docs/first-resource.md:84-85`). This amends [Adding a key twice](../decisions.md#adding-a-key-twice) (`docs/decisions.md:1001-1006`): a seeded key is the storage's, and the owner's `add()` is its first declaration.

Before (`NewsletterResource.php:33-35`):

```php
$inputs->add('sender_name', 'string', t('Sender name'))
  ->setRequired(TRUE)
  ->addConstraint('Length', ['max' => 64]);
```

After, the rule lives once, in the schema core validates against:

```yaml
sender_name:
  type: string
  label: 'Sender name'
  constraints:
    Length: { max: 64 }
```

```php
// Type checked against the schema; Length and required come from it.
$inputs->add('sender_name', 'string', t('Sender name'));
```

**Cost.** A behavior change and one rule. No new concept: the schema was already one of the first resource's seven files. **Breaks.** The page-pinned `data_surface_first` test updates. Contracts gain schema constraints the runtime already enforced at prepare, so baselines re-pin and "never stricter than the runtime" holds. A resource whose PHP type disagrees with its schema is refused at build.

### 2. Generate routes from operations

**Problem.** "Operations are what routes and tools are generated from" (`README.md:211-213`). Tools are; routes are written by hand. Every route has the same four module lines (`modules/data_surface_first/data_surface_first.routing.yml:1-9`), and `_data_surface_operation_access: 'TRUE'` carries no information. Derived resources show the gap: every bundle gets tools, but only `node.page` gets a route, because someone typed it (`modules/data_surface_entity/data_surface_entity.routing.yml:19-28`). Core has both shapes this needs. Symfony's `#[Route]` on methods is discovered as of 11.4 (`web/core/lib/Drupal/Core/Routing/AttributeRouteDiscovery.php`). Entity types generate routes from link templates through route providers.

**Change.** Read Symfony's `#[Route]` beside `#[Operation]` through a `StaticRouteDiscoveryBase` subclass over the registry. It fills in `_form`, the two defaults, `_title` from the operation label, and parameter upcasting from the method's typed parameters, which `OperationArguments` already reads. `OperationAccessCheck` implements `AccessCheckInterface::applies()` for any route with `_data_surface_resource` (`web/core/lib/Drupal/Core/Access/AccessCheckInterface.php:22`), so the requirement becomes implicit. A deriver gives one path template per operation, the way link templates work.

Before: the nine YAML lines above. After:

```php
#[Operation('configure', label: new TranslatableMarkup('Configure the newsletter'), permission: 'administer data_surface_first')]
#[Route('/admin/config/services/surface-newsletter', name: 'data_surface_first.newsletter')]
public static function configure(): OperationContext {
```

**Cost.** One optional attribute, already core's; no concept. **Breaks.** Nothing. YAML routes keep working, and route names and keys keep the word surface.

*Answered in part, 2026-10-29:* one routing entry now serves every bundle's add page: a variable of the route's path inside the resource id, `_data_surface_resource: 'entity.content:node.{node_type}'`, filled from its raw value and bounded by a requirement or the `entity:node_type` converter, with `DataSurfaceOperationForm::title` as the title callback ([One route for every bundle](../forms.md#one-route-for-every-bundle)). Routes declared on the class, `#[Route]` beside `#[Operation]` or `pages:` a deriver declares on `DerivedResource`, are deferred until the YAML grammar has been used: they are a second spelling to learn and a second place to look, they need a route provider rebuilt whenever the list of bundles changes, and they would collide with hand-written routes at paths no file shows. The edit route for every bundle waits on them, or on a deriver method answering which derived resource an entity belongs to, since a node's bundle is known only once it is loaded; until then the bundle goes in the edit route's path ([decision](../decisions.md#a-route-may-fill-or-fix-what-an-operation-asks-for)).

### 3. A config entity is a derived resource, and storage has one interface

**Problem.** Config entities are the most core-shaped resources there are: the `id` key is the identity, `config_export` plus schema gives the keys, the access handler gives access, the `add` and `edit` form handlers give the operations, and link templates give the routes. The module derives none of this. Its `ConfigEntityStorage` implements the pipeline's interface (`src/Storage/ConfigEntityStorage.php:56`), and `#[Resource(storage:)]` takes the other one (`src/Resource/Attribute/Resource.php:37`). So no resource uses it ("no shipped resource yet", `docs/storage.md:415`). The content type demo instead writes a 305-line storage, restates the schema (change 1), and re-derives entity access by hand (`modules/data_surface_demo_node_type/src/Access/NodeTypeAccess.php:43`, `:53`). Two interfaces with the same three verbs (`docs/storage.md:254`) is exactly what a contrib author will pick wrong.

**Change.** Add an `entity.config` deriver beside `entity.content`. Make `ConfigEntityStorage` a `ResourceStorageInterface` named on a subclass by constant, as `ConfigObjectStorage` is, and keep `DataSurfaceStorageInterface` internal.

Before (`NodeTypeResource.php:42-46`, plus a storage and an access class):

```php
#[Resource('node.type',
  identity: ['type'],
  storage: NodeTypeStorage::class,
  access: NodeTypeAccess::class,
)]
```

After: `entity.config:node_type` is derived. The demo keeps only what core's `NodeTypeForm` adds beyond the entity, the base field override keys, as `#[Alters('entity.config:node_type')]` with a storage layout for its mount.

**Cost.** One deriver. No new concept: derived resources exist. **Breaks.** The tool id `data_surface:node.type:add` becomes `data_surface:entity.config:node_type:add`, a wire change for the demo and its Tool Belt comparison module.

### 4. On the entity side, keep core's entity form as the form door

**Problem.** `/surface-entity` redraws a node form with the module's widgets. It reads the form display only for which fields to include and in what order (`docs/entities.md:39`). Before the entity side resumes, this is what it will run into:

- Text fields lose CKEditor, because a format is a select beside a textarea (`docs/entities.md:64`) and nothing in `src/` builds a `text_format` element.
- A datetime is typed by hand in UTC (`docs/entities.md:71`) where core's widget converts from the user's timezone.
- Multi-value fields, files and images are left out (`docs/entities.md:85-89`).
- Every contrib form alter keyed to the node form misses, because the form id is `data_surface_operation_form_<route>` (`src/Form/DataSurfaceOperationForm.php:165`). That is why Scheduler, path, menu and moderation are absent (`docs/entities.md:217-218`, `:302-306`).
- Widget types and their settings in the form display are ignored.

Core already generates this form from definitions. Its widgets, `massageFormValues()` and `errorElement()` are the decades of edge cases a parallel widget set would have to relearn.

**Change.** Resume from the module's own Group G design (`docs/adoption.md:230-250`). The entity form stays the form door, and the derived resource serves the tool, the React app and the pipeline. A data surface widget appears only for field types whose item has refiners, inside `WidgetBase`'s delta loop.

Before: `data_surface_entity.routing.yml:19-28` serving the operation form. After: no route. `/node/add/page` is core's, and `data_surface:entity.content:node.page:add` is unchanged.

**Cost.** A behavior change to `data_surface_entity`'s form door, and no author concept. **Breaks.** The `/surface-entity` add and edit pages. Contracts and tools are unchanged.

### 5. Discover through a plugin manager

**Problem.** `ResourceCollectorPass` scans `Resource`, `Structure`, `Shape` and `Alter` in every module (`src/ResourceBuild/ResourceCollectorPass.php:74`). It calls `class_exists()` on every PHP file there (`:211`) on every container build, with three things missing that core has:

- **No file cache.** `HookCollectorPass` has one (`web/core/lib/Drupal/Core/Hook/HookCollectorPass.php:470-489`).
- **No text prefilter.** `AttributeRouteDiscovery` skips files that do not mention its attribute (`AttributeRouteDiscovery.php:52`).
- **No missing-dependency guard.** `AttributeClassDiscovery` catches the error (`web/core/lib/Drupal/Component/Plugin/Discovery/AttributeClassDiscovery.php:162-173`).

On this site, `canvas/src/Resource` and `mcp_server/src/Resource` are loaded this way. A class in either that extended an optional module's class would stop the container from compiling. Plugin features are also reinvented:

- `#[DerivesResources]` (`src/Resource/Attribute/DerivesResources.php`) is a deriver.
- The derived id shape `<base>:<derivative>` (`docs/decisions.md:2503-2507`) is already `PluginBase::DERIVATIVE_SEPARATOR` (`web/core/lib/Drupal/Component/Plugin/PluginBase.php:13`).
- There is no `hook_data_surface_resource_info_alter` (`data_surface.api.php` offers one for widgets and one for resolvers only). So a site cannot swap a resource's storage class the way it swaps any plugin's class.

**Change.** Make `#[Resource]`, `#[Structure]` and `#[Shape]` extend `Drupal\Component\Plugin\Attribute\Plugin`. Read them with a `DefaultPluginManager` that only reads definitions and never calls `createInstance()`. Field types are read the same way, statically (`web/core/lib/Drupal/Core/Field/FieldItemInterface.php:35`, `:91`). Derivers implement `DeriverInterface`, and the compiler pass keeps only alter and storage autowiring.

Before: `#[DerivesResources('entity.content', overrides: FieldItemStructure::class)]` on a class implementing `ResourceDeriverInterface`. After: `deriver: ContentEntityResources::class` on the attribute, with `getDerivativeDefinitions()`.

**Cost.** An internal rewrite, plus a rename for deriver authors. **Breaks.** No contract or id, since ids already use core's separator.

## Borrow

**Already borrowed well.** Much of the module already reads like core:

- OOP `#[Hook]` classes ordered with `Order::Last` (`src/Hook/ResourcePluginHooks.php:50`).
- Widgets and resolvers as attribute plugin types with info alters (`data_surface.api.php:29`, `:52`).
- `LabeledChoice` as a `Choice` subclass keeping Symfony's validator.
- Static and runtime access read from cache contexts rather than declared.
- `t()` in static methods, argued against the coding standards rather than ignored (`docs/decisions.md:276`).

**Take next:**

- **`#[JsonSchema]` on data types.** Core 11 annotates each data type's casted value, for example `#[JsonSchema(['type' => 'string', 'format' => 'email'])]` (`web/core/lib/Drupal/Core/TypedData/Plugin/DataType/Email.php:25`). The emitter hard-codes a seven-type map instead (`src/Contract/ContractEmitter.php:2734-2745`), so `duration_iso8601` and every contrib data type fall to an empty schema. Reading the attribute is static, transfers to any definition, and can only add what the runtime casts to, so it is never stricter.
- **Post-update hooks as the primary migration path.** The stored-value migration design converts on read (`docs/evolution.md:151`) and offers an eager pass second (`:182`). Drupal deployments run `updb` and then `config:import`, and module code reads `\Drupal::config()` raw. Make the eager pass the default: a `hook_post_update_NAME()` the contract diff asks for when a version rises, with on-read conversion only as the safety net.
- **Field access's two questions.** `FieldItemAccess` already maps core's `fieldAccess('edit')` and `fieldAccess('view')` to open, locked and omitted (`docs/entities.md:119-127`). Let an author's access class answer the same two core operations, `update` and `view`, with plain `AccessResult`, and derive the third answer. `AccessAnswer` then becomes internal, and a contrib author never learns a third result type.
- **`hook_config_schema_info_alter()`** as the way an alter of a shape tightens it everywhere, once shapes are schema types (Cut, below).

Each borrow keeps declarations static, only narrows, and adds no concept to a settings page.

## Cut

Held to `docs/concepts.md`:

- **`DataSurfaceStorageInterface` from the author's pages** (`docs/concepts.md:94`). It becomes internal under change 3, and authors see one storage interface.
- **The cosmetic layer** (`docs/concepts.md:45`). Fold it into `hook_form_FORM_ID_alter()`, which already works because the form id is stable per route (`docs/forms.md:788`). The layer's promise that nothing in it changes what a value means is kept by extraction and the pipeline, not by the interface: `alterSurfaceForm()` is handed the whole form, as a form alter is.
- **`#[Shape]` as a PHP class kind** (`docs/concepts.md:23`). A config schema type already names a scalar with constraints, for example `machine_name` (`core.data_types.schema.yml:153-162`). Let `add()` take a schema type name in the type position. The concept survives, written as `$defs.<type>`, while `src/Shape`, `ShapeInterface` and one discovered kind go.
- **`version:` until migrations exist.** Any value above 1 is refused (`src/ResourceBuild/ResourceRegistry.php:431-433`), so an argument whose only legal value is its default is noise in the attribute. The emitter can still stamp 1.
- **The operation id written twice.** `#[Operation('configure')]` and `new OperationContext('configure')` must agree or the operation is refused (`docs/decisions.md:386-392`). The build knows the id, so let it stamp it and make the constructor's id optional.

## Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| `Storage\ConfigEntityStorage` (`src/Storage/ConfigEntityStorage.php:56`) | `ConfigEntityResourceStorage` | Same short name as core's `Drupal\Core\Config\Entity\ConfigEntityStorage`, so an IDE import picks the wrong one. A concept name; does not cross the product rule. |
| `ContentEntityStorage` (`modules/data_surface_entity/src/Storage/ContentEntityStorage.php:52`) | `ContentEntityResourceStorage` | Reads as one of core's storage handlers (`ContentEntityStorageBase`, `ContentEntityStorageInterface`). |
| Slot, "a slot and its variants" | union | SDC components declare `slots:` (`web/core/themes/olivero/components/teaser/teaser.component.yml:22`), and Canvas builds on them. A Drupal reader takes "slot" to mean a place for markup. Smithy, whose words the module took, calls one-of-several a union. `attachBy()` can stay. |
| `src/Resource`, `src/Structure`, `src/Shape`, `src/Alter` | `src/DataSurface/{Resource,Structure,Shape,Alter}` | Generic directories other modules already use for unrelated classes (canvas, mcp_server). A product-named directory follows "the product keeps its name" rather than crossing it. |
| `_data_surface_operation_access: 'TRUE'` | implied by `_data_surface_resource` | The value says nothing. Keeps the key accepted, so no wire or product name changes. |
| `Licence`, `event.licence`, the `licence` key | `License`, `event.license`, `license` | Drupal's coding standards use American English. The shape id is in `$defs` and the key is stored config, so this is a wire change and waits for a contract version, as `situation` does. |
| `ResourceCatalogue`, "Catalogue" | `ResourceCatalog`, "Catalog" | American spelling. Internal names only. |

## Verdict

For settings pages and plugin configuration I would adopt it: the pipeline, access read from cacheability, and narrowing-only refinement are better than anything core offers today, and they reuse core's types rather than replacing them. The caveat is the second declaration: until the blueprint is seeded from config schema, every adopter keeps two copies of its rules in step by hand, and for content entities I would wait until core's entity form, not a parallel one, is the form door. Change 1 alone would move my answer from "adopt with care" to "propose for core".
