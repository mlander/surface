# Performance review, 2026-10-20

## 1. Standpoint

I review as a performance engineer running Drupal for a site with a hundred content types, a thousand fields, fifty roles, and agents calling tools in bursts, often from long-lived workers. I hold the module to this: per-request work stays flat as the site grows, one change to the site invalidates only what read it, and memory in a worker does not grow with the number of calls.

Measured with one-off scripts run through `ddev drush php:eval` on `next` at fad37cc (PHP 8.4, assertions on); nothing under the module was changed. This site's discovery bin is the null backend (`web/sites/default/settings.local.php:38`), so cache sizes below are what production would store, computed by serializing what the registry and deriver hold. The site is small (91 definitions, 65 tools, 22 bundles, 4 roles), so scale figures are estimates with their basis given. Paths are relative to the module.

On a small resource the engine is cheap: example 3 builds in 0.19 ms and emits its full schema in 0.85 to 1.0 ms; `entity.content:node.page` builds in 0.8 to 0.9 ms. The costs that matter at scale are how the caches are shaped, how much one change rebuilds, and what is kept alive.

## 2. The five costs that most matter at scale

### 1. Discovery is one cache item holding every derived definition, cleared by site-wide tags

**Where it is paid.** Every request that touches the registry (a data surface form, a tool call, a schema request, an access check) unserializes the whole item (`src/ResourceBuild/ResourceRegistry.php:131-140`). It is stored under the union of every derivative's tags (`ResourceRegistry.php:136-138`), and each derivative carries `entity_field_info`, `entity_bundles` and `config:entity_form_display_list` (`modules/data_surface_entity/src/FieldItemDerivation.php:342-349`). So any field saved, any form display saved for any entity type, or any bundle created anywhere throws the whole registry away, and the next request asks every deriver for every bundle again (`ResourceRegistry.php:714-783`). Before it even reads the cache, computing the cache id reads the full definitions of five plugin managers (`ResourceRegistry.php:130`, `src/ResourceBuild/ResourcePlugins.php:88-95`, the list at `data_surface.services.yml:84-91`), and one of them, `action`, reaches through `eca_tool`'s action deriver into the tool deriver and back into the registry.

**Measured.** The item is 256,522 bytes serialized for 91 definitions: 50 field item structures average 3,473 bytes, 7 bundle resources 4,901, 34 class resources 1,338. Unserialized it holds 1.24 MB, and unserializing takes 0.43 ms. The five plugin managers' definitions are 132 KB serialized. Rediscovery with field info warm takes 3 to 4 ms; without derivers, 0.2 ms.

**A bug found on the way.** `ResourcePlugins::read()` sets `$this->used = []` before it loops (`ResourcePlugins.php:92`), so a registry asked from inside that loop (via `eca_tool`) gets `own = []`, discovers without the plugins that are their own structure, and memoizes that for the rest of the request. Reading the plugin hosts first and then the registry gave 90 definitions with `DataSurfaceDemoFormatter` missing; the other order gave 91. On a production site the nested call also writes that incomplete set to the discovery bin under the class-list-only cache id.

**At scale (estimate).** A bundle shows about 20 fields (node.page shows 14, 11 of them base fields; a thousand config fields over a hundred types adds 10): about 2,000 field items plus 120 bundle resources, so about 7.5 MB serialized at the measured averages, 37 MB in memory at the measured 4.85 ratio and 13 ms to unserialize, per request. That exceeds memcached's default 1 MB item limit, so there the item is never stored and every request rediscovers.

**The fix.** Keep an index entry (ids, bases, alters, variants) tagged with the list tags, and one entry per derivative tagged only with what it read: its bundle's form display, and the config tags of the field configs, field storages and base field overrides it displays. Read derivatives on demand, the way core caches bundle field definitions per bundle. Store the plugin hosts' answer inside the index entry instead of hashing it into the cid, and make `read()` re-entrant-safe by filling a local and assigning at the end.

```php
// Now, ResourceRegistry.php:130-131
$own = $this->plugins?->ownStructures() ?? [];
$cid = self::CACHE_PREFIX . hash('xxh3', serialize($own === [] ? $this->classes : [$this->classes, $own]));

// Proposed
$cid = self::CACHE_PREFIX . 'index:' . hash('xxh3', serialize($this->classes));
// ...and getDefinition('field.item:node.page.title') loads
// self::CACHE_PREFIX . 'derived:field.item:node.page.title' on demand.
```

**What it costs and breaks.** Internal, no concept; nothing on the wire. A tag left out would leave a definition stale, so it needs a kernel test saving a field storage two bundles share.

### 2. Tool derivation builds each operation about three times, and fifteen tags throw away every tool

**Where it is paid.** `ResourceOperationToolDeriver::getDerivativeDefinitions()` (`modules/data_surface_tool/src/Plugin/Derivative/ResourceOperationToolDeriver.php:138-188`) builds the neutral surface in `inputs()`, again in `parameters()` for the read tool, again in `readOutputs()`, and once more in `outputs()` when the resource has outputs (`modules/data_surface_tool/src/OperationInputs.php:166`, `:194`, `:220`, `:437`, `:542`). Nothing is shared between them. The invalidator then clears the entire tool plugin manager, every module's tools, when any remembered tag is invalidated (`modules/data_surface_tool/src/ToolDefinitionsInvalidator.php:72-78`). The decision (`docs/decisions.md:2564`) is right that a new field must reach the `add` tool, but it pays for that with the whole listing.

**Measured.** 65 tools: 14 `build()` and 92 `buildStatic()` calls, 264 ms inside builds in a cold process; a warm re-derivation takes 77 ms. The tool definitions are 909,635 bytes serialized, 14 KB per tool. The remembered tags are 15, among them `config:node_type_list`, `config:comment_type_list`, `config:contact_form_list`, `entity_field_info`, `entity_bundles` and `config:entity_form_display_list`.

**At scale (estimate).** 120 bundles at three tools each (`add`, `edit`, `edit:read`) plus the config resources come to about 400 tools: about 5.6 MB of tool definitions at 14 KB each, and about 650 builds per re-derivation at the measured 1.6 per tool. That is 0.5 s warm to 1.6 s cold at the measured per-build cost, and more for bundles with many fields. It is paid by the first listing after any field save, comment type save or contact form save, and in a burst by every concurrent agent request at once, since nothing locks the rebuild.

**The fix.** Memoize the neutral build per resource and operation for the length of one derivation, which takes 3 or 4 builds down to 1. Cache each resource's tool definitions in an entry of their own under that resource's tags, so a field saved on `node.article` re-derives three tools and unserializes the rest. Hold core's `lock` service around a re-derivation, so concurrent requests wait for one rebuild instead of all doing it.

```php
// Now, OperationInputs.php:436-438
protected function neutral(ResourceDefinition $resource, OperationDefinition $operation): ?DataSurfaceInterface {
  return $operation->parameters === [] ? NULL : $this->resources->buildStatic($resource->name(), new OperationContext($operation->id));
}

// Proposed: one build per operation per derivation, reset when the deriver starts.
protected function neutral(ResourceDefinition $resource, OperationDefinition $operation): ?DataSurfaceInterface {
  return $operation->parameters === [] ? NULL
    : ($this->neutral[$resource->name()][$operation->id] ??= $this->resources->buildStatic($resource->name(), new OperationContext($operation->id)));
}
```

**What it costs and breaks.** Internal; nothing on the wire. The listing stays one account's (`docs/decisions.md:2152`), as today.

### 3. The options memo keeps every definition it ever resolved

**Where it is paid.** `DataSurfaceOptions::resolve()` memoizes by `spl_object_id()` and keeps the definition itself in the entry so the id cannot be reused (`src/Options/DataSurfaceOptions.php:47`, `:96-98`, `:117`). Every refine makes new definition objects, so every refine, validate, emit and tool call adds entries that are never released. The docblock says "the memo dies with the request" (`DataSurfaceOptions.php:37`). That holds under PHP-FPM, but not in an MCP stdio server, a queue worker, a Drush batch or a worker-mode runtime, which is where bursts of agent calls run.

**Measured.** 1,000 validations of example 3 with distinct capacities: the memo grew from 1,103 to 2,013 entries and memory by 2.50 MB, all of it released when the memo was cleared. 300 `emit()` calls added 978 entries and 2.07 MB. One tool derivation leaves 1,103 entries holding 1.87 MB.

**At scale (estimate).** About 2.5 KB per call for a nine-key resource, so ten thousand calls in one worker keep about 25 MB, and more for entity forms. A 400-tool derivation keeps about 11 MB for the rest of its request.

**The fix.** A `WeakMap`, which is exactly the identity memo the docblock describes, without the leak or the id-reuse concern:

```php
// Now, DataSurfaceOptions.php:96-98 and :117
$id = spl_object_id($definition);
if (array_key_exists($id, $this->resolved)) { return $this->resolved[$id]['set']; }
// ...
$this->resolved[$id] = ['definition' => $definition, 'set' => $resolved];

// Proposed ($this->resolved = new \WeakMap() in the constructor)
if ($this->resolved->offsetExists($definition)) { return $this->resolved[$definition]; }
// ...
$this->resolved[$definition] = $resolved;
```

The same reading applies, in a smaller way, to `Resources::$staticAnswers` (`src/ResourceBuild/Resources.php:166`, `:1826-1838`): it is keyed by the context's `known`, so an edit context per entity adds one entry per entity id for the life of the worker. Bound it, or key it without the identity when the answer is static.

**What it costs and breaks.** About ten lines; nothing.

### 4. Builds are not shared within a request, and one access answer anywhere makes every access check build

**Where it is paid.** "A build is not cached" (`docs/decisions.md:320`, the docblock at `Resources.php:116-123`). A tool call builds for the listing when it is instantiated (`modules/data_surface_tool/src/Plugin/tool/Tool/ResourceOperationTool.php:79`, `:102`), again when its parameters refine `values` (`:127`), and again to execute (`:186`). A schema request builds in the route's access check (`modules/data_surface_react/src/Access/ServedOperationAccessCheck.php:73`, through `modules/data_surface_react/src/ServedOperations.php:107-108`, which also composes storage), again in the controller, then once more for the account alone (`modules/data_surface_react/src/Controller/ResourceApiController.php:147-152`). The access check runs before the dynamic page cache answers, so even a cached schema pays one build. Separately, `access()` builds the whole resource when `childrenMayAnswer()` is true (`Resources.php:1989-1993`), and that method answers for every variant on the site, not this resource's (`Resources.php:2124-2135`; its docblock says "some declared variant other than it").

**Measured.** A dry-run call to `data_surface:entity.content:node.page:edit` made one `buildStatic()` and two `build()` calls and took 39.8 ms in total; `registration.step3:configure` took 14.8 ms. No variant on this site answers access, so `access()` costs 0.001 to 0.05 ms here and does not build.

**At scale (estimate).** A 60-field bundle is about four times node.page's 14 children, each asking field access: 3 to 4 ms per build, about 10 ms of repeated builds per call. With fifty roles the schema route is kept per permissions hash in use, and every hit still pays a build. The first contrib module that ships a variant with an access class turns every `access()` on every resource into a full build.

**The fix.** A request-scoped memo of sealed builds, keyed by name, context, kind (`build` or `buildStatic`) and account kind. It skips any surface with max-age 0 and drops entries whose cache tags `ResourceRegistryInvalidator` sees invalidated, which is the same mechanism that already keeps the registry's copy fresh within a request. This engages the decision's reason: a cache keyed by context *alone* would be stale across requests, but a sealed surface carries its own cacheability, so within a request staleness is detectable. Compute "may a variant of this resource refuse" at discovery, on the definition, and memoize `ServedOperation` between the access check and the controller.

**What it costs and breaks.** A second paragraph on the decision; nothing on the wire. A build reading state its cacheability does not declare would be reused within the request, which is already a bug.

### 5. Enumeration refines the whole surface per combination, per key, with nothing shared

**Where it is paid.** `ContractEmitter::enumerate()` refines the whole data surface once per combination of watched values (`src/Contract/ContractEmitter.php:2040-2043`), per enumerated key, up to `ENUMERATION_CAP` (`:142`). Keys that watch the same sibling repeat identical refines, and each refine runs every chain and checks each watched value through typed data (`src/DataSurface.php:286-306`, `:651`). The React refine endpoint does the same for every keystroke with max-age 0 (`ResourceApiController.php:194`, `:439-441`), and so does the contract panel on every AJAX rebuild (`modules/data_surface_tool/src/ContractPanel.php:142`, `:156`).

**Measured.** Example 3 enumerates 3 + 6 + 2 = 11 refines; `field.instance` enumerates 15 + 20 + 20 = 55, and 20 of them repeat exactly (`settings` and `storage.settings` both watch `field_type`). Their full schemas take 0.85 to 1.0 ms and 2.3 to 2.5 ms.

**At scale (estimate).** Cost is about dependent keys x domain (up to 64) x one refine, and a refine grows with the key count. A 200-key resource with 20 dependent keys over 30-value lists makes about 600 refines; at example 3's 0.03 ms for 9 keys scaled linearly (0.7 ms each), about 0.4 s per full schema, per cache variation and per refine request.

**The fix.** Memoize refined surfaces by `[kinds, combination]` for one frame of one emit, held the way `Subschemas` is (`src/Contract/Subschemas.php:20`). Static refiners are pure functions of what they watch, and the `answersAgain()` assertion already holds them to it (`ContractEmitter.php:2059`, `:2278-2297`), so the memo cannot change an answer. Later, refine only the keys downstream of the combination, using the refinement graph `DataSurface` already computes (`DataSurface.php:286`).

```php
// Now, ContractEmitter.php:2040-2042
foreach ($next as $i => [$combination]) {
  $next[$i][1] = $declared->refine(array_replace($values, $combination), $kinds);

// Proposed
foreach ($next as $i => [$combination]) {
  $next[$i][1] = $this->refinedFor[serialize([$kinds, $combination])] ??= $declared->refine(array_replace($values, $combination), $kinds);
```

**What it costs and breaks.** Internal; nothing.

**What the `assert()`-only checks cost a developer.** Little, and they are well placed. With assertions on, full schemas cost 3% (node.page), 7.5% (`field.instance`) and 19% (example 3) more, and builds cost the same. One caveat is about production, not developers: PHP's built-in default for `zend.assertions` is 1, and container images that ship no `php.ini` run with it. On such a host, `StaticRefinerContainer` (`src/Refinement/StaticRefinerContainer.php:17`) and the re-asked access answers (`Resources.php:1804`) run in production, and a refiner that reaches for a service throws there instead of in development. `docs/installation.md` should say to set `zend.assertions=-1`.

## 3. Borrow

- **Core's `CacheCollector` and per-bundle field definition entries.** Core already solved "a large registry most requests need a small part of": lazy per-key entries, persisted at the end of the request under a lock. Derived resources are bundle configuration, exactly like bundle field definitions. This transfers because declarations are static: a derivative's definition is a pure function of the config it read, so it can be cached per derivative with that config's tags.
- **`WeakMap` for identity memos.** It is the language's answer to "memoize by object without keeping the object alive".
- **Stampede protection.** Use core's `lock` around tool re-derivation, or stale-while-revalidate: serve the previous listing while one request rebuilds. An agent burst after a deploy or a field change is a textbook stampede.
- **Performance budgets as tests.** Core's `PerformanceTestBase` asserts query and cache-get counts. A kernel test could assert "a dry-run tool call makes one build" and "deriving N tools makes N builds": deterministic, unlike timings, and they would have caught items 2 and 4.
- **Memoize what is promised cacheable.** `keysFrom()` refuses a list with max-age 0 or varying by account (`Resources.php:803-813`), so a provider's list can be kept under its own tags instead of asked on every build.

None of these touches the authoring surface. The concept budget is unchanged, refinement still only narrows, and since every memo above sits behind a pure function or declared cacheability, the documents cannot become stricter than the runtime.

## 4. Cut

- **Bundle-agnostic field item derivatives.** Of node.page's 14 field items, 11 are base fields (`title`, `uid`, `created`, `status`...) that are derived again for every bundle. The parent already sets the per-bundle label and description at attach (`modules/data_surface_entity/src/Resource/ContentEntityResources.php:131-132`). Derive one structure per field storage and settings hash, shared by every bundle where it is the same. At a hundred bundles that removes about half the definitions from item 1. The concept is unchanged (still a derived resource), but the id shape changes for shared items, which `docs/decisions.md` "The id shape" would need to allow.
- **The site-wide tags on every derivative.** Fold `entity_field_info`, `entity_bundles` and `config:entity_form_display_list` into the index entry only (item 1).
- **The listing build at instantiation when the call will refine anyway.** For an operation with parameters, the `values` listed at `create()` (`ResourceOperationTool.php:79`) is replaced by `refineInputDefinition()` before validation. Make it lazy, or reuse the neutral build.
- **The per-call `new \ReflectionMethod`** in `RefinesInputRefiner::invoke()` (`src/ResourceBuild/RefinesInputRefiner.php:216`), in the innermost loop of item 5. Keep a closure per link.

Nothing in `docs/concepts.md` should be cut for performance: the costs are in the engine, not the vocabulary.

## 5. Names and spelling that read wrong

| Current | Proposed | Reason |
| --- | --- | --- |
| `buildStatic()` | `buildForAccountAlone()` | To a performance reader "static" means memoized or precomputed. It is a full build with runtime access set aside (`Resources.php:197`). The docs already say "for the account alone". It does not cross the product-name rule: a method name is a concept name. |
| `childrenMayAnswer()` | `anyVariantAnswersAccess()`, or scope it and keep the name | It reads as this resource's children, but it scans every variant on the site (`Resources.php:2124-2135`). The name hides a cliff. |
| `ENUMERATION_CAP` | `ENUMERATION_CAP_PER_KEY` | It caps each key's combinations, not a document's (`ContractEmitter.php:136-142`). The total is unbounded in the number of keys. |
| "the memo dies with the request" (docblock) | "the memo lives as long as the service" | False in a worker (`DataSurfaceOptions.php:37`); see item 3. |
| `ToolDefinitionsInvalidator::remember()` | `rememberTags()` | It writes State on every derivation whose tags changed (`ToolDefinitionsInvalidator.php:59-67`). The name should say it persists something. |
| "A build is not cached" (decision title) | "A build is not cached across requests" | Item 4's memo is consistent with the reason given. The title as written forbids it. |

None of these is a wire identifier, a route, a config name or a service id, so none crosses `docs/decisions.md#the-product-keeps-its-name` (`docs/decisions.md:69`).

## 6. Verdict

I would adopt it today for configuration resources and their tools on a large site: a build is under a millisecond, the full schema is cacheable with honest cacheability, and the assertion-time checks cost a developer a few percent. The caveat is `data_surface_entity` with `data_surface_tool` on a hundred-bundle site: the single discovery item (about 7.5 MB), the whole-listing tool invalidation (about 650 unlocked builds per field change) and the options memo in workers would each hurt production, and I would not enable that pair until they are fixed. One change would move my answer to an unqualified yes: per-derivative discovery entries with narrow tags, with tool definitions cached per resource under the same tags, so one field change rebuilds one bundle's definitions and tools and nothing else.
