<?php

declare(strict_types=1);

namespace Drupal\surface\ResourceBuild;

use Drupal\surface\Contract\SchemaReader;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\surface\Hook\ResourcePluginHooks;
use Drupal\surface\Resource\Attribute\Alters;
use Drupal\surface\Resource\Attribute\DerivesResources;
use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\Attribute\Shape;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\Attribute\Variant;
use Drupal\surface\Resource\DerivedOperation;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Reference\EntityReferenceStructure;
use Drupal\surface\Resource\ResourceCallable;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\AltersInputsInterface;
use Drupal\surface\Resource\OperationAccessInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface\Resource\ShapeInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface\Resource\ResourceDeriverInterface;
use Drupal\surface\Resource\KnowsDerivativeListTagsInterface;

/**
 * Every resource, structure and shape, with operations, alters, variants.
 *
 * The compiler pass lists the classes in src/Resource, src/Structure,
 * src/Shape and src/Alter; the plugin definitions list the plugins that
 * are their own structure (#[UsesStructure] with no argument). This
 * reads their attributes and caches what it read in the discovery bin,
 * the way a plugin manager caches its definitions, in two layers: an
 * index (every name and id, its kind and provider, and the shapes),
 * and one entry per definition, read by name when it is asked for and
 * kept for the rest of the request. A request that builds one resource
 * reads the index and what that resource names, never the whole of
 * discovery. The cache ids carry a hash of both lists, so a container
 * rebuilt with a module more or less, or a plugin more or less, is a
 * different set of entries and needs no clearing; an attribute edited
 * in place needs a cache rebuild, exactly as a plugin attribute does.
 *
 * A deriver (#[DerivesResources]) is asked for its resources here too,
 * and each is read into a definition with no class, under the id
 * `<base>:<derivative>`. What it derived from is live site state, so
 * each derivative's entry carries the cache tags it names, and only
 * those: a field saved clears that field's structure and its bundle's
 * resource. The index carries what decides which derivatives there are
 * (KnowsDerivativeListTagsInterface), so a bundle created or a form
 * display saved reads the list again; reading it again derives
 * everything, and writes only the entries whose tags fired or whose
 * content changed, every other one kept as it was cached. Through
 * ResourceRegistryInvalidator, an invalidation also reaches what this
 * request already read.
 *
 * Two kinds of refusal, kept apart on purpose:
 * - What is wrong with the attributes themselves (an operation with
 *   nothing to belong to, an operation method that is not static, a
 *   class whose defineInputs() is not static, two classes claiming one id,
 *   a class with the attribute of one kind and the interface of the
 *   other, a reference to a class that exists and is neither a resource
 *   nor a structure) is refused here, when discovery is read, because it
 *   cannot be pinned on one build.
 * - What is wrong with one resource or structure (two operations with
 *   one id, a refiner watching a key it never declares) is
 *   refused when that one is asked for, naming the offender, so one
 *   module's mistake does not take every other one down with it.
 *
 * Decision: see docs/decisions.md#what-is-paid-once-and-what-per-request.
 */
final class ResourceRegistry {

  /**
   * The cache id prefix in the discovery bin.
   */
  protected const CACHE_PREFIX = 'surface:resources:';

  /**
   * The attribute arguments removed on 2026-10-24, with what replaced each.
   *
   * Decision: see docs/decisions.md#the-surface-was-counted-and-cut.
   */
  protected const REMOVED_ARGUMENTS = [
    RefinesInput::class => [
      'watches' => 'what a refiner watches is its parameters after the definition, each named for the key it is handed.',
    ],
    Operation::class => [
      'default' => 'a child is invoked through the operation its parent\'s context names, else its operation with the parent context\'s id, else its operation with the standard id of the parent operation\'s kind (create, update or configure, read or list, delete).',
      'of' => 'an operation stands on its resource\'s or structure\'s own class.',
    ],
  ];

  /**
   * The index, once read: what there is, by name, and the shapes.
   *
   * `ids` maps an id to its name where they differ (a class's), `refusing`
   * names the holders of a variant that answers access.
   *
   * @var array{names: array<string, array{id: string, kind: string, module: string, base: string|null, stored: bool}>, ids: array<string, string>, shapes: array<class-string, \Drupal\surface\ResourceBuild\ShapeDefinition>, refusing: array<string, true>}|null
   */
  protected ?array $index = NULL;

  /**
   * The hash both lists give, for the index read now.
   */
  protected string $hash = '';

  /**
   * The cache tags of the index read now.
   *
   * @var string[]
   */
  protected array $indexTags = [];

  /**
   * The definitions read so far in this request, by name.
   *
   * @var array<string, \Drupal\surface\ResourceBuild\ResourceDefinition>
   */
  protected array $definitions = [];

  /**
   * The cache tags of each definition read so far, by name.
   *
   * @var array<string, string[]>
   */
  protected array $entryTags = [];

  /**
   * Every definition, in discovery order, once all were read.
   *
   * @var array<string, \Drupal\surface\ResourceBuild\ResourceDefinition>|null
   */
  protected ?array $all = NULL;

  /**
   * What was read while the plugin hosts were being read, by cache id.
   *
   * Answered to the calls made then, so a deriver asking many times does
   * not discover each time, and never kept past them.
   *
   * @var array<string, array{resources: array<string, \Drupal\surface\ResourceBuild\ResourceDefinition>, shapes: array<class-string, \Drupal\surface\ResourceBuild\ShapeDefinition>}>
   */
  protected array $provisional = [];

  /**
   * The structures read from a schema document, by the name each is asked by.
   *
   * Kept for the life of this service and listed by nothing: no catalog,
   * baseline or tool sees them. Each is registered again, under the same
   * name, whenever what reads it is built.
   *
   * @var array<string, \Drupal\surface\ResourceBuild\ResourceDefinition>
   */
  protected array $read = [];

  /**
   * How discovery was read in this request, for a performance budget.
   *
   * @var array{index: int, entries: int, rebuilds: int, written: int}
   */
  protected array $reads = ['index' => 0, 'entries' => 0, 'rebuilds' => 0, 'written' => 0];

  /**
   * Constructs a ResourceRegistry.
   *
   * @param array<string, array<class-string, mixed>> $classes
   *   The class lists the compiler pass wrote, by kind, valued with the
   *   module each class is in; `overrides` is keyed by deriver, then by
   *   the class carrying its override attribute.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The discovery cache bin.
   * @param \Drupal\surface\ResourceBuild\ResourcePlugins|null $plugins
   *   The plugin definitions, for the plugins that are their own
   *   structure; NULL reads none.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface|null $classResolver
   *   What returns each deriver as the autowired service discovery
   *   registered; NULL asks no deriver.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $entityTypeManager
   *   The entity type manager, which a gate naming an entity type is held
   *   to; NULL holds it to nothing.
   */
  public function __construct(
    protected readonly array $classes,
    protected readonly CacheBackendInterface $cache,
    protected readonly ?ResourcePlugins $plugins = NULL,
    protected readonly ?ClassResolverInterface $classResolver = NULL,
    protected readonly ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {
  }

  /**
   * Gets every discovered resource and structure.
   *
   * Reads every entry: what lists the whole site (the catalog, the tool
   * deriver) asks for this; what builds one resource asks for it by name
   * (getDefinition()), which reads only its entry.
   *
   * @return array<string, \Drupal\surface\ResourceBuild\ResourceDefinition>
   *   The definitions, keyed by name: a class, or a derived resource's
   *   id.
   *
   * @throws \LogicException
   *   When an attribute is misplaced, two classes claim one id, or a
   *   deriver is no ResourceDeriverInterface or names a derivative with a
   *   colon.
   */
  public function getDefinitions(): array {
    $provisional = $this->provisional();
    if ($provisional !== NULL) {
      return $provisional['resources'];
    }
    if ($this->all !== NULL) {
      return $this->all;
    }
    $index = $this->index();
    $missing = array_diff_key($index['names'], $this->definitions);
    if ($missing !== []) {
      $cids = [];
      foreach (array_keys($missing) as $name) {
        $cids[$this->entryCid((string) $name)] = (string) $name;
      }
      $asked = array_keys($cids);
      $hits = $this->cache->getMultiple($asked);
      $this->reads['entries'] += count($cids);
      foreach ($cids as $cid => $name) {
        if (!isset($hits[$cid]) || !$hits[$cid]->data instanceof ResourceDefinition) {
          $this->rebuild();
          return $this->getDefinitions();
        }
        $this->remember($name, $hits[$cid]->data, $hits[$cid]->tags);
      }
    }
    $all = [];
    foreach (array_keys($index['names']) as $name) {
      $all[$name] = $this->definitions[$name];
    }
    return $this->all = $all;
  }

  /**
   * Gets the index: every resource and structure by name, without reading one.
   *
   * What a caller that picks some resources out of all of them reads first
   * (the tool deriver takes only those that name storage), so it reads the
   * entries of those alone.
   *
   * @return array<string, array{id: string, kind: string, module: string, base: string|null, stored: bool}>
   *   Each one's id, kind, module, the base it was derived under (NULL for
   *   a class) and whether it names storage, keyed by name, in discovery
   *   order.
   */
  public function getIndex(): array {
    $provisional = $this->provisional();
    if ($provisional !== NULL) {
      return array_map(static::indexed(...), $provisional['resources']);
    }
    return $this->index()['names'];
  }

  /**
   * Gets the cache tags of the index: what decides what there is.
   *
   * What a listing of every resource (the tool deriver's) is cleared by
   * beside each resource's own: a bundle created, a form display saved.
   *
   * @return string[]
   *   The tags; none while the plugin hosts are being read.
   */
  public function getIndexCacheTags(): array {
    if ($this->provisional() !== NULL) {
      return [];
    }
    $this->index();
    return $this->indexTags;
  }

  /**
   * Answers whether any variant here names an access answer, by holder.
   *
   * The resources and structures that hold, at some key, a variant whose
   * access may refuse them (Gating::childAnswers()): read off the
   * index, so asking reads no definition.
   *
   * @return array<string, true>
   *   The names of the holders.
   */
  public function getRefusingVariantHolders(): array {
    $provisional = $this->provisional();
    if ($provisional !== NULL) {
      return static::refusing($provisional['resources']);
    }
    return $this->index()['refusing'];
  }

  /**
   * Reports how discovery was read in this request.
   *
   * For a performance budget, never for behavior: how many times the
   * index was read from the bin, how many entries were, how many times
   * discovery was read again from the classes and the derivers, and how
   * many entries that wrote.
   *
   * @return array{index: int, entries: int, rebuilds: int, written: int}
   *   The counts.
   *
   * @internal
   */
  public function reads(): array {
    return $this->reads;
  }

  /**
   * Answers from a read that is not the whole answer, while hosts are read.
   *
   * Asked while the plugin hosts are being read, from inside a deriver
   * one of them runs (eca_tool's action deriver asks the tool deriver,
   * which asks here), the plugins that are their own structure come
   * from every other host, which is not the whole answer: what is
   * discovered from it is answered and neither kept nor cached, so the
   * memo and the discovery bin only ever hold what every host said,
   * whichever was asked first.
   *
   * @return array{resources: array<string, \Drupal\surface\ResourceBuild\ResourceDefinition>, shapes: array<class-string, \Drupal\surface\ResourceBuild\ShapeDefinition>}|null
   *   The provisional read, or NULL once every host was read whole.
   */
  protected function provisional(): ?array {
    if ($this->index !== NULL || ($this->plugins?->isComplete() ?? TRUE)) {
      return NULL;
    }
    // Asking for the plugins that are their own structure while their
    // hosts are read may finish reading them, so ask before deciding.
    $own = $this->plugins->ownStructures();
    if ($this->plugins->isComplete()) {
      return NULL;
    }
    $cid = static::hashOf($own === [] ? $this->classes : [$this->classes, $own]);
    return $this->provisional[$cid] ??= $this->discover($own);
  }

  /**
   * Reads the index, from the bin or by reading discovery again.
   *
   * @return array{names: array<string, array{id: string, kind: string, module: string, base: string|null, stored: bool}>, ids: array<string, string>, shapes: array<class-string, \Drupal\surface\ResourceBuild\ShapeDefinition>, refusing: array<string, true>}
   *   The index.
   */
  protected function index(): array {
    if ($this->index !== NULL) {
      return $this->index;
    }
    $own = $this->plugins?->ownStructures() ?? [];
    $this->hash = static::hashOf($own === [] ? $this->classes : [$this->classes, $own]);
    $this->provisional = [];
    $cached = $this->cache->get(self::CACHE_PREFIX . 'index:' . $this->hash);
    $this->reads['index']++;
    if ($cached !== FALSE && is_array($cached->data) && isset($cached->data['names'], $cached->data['ids'], $cached->data['shapes'], $cached->data['refusing'])) {
      $this->indexTags = $cached->tags;
      return $this->index = $cached->data;
    }
    $this->rebuild($own);
    assert($this->index !== NULL);
    return $this->index;
  }

  /**
   * Reads discovery again, and writes what the bin no longer holds.
   *
   * Every definition is read from the classes and the derivers; the index
   * is written, and each entry whose cached copy is gone (its tags fired)
   * or differs from what was read. What the bin still holds unchanged is
   * left as it is. What was read is kept for the rest of the request.
   *
   * @param array<class-string, array{module: string, id: string}>|null $own
   *   The plugins that are their own structure, when already asked.
   */
  protected function rebuild(?array $own = NULL): void {
    $own ??= $this->plugins?->ownStructures() ?? [];
    $this->hash = static::hashOf($own === [] ? $this->classes : [$this->classes, $own]);
    $list_tags = [];
    $read = $this->discover($own, $list_tags);
    $this->reads['rebuilds']++;
    $names = array_map(static::indexed(...), $read['resources']);
    // A derived resource is named by its id; only a class needs one here.
    $ids = [];
    foreach ($read['resources'] as $name => $definition) {
      if ($definition->id !== $name) {
        $ids[$definition->id] = $name;
      }
    }
    $index = [
      'names' => $names,
      'ids' => $ids,
      'shapes' => $read['shapes'],
      'refusing' => static::refusing($read['resources']),
    ];

    $cids = [];
    foreach (array_keys($read['resources']) as $name) {
      $cids[$this->entryCid((string) $name)] = (string) $name;
    }
    $asked = array_keys($cids);
    $kept = $this->cache->getMultiple($asked);
    $items = [];
    foreach ($cids as $cid => $name) {
      $definition = $read['resources'][$name];
      if (isset($kept[$cid]) && serialize($kept[$cid]->data) === serialize($definition)) {
        continue;
      }
      $items[$cid] = ['data' => $definition, 'expire' => Cache::PERMANENT, 'tags' => $definition->cacheTags];
    }
    if ($items !== []) {
      $this->cache->setMultiple($items);
      $this->reads['written'] += count($items);
    }
    $this->cache->set(self::CACHE_PREFIX . 'index:' . $this->hash, $index, Cache::PERMANENT, $list_tags);

    $this->index = $index;
    $this->indexTags = $list_tags;
    $this->definitions = [];
    $this->entryTags = [];
    foreach ($read['resources'] as $name => $definition) {
      $this->remember((string) $name, $definition, $definition->cacheTags);
    }
    $this->all = $read['resources'];
  }

  /**
   * Keeps one definition read for the rest of the request.
   *
   * @param string $name
   *   Its name.
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The definition.
   * @param string[] $tags
   *   The tags its entry carries.
   */
  protected function remember(string $name, ResourceDefinition $definition, array $tags): void {
    $this->definitions[$name] = $definition;
    $this->entryTags[$name] = $tags;
  }

  /**
   * Gets the cache id of one definition's entry.
   *
   * @param string $name
   *   The definition's name.
   *
   * @return string
   *   The cache id, beside the index of the same lists.
   */
  protected function entryCid(string $name): string {
    return self::CACHE_PREFIX . 'entry:' . $this->hash . ':' . $name;
  }

  /**
   * Hashes the lists discovery is read from.
   *
   * @param array $lists
   *   The class lists, with the plugins that are their own structure.
   *
   * @return string
   *   The hash.
   */
  protected static function hashOf(array $lists): string {
    return hash('xxh3', serialize($lists));
  }

  /**
   * Says what the index keeps of one definition.
   *
   * @param \Drupal\surface\ResourceBuild\ResourceDefinition $definition
   *   The definition.
   *
   * @return array{id: string, kind: string, module: string, base: string|null, stored: bool}
   *   Its id, kind, module, base and whether it names storage.
   */
  protected static function indexed(ResourceDefinition $definition): array {
    return [
      'id' => $definition->id,
      'kind' => $definition->kind,
      'module' => $definition->module,
      'base' => $definition->base,
      'stored' => $definition->storage !== NULL,
    ];
  }

  /**
   * Lists the definitions holding a variant that answers access.
   *
   * @param array<string, \Drupal\surface\ResourceBuild\ResourceDefinition> $resources
   *   Every definition, by name.
   *
   * @return array<string, true>
   *   The holders' names.
   */
  protected static function refusing(array $resources): array {
    $holders = [];
    foreach ($resources as $name => $definition) {
      foreach ($definition->variants as $variants) {
        foreach ($variants as $class) {
          if (isset($resources[$class]) && $resources[$class]->answersAccess()) {
            $holders[$name] = TRUE;
          }
        }
      }
    }
    return $holders;
  }

  /**
   * Forgets what this request read, when what it was read from changed.
   *
   * The discovery bin drops its entries by tag on its own; this is the
   * copy held since, which a request that saves a field and then builds a
   * resource would otherwise read stale. A tag of the index forgets
   * everything; a tag of one entry forgets that entry.
   *
   * @param string[] $tags
   *   The tags being invalidated.
   *
   * @return bool
   *   TRUE when the index was forgotten, and so everything read: what was
   *   built from it may describe a resource that is no longer there.
   */
  public function invalidateTags(array $tags): bool {
    if ($this->index !== NULL && array_intersect($tags, $this->indexTags) !== []) {
      $this->index = NULL;
      $this->indexTags = [];
      $this->definitions = [];
      $this->entryTags = [];
      $this->all = NULL;
      $this->provisional = [];
      return TRUE;
    }
    foreach ($this->entryTags as $name => $entry_tags) {
      if (array_intersect($tags, $entry_tags) !== []) {
        unset($this->definitions[$name], $this->entryTags[$name]);
        $this->all = NULL;
      }
    }
    return FALSE;
  }

  /**
   * Lists the derivers discovered, by base id.
   *
   * @return array<string, array{class: class-string, module: string, overriddenBy: class-string|null, overridden: array<class-string, string>}>
   *   Each deriver's class and module, the attribute an override of its
   *   derivation carries and the classes that carry it, keyed by base id
   *   and sorted.
   */
  public function getDerivers(): array {
    $derivers = [];
    foreach ($this->classes['derivers'] ?? [] as $class => $module) {
      $attribute = (new \ReflectionClass($class))->getAttributes(DerivesResources::class)[0]->newInstance();
      $derivers[$attribute->base] = [
        'class' => $class,
        'module' => (string) $module,
        'overriddenBy' => $attribute->overriddenBy,
        'overridden' => $this->classes['overrides'][$class] ?? [],
      ];
    }
    ksort($derivers);
    return $derivers;
  }

  /**
   * Answers whether a resource or structure is discovered, by class or id.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   *
   * @return bool
   *   TRUE when discovery found it.
   */
  public function hasDefinition(string $resource): bool {
    return $this->find($resource) !== NULL;
  }

  /**
   * Gets the structures read from a schema document in this request.
   *
   * What the builds so far registered (defineRead()), which nothing else
   * lists: the site catalog builds every operation first, then lists
   * these, so a structure a provider or a document supplies is on the
   * map too.
   *
   * @return array<string, \Drupal\surface\ResourceBuild\ResourceDefinition>
   *   The definitions, by the name each is built by, `schema:<id>:<hash>`,
   *   sorted.
   *
   * @internal
   *   For the site catalog.
   */
  public function getReadDefinitions(): array {
    $read = array_filter($this->read, static fn (ResourceDefinition $definition): bool => str_starts_with((string) $definition->readAs, 'schema:'));
    ksort($read);
    return $read;
  }

  /**
   * Registers a structure read from a schema document, to be built by name.
   *
   * A document's object at a key, a def it refers to, a variant it states
   * or one a provider supplies is a structure with no class: its inputs
   * are what SchemaReader reads from the document. It is asked for by the
   * name this answers, `schema:<id>:<hash>`, which the hash of what it
   * was read from keeps the same for the same document and apart for
   * another one claiming the same id; its id is the document's own.
   *
   * Decision: see docs/decisions.md#a-schema-can-be-read-back-into-a-structure.
   *
   * @param string $id
   *   The id it has in the document: a def's key, or `<owner>.<key>`.
   * @param string $module
   *   The module of what reads it, which it is reported under.
   * @param \Drupal\surface\Resource\ResourceCallable $inputs
   *   What fills its blueprint.
   * @param string $hash
   *   A hash of what it is read from.
   *
   * @return string
   *   The name it is built by.
   */
  public function defineRead(string $id, string $module, ResourceCallable $inputs, string $hash): string {
    $name = 'schema:' . $id . ':' . $hash;
    if (!isset($this->read[$name])) {
      [$label, $description] = self::readNames($inputs, $id);
      $this->read[$name] = new ResourceDefinition(
        class: NULL,
        id: $id,
        module: $module,
        identity: [],
        storage: NULL,
        access: NULL,
        refiners: [],
        inputs: $inputs,
        label: $label,
        description: $description,
        kind: ResourceDefinition::STRUCTURE,
        readAs: $name,
      );
    }
    return $name;
  }

  /**
   * Reads what a structure read from a schema is called and is for.
   *
   * A def's `title` and `description`, as the emitter writes a
   * structure's label and description there (SchemaReader::names()), so
   * a document reads back to itself. Only for a def: the object at a key,
   * or a document's root, is titled by the key or the operation.
   *
   * Decision: see
   * docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
   *
   * @param \Drupal\surface\Resource\ResourceCallable $inputs
   *   What fills its blueprint.
   * @param string $id
   *   Its id in the document.
   *
   * @return array{0: string|null, 1: string|null}
   *   The label and the description, each NULL where the def says none.
   */
  protected static function readNames(ResourceCallable $inputs, string $id): array {
    [$root, $pointer] = $inputs->arguments + [NULL, NULL];
    if ($inputs->class !== SchemaReader::class || $inputs->method !== 'fill' || !is_array($root) || !is_string($pointer)) {
      return [NULL, NULL];
    }
    return SchemaReader::names($root, $pointer, $id);
  }

  /**
   * Writes down structures read from a schema so a later request has them.
   *
   * A build the build cache answers names, at its keys, structures read
   * from a schema document or made for a reference key, which a request
   * registers only by building their owner: the cache stores them with
   * the build (exportRead()) and registers them again when it answers it
   * (rememberRead()), so every door can build and name them as if this
   * request had built the owner. A document many structures are read
   * from (a theme's components, each one a def of it) is written once.
   *
   * Decision: see
   * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
   *
   * @param array<string, \Drupal\surface\ResourceBuild\ResourceDefinition> $definitions
   *   The definitions, by the name each is built by.
   *
   * @return array{documents: array<string, array>, schema: array<string, array{0: string, 1: string, 2: string, 3: string}>, other: array<string, \Drupal\surface\ResourceBuild\ResourceDefinition>}
   *   Each document once, by its hash; each structure read from one, as
   *   its id, module, pointer and document hash; and the rest as they are.
   *
   * @internal
   *   For the build step alone.
   */
  public function exportRead(array $definitions): array {
    $export = ['documents' => [], 'schema' => [], 'other' => []];
    foreach ($definitions as $name => $definition) {
      $inputs = $definition->inputs;
      [$root, $pointer, $hash] = ($inputs->arguments ?? []) + [NULL, NULL, NULL];
      if ($inputs?->class === SchemaReader::class && $inputs->method === 'fill' && is_array($root) && is_string($pointer) && is_string($hash) && str_starts_with((string) $name, 'schema:')) {
        $export['documents'][$hash] ??= $root;
        $export['schema'][(string) $name] = [$definition->id, $definition->module, $pointer, $hash];
        continue;
      }
      $export['other'][(string) $name] = $definition;
    }
    return $export;
  }

  /**
   * Registers again what exportRead() wrote down.
   *
   * @param array{documents?: array<string, array>, schema?: array<string, array{0: string, 1: string, 2: string, 3: string}>, other?: array<string, \Drupal\surface\ResourceBuild\ResourceDefinition>} $export
   *   What it answered.
   *
   * @internal
   *   For the build step alone.
   */
  public function rememberRead(array $export): void {
    foreach ($export['schema'] ?? [] as $name => [$id, $module, $pointer, $hash]) {
      if (isset($this->read[$name]) || !isset($export['documents'][$hash])) {
        continue;
      }
      $inputs = new ResourceCallable(SchemaReader::class, 'fill', [$export['documents'][$hash], $pointer, $hash]);
      [$label, $description] = self::readNames($inputs, $id);
      $this->read[$name] = new ResourceDefinition(
        class: NULL,
        id: $id,
        module: $module,
        identity: [],
        storage: NULL,
        access: NULL,
        refiners: [],
        inputs: $inputs,
        label: $label,
        description: $description,
        kind: ResourceDefinition::STRUCTURE,
        readAs: $name,
      );
    }
    foreach ($export['other'] ?? [] as $name => $definition) {
      if ($definition->isRead()) {
        $this->read[(string) $name] ??= $definition;
      }
    }
  }

  /**
   * Gets a structure read from a schema, or made for a reference key.
   *
   * @param string $name
   *   The name it is built by.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceDefinition|null
   *   Its definition, or NULL when this request registered none by it.
   *
   * @internal
   *   For the build step alone.
   */
  public function readDefinition(string $name): ?ResourceDefinition {
    return $this->read[$name] ?? NULL;
  }

  /**
   * Registers the two parts of a bare `entity` key, for one key's filter.
   *
   * Like a structure read from a schema, listed by nothing and built by
   * name: its inputs EntityReferenceStructure::fill() with the filter, its
   * refiner EntityReferenceStructure::id().
   *
   * Decision: see
   * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
   *
   * @param string $id
   *   Its id: the owner's, then the key.
   * @param string $module
   *   The owner's module.
   * @param array{interface: string|null, targets: array<string, string[]>, required: bool, byId?: true} $filter
   *   What the key's author narrowed it by, as
   *   EntityReferenceStructure::filter() reads it.
   *
   * @return string
   *   The name it is attached and built by.
   */
  public function defineReference(string $id, string $module, array $filter): string {
    $name = 'reference:' . $id . ':' . hash('xxh128', serialize($filter));
    $this->read[$name] ??= new ResourceDefinition(
      class: NULL,
      id: $id,
      module: $module,
      identity: [],
      storage: NULL,
      access: NULL,
      refiners: self::refinersOf(new \ReflectionClass(EntityReferenceStructure::class)),
      inputs: new ResourceCallable(EntityReferenceStructure::class, 'fill', [
        $filter['interface'],
        $filter['targets'],
        $filter['required'],
        $filter['byId'] ?? FALSE,
      ]),
      kind: ResourceDefinition::STRUCTURE,
      readAs: $name,
    );
    return $name;
  }

  /**
   * Gets every discovered shape.
   *
   * @return array<class-string, \Drupal\surface\ResourceBuild\ShapeDefinition>
   *   The shapes, keyed by class.
   */
  public function getShapes(): array {
    return $this->provisional()['shapes'] ?? $this->index()['shapes'];
  }

  /**
   * Answers whether a shape is discovered, by class or id.
   *
   * @param string $shape
   *   The class, or its #[Shape] id.
   *
   * @return bool
   *   TRUE when discovery found it.
   */
  public function hasShape(string $shape): bool {
    return $this->findShape($shape) !== NULL;
  }

  /**
   * Gets one shape, by class or by id.
   *
   * @param string $shape
   *   The class, or its #[Shape] id.
   *
   * @return \Drupal\surface\ResourceBuild\ShapeDefinition
   *   The shape.
   *
   * @throws \InvalidArgumentException
   *   When nothing discovered has that class or id.
   */
  public function getShape(string $shape): ShapeDefinition {
    return $this->findShape($shape) ?? throw new \InvalidArgumentException(sprintf(
      'No shape "%s" was discovered: one is a class carrying #[Shape] and implementing %s in an enabled module\'s src/Shape directory.',
      $shape,
      ShapeInterface::class,
    ));
  }

  /**
   * Finds a shape by class, then by id.
   *
   * @param string $shape
   *   The class or id.
   *
   * @return \Drupal\surface\ResourceBuild\ShapeDefinition|null
   *   The shape, or NULL.
   */
  protected function findShape(string $shape): ?ShapeDefinition {
    $shapes = $this->getShapes();
    if (isset($shapes[$shape])) {
      return $shapes[$shape];
    }
    foreach ($shapes as $definition) {
      if ($definition->id === $shape) {
        return $definition;
      }
    }
    return NULL;
  }

  /**
   * Gets one resource or structure, by class or by id.
   *
   * @param string $resource
   *   The class, or its #[Resource] or #[Structure] id.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceDefinition
   *   The definition.
   *
   * @throws \InvalidArgumentException
   *   When nothing discovered has that class or id.
   */
  public function getDefinition(string $resource): ResourceDefinition {
    return $this->find($resource) ?? throw new \InvalidArgumentException(sprintf(
      'No resource or structure "%s" was discovered: one is a class carrying #[Resource] or #[Structure] in an enabled module\'s src/Resource or src/Structure directory, or a plugin carrying #[UsesStructure] with no argument.',
      $resource,
    ));
  }

  /**
   * Gets the operations of one resource or structure, refusing a clash.
   *
   * @param string $resource
   *   The class or id.
   *
   * @return array<string, \Drupal\surface\ResourceBuild\OperationDefinition>
   *   The operations, keyed by id.
   *
   * @throws \LogicException
   *   When two providers declare one operation id.
   */
  public function getOperations(string $resource): array {
    $definition = $this->getDefinition($resource);
    $operations = [];
    foreach ($definition->operations as $operation) {
      if (isset($operations[$operation->id])) {
        throw new \LogicException(sprintf(
          '%s has two operations with the id "%s", from %s and from %s. An operation id is unique within its resource or structure, because it is the id a context is addressed by.',
          $definition->id,
          $operation->id,
          $operations[$operation->id]->describe(),
          $operation->describe(),
        ));
      }
      $operations[$operation->id] = $operation;
    }
    return $operations;
  }

  /**
   * Gets one operation of one resource or structure.
   *
   * @param string $resource
   *   The class or id.
   * @param string $id
   *   The operation id.
   *
   * @return \Drupal\surface\ResourceBuild\OperationDefinition
   *   The operation.
   *
   * @throws \InvalidArgumentException
   *   When it has no such operation.
   */
  public function getOperation(string $resource, string $id): OperationDefinition {
    $operations = $this->getOperations($resource);
    return $operations[$id] ?? throw new \InvalidArgumentException(sprintf(
      '%s has no "%s" operation; it has %s.',
      $this->getDefinition($resource)->id,
      $id,
      $operations === [] ? 'none' : implode(', ', array_keys($operations)),
    ));
  }

  /**
   * Gets the alters of one resource or structure, in the order they apply.
   *
   * @param string $resource
   *   The class or id.
   *
   * @return \Drupal\surface\ResourceBuild\AlterDefinition[]
   *   The alters.
   */
  public function getAlters(string $resource): array {
    return $this->getDefinition($resource)->alters;
  }

  /**
   * Gets the structures marked as variants at one key of one class.
   *
   * The variants any module may add to: attachVariants() names no
   * children, so the build step fills them from here.
   *
   * @param string $resource
   *   The class or id holding the variants.
   * @param string $key
   *   The key the variants are attached at.
   *
   * @return array<string, class-string>
   *   The variant classes, keyed by the deciding value.
   */
  public function getVariants(string $resource, string $key): array {
    return $this->getDefinition($resource)->variants[$key] ?? [];
  }

  /**
   * Finds a resource or structure by class, then by id.
   *
   * @param string $resource
   *   The class or id.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceDefinition|null
   *   The definition, or NULL.
   */
  protected function find(string $resource): ?ResourceDefinition {
    if (isset($this->read[$resource])) {
      return $this->read[$resource];
    }
    $provisional = $this->provisional();
    if ($provisional !== NULL) {
      if (isset($provisional['resources'][$resource])) {
        return $provisional['resources'][$resource];
      }
      foreach ($provisional['resources'] as $definition) {
        if ($definition->id === $resource) {
          return $definition;
        }
      }
      return NULL;
    }
    if (isset($this->definitions[$resource])) {
      return $this->definitions[$resource];
    }
    $index = $this->index();
    $name = isset($index['names'][$resource]) ? $resource : ($index['ids'][$resource] ?? NULL);
    if ($name === NULL) {
      return NULL;
    }
    if (isset($this->definitions[$name])) {
      return $this->definitions[$name];
    }
    $cached = $this->cache->get($this->entryCid($name));
    $this->reads['entries']++;
    if ($cached !== FALSE && $cached->data instanceof ResourceDefinition) {
      $this->remember($name, $cached->data, $cached->tags);
      return $cached->data;
    }
    // Gone from the bin while the index still names it (its own tags
    // fired, or the bin let it go): read discovery again, which writes it.
    $this->rebuild();
    return isset($this->index['names'][$resource]) || isset($this->index['ids'][$resource]) ? $this->find($resource) : NULL;
  }

  /**
   * Holds a declared version to what can be honored today.
   *
   * A version names the stored values a class describes, and one above 1
   * says values stored at an older version are converted forward before
   * anything reads them. Those migrations are designed and not built, so
   * a version above 1 would be a promise nothing keeps: refused, as
   * storage that would quietly write nothing is refused.
   *
   * Decision: see docs/decisions.md#a-resource-evolves-in-the-open.
   *
   * @param class-string $class
   *   The class.
   * @param int $version
   *   The version its attribute declares.
   *
   * @return int
   *   The version.
   */
  protected static function versionOf(string $class, int $version): int {
    if ($version < 1) {
      throw new \LogicException(sprintf('%s declares version %d. A version counts the breaking changes made to stored values, from 1.', $class, $version));
    }
    if ($version > 1) {
      throw new \LogicException(sprintf(
        '%s declares version %d. A version above 1 needs its stored-value migrations, which are designed (docs/evolution.md) and not built yet (ROADMAP.md, Phase D item 13). Until then a breaking change is accepted by an allowance recorded in the contract baseline.',
        $class,
        $version,
      ));
    }
    return $version;
  }

  /**
   * Reads every attribute on every discovered class.
   *
   * @param array<class-string, array{module: string, id: string}> $own
   *   The plugins that are their own structure, with the module and the id
   *   their plugin definitions give them.
   * @param string[] $list_tags
   *   Filled with the tags of what decides which derivatives there are.
   *
   * @return array{resources: array<string, \Drupal\surface\ResourceBuild\ResourceDefinition>, shapes: array<class-string, \Drupal\surface\ResourceBuild\ShapeDefinition>}
   *   The resources and structures, keyed by name, and the shapes, keyed
   *   by class.
   */
  protected function discover(array $own = [], array &$list_tags = []): array {
    $resources = [];
    $ids = [];
    $found = [];
    foreach ($this->classes['resources'] ?? [] as $class => $module) {
      $found[$class] = [$module, NULL];
    }
    // Decision: see docs/decisions.md#a-plugin-that-is-its-own-structure.
    foreach ($own as $class => ['module' => $module, 'id' => $id]) {
      $found[$class] ??= [$module, $id];
    }
    foreach ($found as $class => [$module, $plugin_id]) {
      $reflection = new \ReflectionClass($class);
      $kind = self::assertResourceClass($reflection, $plugin_id !== NULL);
      $attribute = ($reflection->getAttributes($kind === ResourceDefinition::RESOURCE ? Resource::class : Structure::class)[0] ?? NULL)?->newInstance();
      $id = $attribute->id ?? (string) $plugin_id;
      if (isset($ids[$id])) {
        throw new \LogicException(sprintf(
          'Two classes claim the id "%s": %s and %s. A resource or structure id is what things outside PHP ask for it by, so it names one class.',
          $id,
          $ids[$id],
          $class,
        ));
      }
      $ids[$id] = $class;
      self::assertAccessClass($class, $attribute?->access);
      // What the one plugin hosting it calls itself, which its label and
      // description fall back to.
      // Decision: see
      // docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for.
      $host = $this->plugins?->hostNames($class) ?? ['label' => NULL, 'description' => NULL];
      $resources[$class] = new ResourceDefinition(
        class: $class,
        id: $id,
        module: $module,
        identity: $attribute instanceof Resource ? array_values($attribute->identity) : [],
        storage: $attribute instanceof Resource ? $attribute->storage : NULL,
        access: $attribute?->access,
        refiners: self::refinersOf($reflection),
        label: $attribute?->label,
        description: $attribute?->description,
        kind: $kind,
        version: self::versionOf($class, $attribute->version ?? 1),
        hostLabel: $host['label'],
        hostDescription: $host['description'],
      );
      if ($attribute instanceof Resource && $attribute->storage === NULL && $attribute->identity === [] && $plugin_id === NULL && ($this->plugins?->usedBy($class) ?? []) === []) {
        // Decision: see docs/decisions.md#a-resource-has-storage-or-a-host.
        throw new \LogicException(sprintf(
          '%s carries #[Resource] and names no storage, no identity and no plugin that hosts it: everything it says, a structure says. Give it storage: in #[Resource], or carry #[Structure] and implement %s.',
          $class,
          StructureInterface::class,
        ));
      }
    }
    // Decision: see docs/decisions.md#a-shape-is-a-named-definition.
    $shapes = [];
    foreach ($this->classes['shapes'] ?? [] as $class => $module) {
      $reflection = new \ReflectionClass($class);
      self::assertShapeClass($reflection);
      $id = $reflection->getAttributes(Shape::class)[0]->newInstance()->id;
      if (isset($ids[$id])) {
        throw new \LogicException(sprintf(
          'Two classes claim the id "%s": %s and %s. A shape\'s id names its def in the served contract, beside every resource\'s and structure\'s, so it names one class.',
          $id,
          $ids[$id],
          $class,
        ));
      }
      $ids[$id] = $class;
      $shapes[$class] = new ShapeDefinition($class, $id, (string) $module);
    }
    $operations = [];
    $bases = [];
    foreach ($this->derive($ids, $list_tags) as $id => [$definition, $derived_operations]) {
      $resources[$id] = $definition;
      $operations[$id] = $derived_operations;
      $bases[(string) $definition->base][] = $id;
    }

    // A plugin that is its own structure may carry its operations too.
    $operation_classes = $this->classes['operations'] ?? [];
    foreach ($own as $class => ['module' => $module]) {
      $operation_classes[$class] ??= $module;
    }
    foreach ($operation_classes as $class => $module) {
      $reflection = new \ReflectionClass($class);
      // On the class: an operation whose context is the bare one, the
      // attribute's id and answer and nothing else, so it has no method.
      // Decision: see docs/decisions.md#an-operation-on-the-class.
      foreach ($reflection->getAttributes(Operation::class) as $attribute) {
        $where = sprintf('#[Operation(\'%s\')] on %s', self::idOf($attribute), $class);
        self::assertNoRemovedArgument($attribute, $where);
        $operation = $attribute->newInstance();
        $of = self::operationOwner($class, $resources, $where);
        $operations[$of][] = self::operationDefinition($operation, $of, $class, '', $module, [], $operation->description);
      }
      foreach ($reflection->getMethods() as $method) {
        foreach ($method->getAttributes(Operation::class) as $attribute) {
          $where = sprintf('#[Operation(\'%s\')] on %s::%s()', self::idOf($attribute), $class, $method->getName());
          self::assertNoRemovedArgument($attribute, $where);
          $operation = $attribute->newInstance();
          $of = self::operationOwner($class, $resources, $where);
          if (!$method->isStatic()) {
            throw new \LogicException(sprintf(
              '#[Operation(\'%s\')] on %s::%s() is not static: an operation builds a context before anything is built to ask.',
              $operation->id,
              $class,
              $method->getName(),
            ));
          }
          $definition = self::operationDefinition(
            $operation,
            $of,
            $class,
            $method->getName(),
            $module,
            array_map(OperationParameter::fromReflection(...), $method->getParameters()),
            $operation->description ?? self::summary($method),
          );
          $this->assertStamps($definition);
          $operations[$of][] = $definition;
        }
      }
    }

    $alters = [];
    $shape_alters = [];
    foreach ($this->classes['alters'] ?? [] as $class => $module) {
      $reflection = new \ReflectionClass($class);
      if ($reflection->hasMethod('alterOutputs')) {
        throw ResourceCollectorPass::outputsRefused($class);
      }
      $attribute = $reflection->getAttributes(Alters::class)[0]->newInstance();
      self::assertAltersByClass($class, $attribute->of, $ids, $resources, $shapes, $bases);
      if (isset($shapes[$attribute->of])) {
        self::assertShapeAlter($reflection, $attribute, $shapes[$attribute->of]);
        $shape_alters[$attribute->of][] = new AlterDefinition(class: $class, module: $module, operations: [], refiners: []);
        continue;
      }
      // A deriver's base names everything it derived.
      $named = $bases[$attribute->of] ?? [$attribute->of];
      $alter = new AlterDefinition(
        class: $class,
        module: $module,
        operations: array_values($attribute->operations),
        refiners: self::refinersOf($reflection),
        answersAccess: $reflection->implementsInterface(OperationAccessInterface::class),
      );
      foreach ($named as $altered) {
        if (!isset($bases[$attribute->of]) && self::isUndiscovered($altered, $resources, sprintf('#[Alters] on %s', $class))) {
          continue;
        }
        $alters[$altered][] = $alter;
      }
    }

    $variants = [];
    foreach ($resources as $class => $definition) {
      if ($definition->isDerived()) {
        continue;
      }
      foreach ((new \ReflectionClass($class))->getAttributes(Variant::class) as $attribute) {
        $variant = $attribute->newInstance();
        if (!self::isUndiscovered($variant->of, $resources, sprintf('#[Variant] on %s', $class))) {
          $variants[$variant->of][$variant->at][$variant->for] = $class;
        }
      }
    }

    foreach ($resources as $class => $definition) {
      $resources[$class] = $definition->with($operations[$class] ?? [], $alters[$class] ?? [], $variants[$class] ?? []);
    }
    $this->assertGates($resources, $operations);
    foreach ($shapes as $class => $shape) {
      $shapes[$class] = $shape->withAlters($shape_alters[$class] ?? []);
    }
    return ['resources' => $resources, 'shapes' => $shapes];
  }

  /**
   * Refuses a gate that names what the site does not have.
   *
   * An operation gate names a resource and one of its operations, each
   * discovered; an entity or a field gate names an entity type the entity
   * type manager has, and a field gate one whose entities have fields.
   * Which fields an entity has is not known until one is asked about, so
   * a field the entity lacks refuses when the gate is asked. Without an
   * entity type manager, as a registry built for a test of discovery
   * alone, the entity types are not looked at.
   *
   * Decision: see docs/decisions.md#a-gate-is-borrowed-never-copied.
   *
   * @param array<string, \Drupal\surface\ResourceBuild\ResourceDefinition> $resources
   *   Every resource and structure discovered, by name.
   * @param array<string, \Drupal\surface\ResourceBuild\OperationDefinition[]> $operations
   *   Their operations, by name.
   *
   * @throws \LogicException
   *   Naming the gate, the operation it is on and what is missing.
   */
  protected function assertGates(array $resources, array $operations): void {
    $names = [];
    foreach ($resources as $name => $definition) {
      $names[$definition->id] = (string) $name;
    }
    foreach ($operations as $of => $list) {
      foreach ($list as $operation) {
        foreach ($operation->gates as $gate) {
          $where = sprintf('The gate "%s" on the "%s" operation of %s', $gate->spelled, $operation->id, $resources[$of]->id ?? $of);
          if ($gate->kind === Gate::OPERATION) {
            $name = $names[(string) $gate->resource] ?? NULL;
            if ($name === NULL) {
              throw new \LogicException(sprintf('%s names the resource %s, which the site does not have.', $where, $gate->resource));
            }
            $ids = array_map(static fn (OperationDefinition $one): string => $one->id, $operations[$name] ?? []);
            if (!in_array($gate->operation, $ids, TRUE)) {
              throw new \LogicException(sprintf('%s names the "%s" operation of %s, which it does not have; it has %s.', $where, $gate->operation, $gate->resource, $ids === [] ? 'none' : implode(', ', $ids)));
            }
            continue;
          }
          if ($this->entityTypeManager === NULL) {
            continue;
          }
          $entity_type = $this->entityTypeManager->getDefinition((string) $gate->entityType, FALSE);
          if ($entity_type === NULL) {
            throw new \LogicException(sprintf('%s names the entity type %s, which the site does not have.', $where, $gate->entityType));
          }
          if ($gate->kind === Gate::FIELD && !$entity_type->entityClassImplements(FieldableEntityInterface::class)) {
            throw new \LogicException(sprintf('%s asks for a field of %s, whose entities have no fields.', $where, $gate->entityType));
          }
        }
      }
    }
  }

  /**
   * Refuses a #[Shape] class that cannot be one, and says why.
   *
   * A shape carries #[Shape] alone, implements ShapeInterface and
   * declares define() static. PHP refuses a class implementing the
   * interface with an instance define(), so a class reaching here with one
   * dropped the interface; it is named for what it is.
   *
   * Decision: see docs/decisions.md#a-shape-is-a-named-definition.
   *
   * @param \ReflectionClass $class
   *   The class carrying #[Shape].
   *
   * @throws \LogicException
   *   Naming the class and what it is missing.
   */
  protected static function assertShapeClass(\ReflectionClass $class): void {
    self::assertOneKind($class);
    if ($class->hasMethod('define') && !$class->getMethod('define')->isStatic()) {
      throw new \LogicException(sprintf(
        '%s carries #[Shape] and declares define() as an instance method. What a shape allows belongs to the class: declare it `public static function define(): DataDefinition`, and implement %s.',
        $class->getName(),
        ShapeInterface::class,
      ));
    }
    if (!$class->implementsInterface(ShapeInterface::class)) {
      throw new \LogicException(sprintf('%s carries #[Shape] but does not implement %s.', $class->getName(), ShapeInterface::class));
    }
  }

  /**
   * Reads the id an attribute names first, for a message.
   *
   * @param \ReflectionAttribute $attribute
   *   An #[Operation] or a #[RefinesInput].
   *
   * @return string
   *   Its first argument, by position or by name.
   */
  protected static function idOf(\ReflectionAttribute $attribute): string {
    $arguments = $attribute->getArguments();
    $first = $arguments[0] ?? $arguments['id'] ?? $arguments['key'] ?? '';
    return is_string($first) ? $first : '';
  }

  /**
   * Refuses an attribute argument the module no longer takes.
   *
   * Each is named with what replaced it, before the attribute is
   * instantiated, so the message says more than PHP's unknown parameter.
   *
   * Decision: see docs/decisions.md#the-surface-was-counted-and-cut.
   *
   * @param \ReflectionAttribute $attribute
   *   The attribute.
   * @param string $where
   *   It and the class or method it is on, for the message.
   *
   * @throws \LogicException
   *   When it names an argument that was removed.
   */
  protected static function assertNoRemovedArgument(\ReflectionAttribute $attribute, string $where): void {
    $removed = self::REMOVED_ARGUMENTS[$attribute->getName()] ?? [];
    foreach (array_intersect_key($removed, $attribute->getArguments()) as $name => $instead) {
      throw new \LogicException(sprintf('%s says %s:, which the attribute no longer takes: %s', $where, $name, $instead));
    }
  }

  /**
   * Gets the resource or structure an #[Operation] on a class belongs to.
   *
   * Decision: see docs/decisions.md#the-surface-was-counted-and-cut.
   *
   * @param string $class
   *   The class carrying it.
   * @param array<string, \Drupal\surface\ResourceBuild\ResourceDefinition> $resources
   *   What discovery found.
   * @param string $where
   *   The attribute and where it is, for the message.
   *
   * @return string
   *   The class itself.
   *
   * @throws \LogicException
   *   When the class is no resource or structure.
   */
  protected static function operationOwner(string $class, array $resources, string $where): string {
    if (!isset($resources[$class])) {
      throw new \LogicException(sprintf(
        '%s stands on a class that is no resource or structure. An operation stands on its resource\'s or structure\'s own class.',
        $where,
      ));
    }
    return $class;
  }

  /**
   * Refuses an access class that is an alter.
   *
   * An alter that answers access implements OperationAccessInterface, as
   * an access class does, and is read in its own role: after the owner's
   * answer, its neutral open. Named on `access:` as well, it would answer
   * twice, once in each role.
   *
   * Decision: see
   * docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands.
   *
   * @param string $class
   *   The resource or structure.
   * @param string|null $access
   *   The class its attribute names on `access:`.
   *
   * @throws \LogicException
   *   When the access class carries #[Alters].
   */
  protected static function assertAccessClass(string $class, ?string $access): void {
    if ($access !== NULL && class_exists($access) && (new \ReflectionClass($access))->getAttributes(Alters::class) !== []) {
      throw new \LogicException(sprintf(
        '%s names %s on access:, which carries #[Alters]. An alter that answers access is asked as an alter, after the owner\'s answer; an access class is the owner\'s own. Name a class that carries no #[Alters], or leave the alter to answer as one.',
        $class,
        $access,
      ));
    }
  }

  /**
   * Refuses a class carrying #[Shape] beside #[Resource] or #[Structure].
   *
   * @param \ReflectionClass $class
   *   The class.
   *
   * @throws \LogicException
   *   When it carries both.
   */
  protected static function assertOneKind(\ReflectionClass $class): void {
    if ($class->getAttributes(Shape::class) === []) {
      return;
    }
    foreach ([Resource::class => 'Resource', Structure::class => 'Structure'] as $attribute => $name) {
      if ($class->getAttributes($attribute) !== []) {
        throw new \LogicException(sprintf(
          '%s carries both #[Shape] and #[%s]. A class is one kind: a shape is one value reused at many keys, a %s holds keys of its own.',
          $class->getName(),
          $name,
          strtolower($name),
        ));
      }
    }
  }

  /**
   * Refuses an alter that names a class's owner by its id.
   *
   * A resource, structure or shape with a class is named by its class; an
   * id names only a derived resource, which has none, or a deriver's base.
   * An id that names a class is refused, not skipped as a class that does
   * not load is, since it names something discovered.
   *
   * Decision: see docs/decisions.md#a-class-that-is-not-there.
   *
   * @param class-string $alter
   *   The alter.
   * @param string $of
   *   What its #[Alters] names.
   * @param array<string, string> $ids
   *   Every discovered id, with the class that claimed it.
   * @param array<string, \Drupal\surface\ResourceBuild\ResourceDefinition> $resources
   *   The resources and structures, keyed by name.
   * @param array<class-string, \Drupal\surface\ResourceBuild\ShapeDefinition> $shapes
   *   The shapes, keyed by class.
   * @param array<string, string[]> $bases
   *   The derived ids, keyed by their deriver's base id.
   *
   * @throws \LogicException
   *   When $of is the id of a resource, structure or shape with a class.
   */
  protected static function assertAltersByClass(string $alter, string $of, array $ids, array $resources, array $shapes, array $bases): void {
    if (!isset($ids[$of]) || isset($resources[$of]) || isset($shapes[$of]) || isset($bases[$of])) {
      return;
    }
    throw new \LogicException(sprintf(
      '#[Alters(\'%s\')] on %s names an id; name the owner by its class, %s::class. An id names only a derived resource, which has no class.',
      $of,
      $alter,
      $ids[$of],
    ));
  }

  /**
   * Refuses an alter of a shape that says what no shape takes.
   *
   * A shape is tightened in alterInputs(), the same at every key that uses
   * it: so in every operation, and with no #[RefinesInput] method, since a
   * shape has no siblings to watch and no key to narrow apart from itself.
   *
   * @param \ReflectionClass $class
   *   The alter.
   * @param \Drupal\surface\Resource\Attribute\Alters $attribute
   *   Its attribute.
   * @param \Drupal\surface\ResourceBuild\ShapeDefinition $shape
   *   The shape it names.
   *
   * @throws \LogicException
   *   Naming the alter and what it may not say.
   */
  protected static function assertShapeAlter(\ReflectionClass $class, Alters $attribute, ShapeDefinition $shape): void {
    $problem = match (TRUE) {
      $attribute->operations !== [] => 'names operations, and a shape is the same in every operation of every key that uses it',
      self::refinersOf($class) !== [] => 'declares a #[RefinesInput] method, and a shape has no siblings to watch: tighten it in alterInputs() with narrow()',
      $class->implementsInterface(OperationAccessInterface::class) => sprintf('implements %s, and a shape is one value with no access of its own: answer for the resource or structure that asks for it', OperationAccessInterface::class),
      !$class->implementsInterface(AltersInputsInterface::class) => sprintf('does not implement %s, whose alterInputs() is where a shape is tightened', AltersInputsInterface::class),
      default => NULL,
    };
    if ($problem !== NULL) {
      throw new \LogicException(sprintf('%s alters the shape %s and %s.', $class->getName(), $shape->id, $problem));
    }
  }

  /**
   * Asks every deriver for its resources, and reads each into a definition.
   *
   * @param array<string, class-string> $ids
   *   The ids the classes claimed, which a derived resource may not claim
   *   again; extended with each derived id.
   * @param string[] $list_tags
   *   Filled with the tags of what decides which derivatives there are:
   *   each deriver's own answer (KnowsDerivativeListTagsInterface), or
   *   every tag its derivatives carry.
   *
   * @return array<string, array{0: \Drupal\surface\ResourceBuild\ResourceDefinition, 1: \Drupal\surface\ResourceBuild\OperationDefinition[]}>
   *   Each derived resource's definition, and its operations, keyed by id.
   *
   * @throws \LogicException
   *   When a deriver does not implement ResourceDeriverInterface, a
   *   derivative id holds a colon, or an id is claimed twice.
   */
  protected function derive(array &$ids, array &$list_tags = []): array {
    if ($this->classResolver === NULL) {
      return [];
    }
    $derived = [];
    foreach ($this->getDerivers() as $base => ['class' => $class, 'module' => $module, 'overridden' => $overridden]) {
      $deriver = $this->classResolver->getInstanceFromDefinition($class);
      if (!$deriver instanceof ResourceDeriverInterface) {
        throw new \LogicException(sprintf('%s carries #[DerivesResources] but does not implement %s.', $class, ResourceDeriverInterface::class));
      }
      $derivatives = $deriver->derivatives($overridden);
      if ($deriver instanceof KnowsDerivativeListTagsInterface) {
        $list_tags = Cache::mergeTags($list_tags, $deriver->listCacheTags());
      }
      else {
        foreach ($derivatives as $declared) {
          $list_tags = Cache::mergeTags($list_tags, $declared->cacheTags);
        }
      }
      foreach ($derivatives as $derivative => $declared) {
        $id = $base . ':' . $derivative;
        if (str_contains((string) $derivative, ':')) {
          throw new \LogicException(sprintf(
            '%s::derivatives() answered "%s": a derivative id holds no colon, which separates it from the base.',
            $class,
            $derivative,
          ));
        }
        if (isset($ids[$id])) {
          throw new \LogicException(sprintf('Two classes claim the id "%s": %s and %s.', $id, $ids[$id], $class));
        }
        $ids[$id] = $class;
        $refiners = [];
        foreach ($declared->refiners as $refining) {
          array_push($refiners, ...self::refinersOf(new \ReflectionClass($refining)));
        }
        $derived[$id] = [
          new ResourceDefinition(
            class: NULL,
            id: $id,
            module: $module,
            identity: array_values($declared->identity),
            storage: $declared->storage,
            access: $declared->access,
            refiners: $refiners,
            inputs: $declared->inputs,
            outputs: $declared->outputs,
            base: $base,
            deriver: $class,
            label: $declared->label,
            description: $declared->description,
            notes: $declared->notes,
            cacheTags: array_values($declared->cacheTags),
            kind: $declared->kind(),
          ),
          array_map(function (DerivedOperation $operation) use ($id, $module): OperationDefinition {
            $definition = new OperationDefinition(
              id: $operation->id,
              label: $operation->label,
              resource: $id,
              class: $operation->context->class,
              method: $operation->context->method,
              permission: $operation->permission,
              module: $module,
              parameters: array_map(
                OperationParameter::fromDeclaration(...),
                array_keys($operation->parameters),
                array_values($operation->parameters),
              ),
              context: $operation->context,
              destructive: $operation->destructive,
              readOnly: $operation->readOnly,
              idempotent: $operation->idempotent,
              creates: $operation->creates,
              description: $operation->description,
              deletes: $operation->deletes,
            );
            $this->assertStamps($definition);
            return $definition;
          }, array_values($declared->operations)),
        ];
      }
    }
    return $derived;
  }

  /**
   * Reads one #[Operation] into its definition.
   *
   * @param \Drupal\surface\Resource\Attribute\Operation $operation
   *   The attribute.
   * @param string $of
   *   The resource or structure it is an operation of.
   * @param class-string $class
   *   The class the attribute is on, or whose method carries it.
   * @param string $method
   *   The method, or empty for an operation on the class.
   * @param string $module
   *   The module it is in.
   * @param \Drupal\surface\ResourceBuild\OperationParameter[] $parameters
   *   The method's parameters.
   * @param string|\Stringable|null $description
   *   The description: the attribute's, else the method's docblock
   *   summary.
   *
   * @return \Drupal\surface\ResourceBuild\OperationDefinition
   *   The definition.
   */
  protected static function operationDefinition(Operation $operation, string $of, string $class, string $method, string $module, array $parameters, string|\Stringable|null $description): OperationDefinition {
    return new OperationDefinition(
      id: $operation->id,
      label: $operation->label,
      resource: $of,
      class: $class,
      method: $method,
      permission: $operation->permission,
      module: $module,
      parameters: $parameters,
      destructive: $operation->destructive,
      readOnly: $operation->readOnly,
      idempotent: $operation->idempotent,
      creates: $operation->creates,
      description: $description,
      deletes: $operation->deletes,
      structure: $operation->structure,
      gates: Gate::parseAll($operation->gate, sprintf('the "%s" operation of %s', $operation->id, $class)),
    );
  }

  /**
   * Reads a method's docblock summary: its first paragraph, as one line.
   *
   * What an #[Operation] method that says no description is described
   * by. Not translatable, being a comment; an operation that will be
   * read in another language says `description:` on its attribute.
   *
   * @param \ReflectionMethod $method
   *   The method.
   *
   * @return string|null
   *   The summary, or NULL when there is no docblock or it only inherits.
   */
  protected static function summary(\ReflectionMethod $method): ?string {
    $comment = $method->getDocComment();
    if ($comment === FALSE) {
      return NULL;
    }
    $lines = [];
    foreach (preg_split('/\R/', $comment) ?: [] as $line) {
      $line = trim((string) preg_replace('#^\s*(/\*\*|\*/|\*)#', '', $line));
      if ($line === '' && $lines !== []) {
        break;
      }
      if ($line !== '') {
        $lines[] = $line;
      }
    }
    $summary = implode(' ', $lines);
    return $summary === '' || str_starts_with($summary, '{@inheritdoc}') || str_starts_with($summary, '@') ? NULL : $summary;
  }

  /**
   * Refuses, at discovery, a context that says otherwise than its operation.
   *
   * An operation that needs nothing is built here, as it would be when
   * asked, and its context stamped (OperationDefinition::stamp()), so a
   * context under another id, or one that says otherwise about creating,
   * is refused before anything asks for it. One that needs a subject
   * cannot be built before a caller names it, and is checked when asked.
   * A method that cannot run here, for whatever reason, is left to be
   * reported when it is asked.
   *
   * Decision: see docs/decisions.md#a-contexts-id-is-the-operation-id.
   *
   * @param \Drupal\surface\ResourceBuild\OperationDefinition $operation
   *   The operation.
   *
   * @throws \LogicException
   *   When its context names another id, or says otherwise about creating.
   */
  protected function assertStamps(OperationDefinition $operation): void {
    if ($operation->isBare() || !$operation->needsNothing()) {
      return;
    }
    try {
      $context = $operation->context !== NULL
        ? ($this->classResolver === NULL ? NULL : $operation->context->call($this->classResolver, []))
        : (new \ReflectionMethod($operation->class, $operation->method))->invoke(NULL);
    }
    catch (\Throwable) {
      return;
    }
    if ($context instanceof OperationContext) {
      $operation->stamp($context);
    }
  }

  /**
   * Refuses a discovered class that cannot be what it says, and says what.
   *
   * A class carries the attribute of its kind and implements the
   * interface of the same kind: #[Resource] and ResourceInterface, or
   * #[Structure] and StructureInterface. A plugin that is its own
   * structure carries neither attribute, or #[Structure], and implements
   * StructureInterface. A class carrying both attributes is refused. Both
   * interfaces extend DefinesInputsInterface, whose defineInputs() is
   * static. PHP itself refuses a class that implements the interface
   * with an instance defineInputs(), so the class that reaches here with
   * one is a class that dropped the interface to get it past the
   * compiler; it is named for what it is.
   *
   * @param \ReflectionClass $class
   *   The class carrying #[Resource] or #[Structure], or a plugin that is
   *   its own structure.
   * @param bool $plugin
   *   Whether it was found as a plugin rather than in src/Resource or
   *   src/Structure.
   *
   * @return string
   *   Its kind, ResourceDefinition::RESOURCE or ::STRUCTURE.
   *
   * @throws \LogicException
   *   Naming the class and what it is missing.
   */
  protected static function assertResourceClass(\ReflectionClass $class, bool $plugin): string {
    self::assertOneKind($class);
    $resource = $class->getAttributes(Resource::class) !== [];
    $structure = $class->getAttributes(Structure::class) !== [];
    if ($resource && $structure) {
      throw new \LogicException(sprintf(
        '%s carries both #[Resource] and #[Structure]. A class is one kind: a resource has identity and storage that operations act on, a structure is held by something else.',
        $class->getName(),
      ));
    }
    $kind = $resource ? ResourceDefinition::RESOURCE : ResourceDefinition::STRUCTURE;
    $interface = $resource ? ResourceInterface::class : StructureInterface::class;
    $how = match (TRUE) {
      $resource => 'carries #[Resource]',
      $structure => 'carries #[Structure]',
      default => 'is its own structure, by #[UsesStructure] with no argument,',
    };
    foreach (['defineInputs' => $interface, 'defineOutputs' => DefinesOutputsInterface::class] as $method => $needed) {
      if ($class->hasMethod($method) && !$class->getMethod($method)->isStatic()) {
        throw new \LogicException(sprintf(
          '%s %s and declares %s() as an instance method. What a class declares belongs to the class, and the method takes nothing but the blueprint to fill: declare it `public static function %s(BlueprintInterface $%s): void`, and implement %s.',
          $class->getName(),
          $how,
          $method,
          $method,
          $method === 'defineInputs' ? 'inputs' : 'outputs',
          $needed,
        ));
      }
    }
    if (!$class->implementsInterface($interface)) {
      throw new \LogicException(sprintf('%s %s but does not implement %s.', $class->getName(), $how, $interface));
    }
    return $kind;
  }

  /**
   * Answers whether a referenced resource or structure is simply not here.
   *
   * A class that does not load belongs to a module that is not enabled,
   * so whatever names it has nothing to apply to and is skipped; so does
   * a class that loads and is a resource or structure without being
   * discovered (it carries #[Resource] or #[Structure], or it is a plugin
   * that is its own structure), because a test's autoloader knows every
   * extension, enabled or not. A class that loads and is neither is a
   * mistake.
   *
   * @param string $class
   *   The referenced class.
   * @param array<class-string, \Drupal\surface\ResourceBuild\ResourceDefinition> $resources
   *   The discovered resources and structures.
   * @param string $referrer
   *   What referenced it, for the message.
   *
   * @return bool
   *   TRUE when the reference is to a module that is not enabled.
   *
   * @throws \LogicException
   *   When the class loads and is neither a resource nor a structure.
   */
  protected static function isUndiscovered(string $class, array $resources, string $referrer): bool {
    if (isset($resources[$class])) {
      return FALSE;
    }
    // Decision: see docs/decisions.md#a-class-that-is-not-there.
    $reflection = class_exists($class) ? new \ReflectionClass($class) : NULL;
    if ($reflection !== NULL && $reflection->getAttributes(Resource::class) === [] && $reflection->getAttributes(Structure::class) === [] && $reflection->getAttributes(Shape::class) === [] && !ResourcePluginHooks::isOwnStructure($class)) {
      throw new \LogicException(sprintf(
        '%s names %s, which is no discovered resource or structure: one carries #[Resource] or #[Structure] and lives in its module\'s src/Resource or src/Structure directory, or is a plugin carrying #[UsesStructure] with no argument.',
        $referrer,
        $class,
      ));
    }
    return TRUE;
  }

  /**
   * Reads the #[RefinesInput] methods of one class.
   *
   * @param \ReflectionClass $class
   *   The resource, structure or alter class.
   *
   * @return \Drupal\surface\ResourceBuild\RefinerDefinition[]
   *   The refiners, in method order.
   */
  protected static function refinersOf(\ReflectionClass $class): array {
    $refiners = [];
    foreach ($class->getMethods() as $method) {
      foreach ($method->getAttributes(RefinesInput::class) as $attribute) {
        self::assertNoRemovedArgument($attribute, sprintf('#[RefinesInput(\'%s\')] on %s::%s()', self::idOf($attribute), $class->getName(), $method->getName()));
        $refines = $attribute->newInstance();
        $parameters = $method->getParameters();
        // The account the operation runs as is handed to a last parameter
        // typed for it, and is no watched key.
        // Decision: see
        // docs/decisions.md#nothing-an-operation-runs-reads-the-request.
        $last = count($parameters) > 1 ? $parameters[count($parameters) - 1]->getType() : NULL;
        $account = $last instanceof \ReflectionNamedType && is_a($last->getName(), AccountInterface::class, TRUE);
        if ($account) {
          array_pop($parameters);
        }
        $refiners[] = new RefinerDefinition(
          class: $class->getName(),
          method: $method->getName(),
          key: $refines->key,
          parameters: array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            array_slice($parameters, 1),
          ),
          takesDefinition: $parameters !== [],
          isStatic: $method->isStatic(),
          runtime: $refines->runtime,
          mayNotApply: DoesNotApply::isDeclaredBy($method),
          takesAccount: $account,
        );
      }
    }
    return $refiners;
  }

}
