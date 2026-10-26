<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Storage;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Pipeline\Storage\BaseFieldOverrideStorage;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Storage\ConfigEntityStorage;
use Drupal\data_surface\Storage\SchemaViolations;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodePreviewMode;
use Drupal\node\NodeTypeInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Where a content type's values live: the entity, and its base fields.
 *
 * A config entity storage (ConfigEntityStorage) for the node type, by its
 * machine name, plus a second destination it rehearses and writes beside
 * the entity:
 *
 * - The node type config entity, for the keys it owns: plain properties
 *   but for the two booleans core sets through their setters, and the
 *   third party settings another module mounted on the resource, which
 *   arrive under third_party_settings.<module> in the layout they are
 *   stored in (a contributor's storage layout has already been applied)
 *   and are written with setThirdPartySetting(), where core keeps them.
 *   An empty description or help is stored as none, as core's own form
 *   stores it, and each is read back through its getter.
 * - Base field overrides on the bundle, for the title label and the three
 *   workflow defaults, which are not stored on the node type at all.
 *   Read, planned and written by the engine's BaseFieldOverrideStorage,
 *   which compares before writing, as core's form does, so a value that
 *   did not move writes no override. Written after the node type, in the
 *   order core's own content type form writes them, because they belong
 *   to the bundle.
 *
 * The node type's keys are held to core's config schema, node.type.*,
 * which is fully validatable: it says which of them always hold a value
 * (mayClear()), and the build refuses the resource where it advertises
 * more than that schema (widerThanSchema()).
 */
final class NodeTypeStorage extends ConfigEntityStorage {

  /**
   * {@inheritdoc}
   */
  public const ENTITY_TYPE_ID = 'node_type';

  /**
   * {@inheritdoc}
   */
  public const PROPERTIES = [
    'name' => 'name',
    'type' => 'type',
    'description' => 'description',
    'help' => 'help',
    'preview_mode' => 'preview_mode',
    'new_revision' => [self::METHOD => 'setNewRevision'],
    'display_submitted' => [self::METHOD => 'setDisplaySubmitted'],
  ];

  /**
   * The entity type whose bundles this storage writes.
   */
  protected const BUNDLE_OF = 'node';

  /**
   * The resource's keys stored as base field overrides, and where.
   */
  protected const OVERRIDES_MAP = [
    'title_label' => ['field' => 'title', 'property' => BaseFieldOverrideStorage::LABEL],
    'status' => ['field' => 'status', 'property' => BaseFieldOverrideStorage::DEFAULT_VALUE],
    'promote' => ['field' => 'promote', 'property' => BaseFieldOverrideStorage::DEFAULT_VALUE],
    'sticky' => ['field' => 'sticky', 'property' => BaseFieldOverrideStorage::DEFAULT_VALUE],
  ];

  /**
   * The prepared key holding the node type's exported array.
   */
  public const NODE_TYPE = 'node_type';

  /**
   * The prepared key holding the moved base field overrides.
   *
   * Each override that would be written, as its exported array, keyed by
   * base field name; NULL for one whose stored override would be removed.
   */
  public const OVERRIDES = 'base_field_overrides';

  /**
   * Constructs a NodeTypeStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, autowired, which holds what is rehearsed
   *   to the config schema.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, autowired.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager, autowired, which answers what the node
   *   base fields say for a bundle.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    TypedConfigManagerInterface $typedConfig,
    TranslationInterface $string_translation,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct($entityTypeManager, $typedConfig, $string_translation);
  }

  /**
   * {@inheritdoc}
   *
   * Every key the node type stores, the two set through setters included:
   * node.type.* states them all.
   */
  protected function configSchemaPaths(): array {
    $keys = array_keys(static::properties());
    return array_combine($keys, $keys);
  }

  /**
   * {@inheritdoc}
   *
   * Every key, for a content type the context names, read through its
   * getters; its title label and workflow defaults from its overrides.
   */
  protected function fromEntity(ConfigEntityInterface $entity): array {
    assert($entity instanceof NodeTypeInterface);
    $overrides = $this->overrides((string) $entity->id())->values();
    $values = [
      'name' => $entity->label(),
      'type' => $entity->id(),
      'description' => $entity->getDescription(),
      'title_label' => $overrides['title_label'],
      'preview_mode' => self::previewModeOf($entity),
      'help' => $entity->getHelp(),
    ] + $overrides;
    $values['new_revision'] = $entity->shouldCreateNewRevision();
    $values['display_submitted'] = $entity->displaySubmitted();
    foreach ($entity->getThirdPartyProviders() as $provider) {
      $values[self::THIRD_PARTY][$provider] = $entity->getThirdPartySettings($provider);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   *
   * Preview mode is set as the integer the schema stores: its setter wants
   * the enum and deprecates the integer, and the LabeledChoice has already
   * refused anything else. The schema refuses an empty description or
   * help text and stores none as NULL, which is what core's own content
   * type form turns an empty one into before saving.
   */
  protected function toEntity(ConfigEntityInterface $entity, array $values): void {
    foreach (['new_revision', 'display_submitted'] as $key) {
      if (array_key_exists($key, $values)) {
        $values[$key] = (bool) $values[$key];
      }
    }
    parent::toEntity($entity, $values);
    foreach (['description', 'help'] as $key) {
      if (is_string($entity->get($key)) && trim($entity->get($key)) === '') {
        $entity->set($key, NULL);
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * The content type it would save, unsaved, and each base field override
   * that would move, held to their config schemas, every violation of
   * either collected before one is thrown. A name the resource only limited
   * in length is refused here for the line break node.type.* forbids,
   * filed under the resource's key, before anything is written.
   *
   * What comes back is what config storage would be handed: the node
   * type's exported array under NODE_TYPE, and each moved override's,
   * keyed by base field name, under OVERRIDES.
   */
  public function prepare(OperationContext $context, array $values, AccountInterface $account): array {
    $violations = [];
    $record = [];
    try {
      $record = parent::prepare($context, $values, $account);
    }
    catch (StorageViolationsException $e) {
      $violations = iterator_to_array($e->getViolations(), FALSE);
    }
    $bundle = (string) ($context->known['type'] ?? $values['type'] ?? '');
    $plan = $this->overrides($bundle)->plan(array_intersect_key($values, self::OVERRIDES_MAP));
    $override_type = $this->entityTypeManager->getDefinition('base_field_override');
    $prefix = $override_type instanceof ConfigEntityTypeInterface ? $override_type->getConfigPrefix() : 'core.base_field_override';
    foreach ($plan[BaseFieldOverrideStorage::SAVE] as $name => $exported) {
      $paths = [];
      foreach (self::OVERRIDES_MAP as $key => $target) {
        if ($target['field'] === $name) {
          $paths[$target['property']] = $key;
        }
      }
      $found = SchemaViolations::collect($this->typedConfig, $prefix . '.' . $exported['id'], $exported, $paths);
      array_push($violations, ...iterator_to_array($found, FALSE));
    }
    if ($violations !== []) {
      throw new StorageViolationsException(new ViolationSet($violations));
    }
    return [
      self::NODE_TYPE => $record,
      self::OVERRIDES => $plan[BaseFieldOverrideStorage::SAVE] + array_fill_keys($plan[BaseFieldOverrideStorage::DELETE], NULL),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The node type first and its overrides after, in the order core's own
   * content type form writes them, because the overrides belong to the
   * bundle.
   */
  public function commit(OperationContext $context, array $prepared, AccountInterface $account): void {
    $record = $prepared[self::NODE_TYPE] ?? NULL;
    if (!is_array($record) || !isset($record['type'])) {
      throw new \InvalidArgumentException('The prepared values did not come from a content type storage.');
    }
    parent::commit($context, $record, $account);
    $overrides = is_array($prepared[self::OVERRIDES] ?? NULL) ? $prepared[self::OVERRIDES] : [];
    if ($overrides !== []) {
      $this->overrides((string) $record['type'])->write([
        BaseFieldOverrideStorage::SAVE => array_filter($overrides, 'is_array'),
        BaseFieldOverrideStorage::DELETE => array_keys(array_filter($overrides, 'is_null')),
      ]);
    }
  }

  /**
   * Gets the storage for a content type's base field overrides.
   *
   * @param string $bundle
   *   The content type, which need not exist yet.
   *
   * @return \Drupal\data_surface\Pipeline\Storage\BaseFieldOverrideStorage
   *   The storage, for the title label and the workflow defaults.
   */
  protected function overrides(string $bundle): BaseFieldOverrideStorage {
    return new BaseFieldOverrideStorage($this->entityFieldManager, self::BUNDLE_OF, $bundle, self::OVERRIDES_MAP);
  }

  /**
   * Reads a content type's preview mode as the integer it is stored as.
   *
   * NodeTypeInterface still only comments the $returnAsInt parameter in,
   * so the entity class is the one that can be asked for the enum without
   * the deprecated integer return; anything else implementing the
   * interface is read from the stored property instead.
   *
   * @param \Drupal\node\NodeTypeInterface $type
   *   The content type.
   *
   * @return int
   *   The preview mode.
   */
  protected static function previewModeOf(NodeTypeInterface $type): int {
    return $type instanceof NodeType
      ? $type->getPreviewMode(FALSE)->value
      : NodePreviewMode::from((int) $type->get('preview_mode'))->value;
  }

}
