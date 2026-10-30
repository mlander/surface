<?php

declare(strict_types=1);

namespace Drupal\surface\Reference;

use Drupal\Component\Utility\Html;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataManagerInterface;
use Drupal\surface\SurfaceInterface;

/**
 * What a key that refers to an entity is, and which entities it may name.
 *
 * One place for the three spellings of a reference key, `entity:<type>`,
 * `entity:<type>:<bundle>` and the bare `entity`: the definition each
 * builds, the candidates, the lookup and the check. The candidates are
 * core's entity reference selection handler's, `default:<type>` with the
 * key's bundles as its `target_bundles` and the label as its `sort`, for
 * the account asking, so what a key lists, what the lookup answers and
 * what validation accepts are one answer: the handler's query carries the
 * entity type's access tag, a node's selection leaves unpublished nodes
 * out for an account without "bypass node access", and a user's leaves
 * blocked users out without "administer users".
 *
 * Up to the site's limit, `surface.settings:reference_list_limit`,
 * the candidates are the key's options; above it, the key takes an id and
 * the lookup finds one.
 *
 * Decision: see
 * docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up.
 */
final class References {

  /**
   * The configuration object the limit lives in.
   */
  public const SETTINGS = 'surface.settings';

  /**
   * The setting: the most candidates a key lists as its options.
   */
  public const LIMIT = 'reference_list_limit';

  /**
   * The limit when the setting is not there.
   */
  public const DEFAULT_LIMIT = 50;

  /**
   * The constraint a reference key's id carries.
   */
  public const CONSTRAINT = 'SurfaceValidReference';

  /**
   * The constraint the entity type of a bare `entity` key carries.
   */
  public const TYPE_CONSTRAINT = 'SurfaceReferenceableType';

  /**
   * The most a lookup answers, whatever it is asked for.
   */
  public const LOOKUP_MAX = 50;

  /**
   * The setting the build writes on a key that refers to a content entity.
   *
   * What a config storage stores such a key by: a content entity's id
   * differs between sites, so config holding it would not deploy.
   *
   * Decision: see docs/decisions.md#a-config-object-stores-a-reference-by-uuid.
   */
  public const CONTENT = 'surface_reference_content';

  /**
   * The setting with which an author keeps a content reference as an id.
   *
   * `->setSetting(References::STORED_AS, References::BY_ID)` on the key
   * (on a bare `entity` key, on the key itself): a config storage then
   * stores the id, as it stores a config entity's.
   */
  public const STORED_AS = 'reference_stored_as';

  /**
   * The value of STORED_AS that keeps the id.
   */
  public const BY_ID = 'id';

  /**
   * The cache contexts core's selection of each target type varies by.
   *
   * Node grants, for a node and for a comment (whose selection asks the
   * commented node's access), vary by the account's grants, which core
   * says with `user.node_grants:view`; the rest by permissions alone. A
   * config entity's selection reads no access at all, and is said to vary
   * by permissions in case a module narrows it. A content entity type not
   * listed varies by the account: its handler may read the account itself
   * (a file's offers what the account uploaded).
   *
   * Decision: see
   * docs/decisions.md#every-door-projects-what-the-read-tool-projects.
   */
  public const CONTEXTS = [
    'node' => ['user.permissions', 'user.node_grants:view'],
    'comment' => ['user.permissions', 'user.node_grants:view'],
    'user' => ['user.permissions'],
    'taxonomy_term' => ['user.permissions'],
    'media' => ['user.permissions'],
  ];

  /**
   * Constructs a References service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager, which says what an id key holds.
   * @param \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface $selection
   *   Core's selection handlers.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory, for the limit.
   * @param \Drupal\Core\TypedData\TypedDataManagerInterface $typedDataManager
   *   The typed data manager, which says whether a data type string names
   *   an entity type and a bundle the site has.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface|null $entityRepository
   *   The entity repository, which finds a content entity by its uuid;
   *   NULL where nothing stored is read (a unit test).
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly SelectionPluginManagerInterface $selection,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TypedDataManagerInterface $typedDataManager,
    protected readonly ?EntityRepositoryInterface $entityRepository = NULL,
  ) {}

  /**
   * The limit a caller holds for the rest of a call (withLimit()), if any.
   */
  protected ?int $held = NULL;

  /**
   * Entity types read from the code of modules not installed (knowing()).
   *
   * @var array<string, \Drupal\Core\Entity\EntityTypeInterface>
   */
  protected array $fromCode = [];

  /**
   * Gives a copy that also knows entity types the site does not have yet.
   *
   * For the config schema generator, which builds a module's resources
   * from its code before the module is installed: a key that refers to an
   * entity type the module declares is built as it will be once the
   * module is, its id typed as the entity type keeps it. Only the key's
   * definition is built: nothing lists, looks up or validates an entity
   * of such a type through the copy.
   *
   * @param array<string, \Drupal\Core\Entity\EntityTypeInterface> $entityTypes
   *   The entity types, by id.
   *
   * @return static
   *   The copy; this service is left as it was.
   */
  public function knowing(array $entityTypes): static {
    $copy = clone $this;
    $copy->fromCode = $entityTypes + $this->fromCode;
    return $copy;
  }

  /**
   * Gets the most candidates a key lists as its options.
   *
   * @return int
   *   The setting, or DEFAULT_LIMIT when the site has none; while
   *   withLimit() runs, the limit it holds.
   */
  public function limit(): int {
    if ($this->held !== NULL) {
      return $this->held;
    }
    $limit = $this->configFactory->get(self::SETTINGS)->get(self::LIMIT);
    return is_numeric($limit) ? max(0, (int) $limit) : self::DEFAULT_LIMIT;
  }

  /**
   * Runs a callback as if the limit were another, writing nothing.
   *
   * For a page that shows what a key says past the limit on a site with
   * few entities: the setting is not saved, so nothing cached is cleared
   * and no other request sees it. What the callback builds must be built
   * afresh, and is not kept.
   *
   * @param int $limit
   *   The limit to hold.
   * @param callable $callback
   *   What to run.
   *
   * @return mixed
   *   What the callback returned.
   */
  public function withLimit(int $limit, callable $callback): mixed {
    $was = $this->held;
    $this->held = max(0, $limit);
    try {
      return $callback();
    }
    finally {
      $this->held = $was;
    }
  }

  /**
   * Says what the limit is read from, for an answer that depends on it.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The settings' cacheability.
   */
  public function limitCacheability(): CacheableMetadata {
    return CacheableMetadata::createFromObject($this->configFactory->get(self::SETTINGS));
  }

  /**
   * Answers whether a data type string spells a reference key.
   *
   * @param string $type
   *   What add() was handed.
   *
   * @return bool
   *   TRUE for `entity` and `entity:<type>[:<bundle>]`.
   */
  public static function spells(string $type): bool {
    return $type === 'entity' || str_starts_with($type, 'entity:');
  }

  /**
   * Builds the key a data type string names: one entity's id.
   *
   * `entity:<type>` is an id of that type and `entity:<type>:<bundle>` an
   * id of that type and bundle, typed as the entity type's id key holds
   * it, an integer or a string, and carrying the constraint that names
   * the type and the bundle, which the options resolver reads as the
   * candidates and its validator holds a value to.
   *
   * @param string $key
   *   The key, for the messages.
   * @param string $type
   *   The data type string.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The key's definition.
   *
   * @throws \LogicException
   *   When the string names an entity type typed data does not know, or no
   *   entity type at all.
   */
  public function definition(string $key, string $type): DataDefinition {
    $parts = explode(':', $type);
    if (count($parts) < 2 || count($parts) > 3 || $parts[1] === '' || ($parts[2] ?? NULL) === '') {
      throw new \LogicException(sprintf('add("%s", "%s") names no entity type: a reference key is "entity:<entity type>" or "entity:<entity type>:<bundle>", or "entity" for a choice of entity type and id.', $key, $type));
    }
    // Typed data derives `entity:<type>` for every entity type. A bundle
    // is site state, which may not be there yet: the key then offers
    // nothing until it is.
    if (!isset($this->fromCode[$parts[1]]) && $this->typedDataManager->getDefinition('entity:' . $parts[1], FALSE) === NULL) {
      throw new \LogicException(sprintf('add("%s", "%s") names an entity type this site does not have: typed data has no "entity:%s" data type.', $key, $type, $parts[1]));
    }
    $definition = DataDefinition::create($this->idType($parts[1]));
    $definition->addConstraint(self::CONSTRAINT, array_filter([
      'entityTypeId' => $parts[1],
      'bundles' => isset($parts[2]) ? [$parts[2]] : [],
    ]));
    if ($this->isContent($parts[1])) {
      $definition->setSetting(self::CONTENT, TRUE);
    }
    return $definition;
  }

  /**
   * Answers whether an entity type is a content entity type.
   *
   * @param string $entity_type_id
   *   The entity type.
   *
   * @return bool
   *   TRUE for a content entity type the site has, or one read from the
   *   code of a module not installed (knowing()).
   */
  public function isContent(string $entity_type_id): bool {
    $entity_type = $this->fromCode[$entity_type_id] ?? $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    return $entity_type !== NULL && $entity_type->entityClassImplements(ContentEntityInterface::class);
  }

  /**
   * Answers whether a config storage stores a key by its entity's uuid.
   *
   * A key that refers to one content entity type, unless its author kept
   * the id (STORED_AS). A bare `entity` key decides per value, by the
   * entity type it holds (ReferenceStorageLayout).
   *
   * Decision: see docs/decisions.md#a-config-object-stores-a-reference-by-uuid.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return bool
   *   TRUE when its stored value is the uuid.
   */
  public static function storedByUuid(DataDefinitionInterface $definition): bool {
    return $definition->getSetting(self::CONTENT) === TRUE && $definition->getSetting(self::STORED_AS) !== self::BY_ID;
  }

  /**
   * Gives the uuid of the content entity an id names.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param mixed $id
   *   The id.
   *
   * @return string|null
   *   The uuid, or NULL when no entity of that type has the id.
   */
  public function uuidOf(string $entity_type_id, mixed $id): ?string {
    if (!is_int($id) && !is_string($id)) {
      return NULL;
    }
    try {
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
    }
    catch (PluginException) {
      return NULL;
    }
    return $entity instanceof EntityInterface ? $entity->uuid() : NULL;
  }

  /**
   * Finds the content entity a stored value names.
   *
   * By its uuid. A value that is not a uuid is read as an id, which is
   * what a key held before its config storage stored uuids, until the
   * next write stores the uuid; the uuid's config schema type still
   * refuses it, so config validation names each one left.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param mixed $stored
   *   The stored value.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity, or NULL when the value names none: nothing stored, or an
   *   entity deleted or not on this site yet.
   */
  public function stored(string $entity_type_id, mixed $stored): ?EntityInterface {
    try {
      if (is_string($stored) && Uuid::isValid($stored)) {
        return $this->entityRepository?->loadEntityByUuid($entity_type_id, $stored);
      }
      if ((is_int($stored) || is_string($stored)) && $stored !== '') {
        return $this->entityTypeManager->getStorage($entity_type_id)->load($stored);
      }
    }
    catch (PluginException | EntityStorageException) {
      // An entity type the site no longer has, or one with no uuid.
    }
    return NULL;
  }

  /**
   * Gets the data type an entity type's id travels as.
   *
   * @param string $entity_type_id
   *   The entity type.
   *
   * @return string
   *   'integer' where a content entity type's id field is one, else
   *   'string'.
   */
  public function idType(string $entity_type_id): string {
    $entity_type = $this->fromCode[$entity_type_id] ?? $this->entityTypeManager->getDefinition($entity_type_id);
    if (!$entity_type->entityClassImplements(FieldableEntityInterface::class)) {
      return 'string';
    }
    $key = (string) $entity_type->getKey('id');
    if (isset($this->fromCode[$entity_type_id])) {
      // Not on the site yet: its class says what its id field is.
      $class = $entity_type->getClass();
      assert(is_subclass_of($class, FieldableEntityInterface::class));
      $fields = $class::baseFieldDefinitions($entity_type);
    }
    else {
      $fields = $this->entityFieldManager->getBaseFieldDefinitions($entity_type_id);
    }
    return isset($fields[$key]) && $fields[$key]->getType() === 'integer' ? 'integer' : 'string';
  }

  /**
   * Reads which entity type and bundles a key's definition refers to.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return array{entityType: string, bundles: string[]}|null
   *   The entity type and bundles its constraint names, or NULL for a key
   *   that refers to no one entity type.
   */
  public static function target(DataDefinitionInterface $definition): ?array {
    $options = $definition->getConstraints()[self::CONSTRAINT] ?? NULL;
    if (!is_array($options) || !is_string($options['entityTypeId'] ?? NULL) || $options['entityTypeId'] === '') {
      return NULL;
    }
    return [
      'entityType' => $options['entityTypeId'],
      'bundles' => array_values(array_map('strval', (array) ($options['bundles'] ?? []))),
    ];
  }

  /**
   * Finds what a key of a built resource refers to, by its path.
   *
   * What the lookup asks: the key at the path, through attached children,
   * as the resource was built for the account, so a key its access left
   * out is not found. A bare `entity` key, or its id, refers to the entity
   * type it is handed, once its own entity type key allows it: the id is
   * refined for it, as the form refines it when the entity type is chosen.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The resource, built.
   * @param string[] $path
   *   The keys, from the top: `['venue']`, `['thing', 'id']`.
   * @param string|null $entity_type_id
   *   For a bare `entity` key, the entity type to look an id up of.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The key's definition, refined for the entity type handed to a bare
   *   key, which target() reads; NULL when no key at the path refers to
   *   one entity type.
   */
  public function definitionAt(SurfaceInterface $surface, array $path, ?string $entity_type_id = NULL): ?DataDefinitionInterface {
    $name = array_shift($path);
    $entry = $name === NULL ? NULL : $surface->getDefinitions()->entry($name);
    if ($entry === NULL) {
      return NULL;
    }
    $child = $entry->attachment?->child;
    if ($child !== NULL && $path === [] && $child->getDefinitions()->has('id')) {
      // The bare key itself names its id.
      $path = ['id'];
    }
    if ($path !== []) {
      return $child === NULL ? NULL : $this->definitionAt($child, $path, $entity_type_id);
    }
    if (static::target($entry->definition) !== NULL) {
      return $entry->definition;
    }
    if ($entry->definition->getSetting(EntityReferenceStructure::TARGETS) === NULL) {
      return NULL;
    }
    $type = $surface->getDefinition('entity_type')?->getConstraints()[self::TYPE_CONSTRAINT] ?? NULL;
    if ($entity_type_id === NULL || !is_array($type)) {
      return NULL;
    }
    [$allowed] = $this->entityTypes($type['interface'] ?? NULL, $type['targets'] ?? []);
    if (!isset($allowed[$entity_type_id])) {
      return NULL;
    }
    $refined = $surface->refine(['entity_type' => $entity_type_id], FALSE)->getDefinition($name);
    return $refined !== NULL && static::target($refined) !== NULL ? $refined : NULL;
  }

  /**
   * Gets core's selection handler for an entity type and its bundles.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string[] $bundles
   *   The bundles, or none for any.
   *
   * @return \Drupal\Core\Entity\EntityReferenceSelection\SelectionInterface
   *   The `default` handler for the type, configured for the bundles and
   *   sorted by label, for the account asking.
   */
  public function handler(string $entity_type_id, array $bundles = []): SelectionInterface {
    return $this->selection->getInstance(['target_type' => $entity_type_id] + $this->selectionSettings($entity_type_id, $bundles));
  }

  /**
   * Gets the selection settings for an entity type and its bundles.
   *
   * What the handler is configured with, and what core's
   * `entity_autocomplete` element hands its autocomplete route.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string[] $bundles
   *   The bundles, or none for any.
   *
   * @return array{target_bundles: array<string, string>|null, sort: array{field: string, direction: string}}
   *   The bundles as `target_bundles`, NULL for any, and the label as the
   *   `sort`, where the entity type has one.
   */
  public function selectionSettings(string $entity_type_id, array $bundles = []): array {
    $label = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('label');
    return [
      'target_bundles' => $bundles === [] ? NULL : array_combine($bundles, $bundles),
      'sort' => is_string($label) && $label !== '' ? ['field' => $label, 'direction' => 'ASC'] : [
        'field' => '_none',
        'direction' => 'ASC',
      ],
    ];
  }

  /**
   * Counts the candidates for the account asking.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string[] $bundles
   *   The bundles, or none for any.
   *
   * @return int
   *   How many there are.
   */
  public function count(string $entity_type_id, array $bundles = []): int {
    return $this->handler($entity_type_id, $bundles)->countReferenceableEntities();
  }

  /**
   * Answers whether there are few enough candidates to list.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string[] $bundles
   *   The bundles, or none for any.
   *
   * @return bool
   *   TRUE up to the limit.
   */
  public function listable(string $entity_type_id, array $bundles = []): bool {
    return $this->count($entity_type_id, $bundles) <= $this->limit();
  }

  /**
   * Lists the candidates with their labels, for the account asking.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string[] $bundles
   *   The bundles, or none for any.
   * @param string|null $match
   *   Text the label contains, or NULL for every candidate.
   * @param int $limit
   *   The most to answer, 0 for all.
   *
   * @return array<int|string, string>
   *   Each candidate's label, plain text, by id, sorted by label.
   */
  public function candidates(string $entity_type_id, array $bundles = [], ?string $match = NULL, int $limit = 0): array {
    $candidates = [];
    foreach ($this->handler($entity_type_id, $bundles)->getReferenceableEntities($match, 'CONTAINS', $limit) as $labels) {
      foreach ($labels as $id => $label) {
        $candidates[$id] = static::plain($label);
      }
    }
    // Sorted here too: a handler's query sorts within a bundle, and a
    // handler may add rows of its own (a user's, the anonymous account).
    uksort($candidates, static fn (int|string $a, int|string $b): int => strnatcasecmp($candidates[$a], $candidates[$b]) ?: strnatcmp((string) $a, (string) $b));
    return $candidates;
  }

  /**
   * Looks candidates up by what their label contains.
   *
   * What the resource's `:lookup` answers and the React page's
   * autocomplete asks: the same handler, so the same access filtering, as
   * the key's options and its check.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string[] $bundles
   *   The bundles, or none for any.
   * @param string $match
   *   Text the label contains; empty for the first candidates.
   * @param int $limit
   *   The most to answer, at most LOOKUP_MAX.
   * @param string|null $as
   *   The data type of the key the ids are for, 'integer' or 'string';
   *   NULL for the entity type's own (idType()).
   *
   * @return array<int, array{id: int|string, label: string}>
   *   The candidates found, sorted by label, each id in the key's type.
   */
  public function lookup(string $entity_type_id, array $bundles, string $match, int $limit, ?string $as = NULL): array {
    $limit = max(1, min(self::LOOKUP_MAX, $limit));
    $id_type = $as ?? $this->idType($entity_type_id);
    $found = [];
    foreach ($this->candidates($entity_type_id, $bundles, $match === '' ? NULL : $match, $limit) as $id => $label) {
      $found[] = ['id' => $id_type === 'integer' ? (int) $id : (string) $id, 'label' => $label];
    }
    return $found;
  }

  /**
   * Answers whether the account asking may refer to an entity.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string[] $bundles
   *   The bundles, or none for any.
   * @param int|string $id
   *   The id.
   *
   * @return bool
   *   TRUE when the selection handler counts it among the candidates.
   */
  public function validates(string $entity_type_id, array $bundles, int|string $id): bool {
    return $this->handler($entity_type_id, $bundles)->validateReferenceableEntities([$id]) !== [];
  }

  /**
   * Says what a list of an entity type's candidates varies by.
   *
   * @param string $entity_type_id
   *   The entity type.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The type's list tags, the contexts its selection reads (CONTEXTS),
   *   and the limit's, since whether it is listed at all depends on it.
   */
  public function cacheability(string $entity_type_id): CacheableMetadata {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    return (new CacheableMetadata())
      ->addCacheTags($entity_type->getListCacheTags())
      ->addCacheContexts($this->contexts($entity_type))
      ->addCacheableDependency($this->limitCacheability());
  }

  /**
   * Gets the cache contexts an entity type's selection varies by.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   *
   * @return string[]
   *   The contexts.
   */
  public function contexts(EntityTypeInterface $entity_type): array {
    if (isset(self::CONTEXTS[$entity_type->id()])) {
      return self::CONTEXTS[$entity_type->id()];
    }
    return $entity_type->entityClassImplements(ConfigEntityInterface::class) ? ['user.permissions'] : ['user'];
  }

  /**
   * Lists the entity types a bare `entity` key may name, for the account.
   *
   * Every entity type that is not internal, of the interface when one is
   * named, among the targets when any are named, and of which the account
   * may refer to at least one, its targets' bundles counted.
   *
   * @param string|null $interface
   *   ContentEntityInterface or ConfigEntityInterface, or NULL for any.
   * @param array<string, string[]> $targets
   *   The entity types allowed, each with its bundles (none for any); an
   *   empty list for every entity type.
   *
   * @return array{0: array<string, string|\Stringable>, 1: \Drupal\Core\Cache\CacheableMetadata}
   *   Each entity type's label by id, sorted by id, and what the list
   *   varies by.
   */
  public function entityTypes(?string $interface, array $targets): array {
    $cacheability = (new CacheableMetadata())->addCacheTags(['entity_types']);
    $types = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $entity_type) {
      if ($entity_type->isInternal() || ($interface !== NULL && !$entity_type->entityClassImplements($interface)) || ($targets !== [] && !array_key_exists($id, $targets))) {
        continue;
      }
      $cacheability->addCacheableDependency($this->cacheability($id));
      if ($this->count($id, array_values($targets[$id] ?? [])) > 0) {
        $types[$id] = $entity_type->getLabel();
      }
    }
    ksort($types);
    return [$types, $cacheability];
  }

  /**
   * Reads a label as plain text.
   *
   * @param mixed $label
   *   A label the selection handler escaped, or an entity type's.
   *
   * @return string
   *   The text, tags stripped and entities decoded.
   */
  protected static function plain(mixed $label): string {
    return trim(Html::decodeEntities(strip_tags((string) $label)));
  }

}
