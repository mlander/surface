<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Target;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Pipeline\TargetViolationsException;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Surface\SurfaceTargetInterface;
use Drupal\data_surface\Target\BaseFieldOverrideTarget;
use Drupal\data_surface\Target\SchemaViolations;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodePreviewMode;
use Drupal\node\NodeTypeInterface;

/**
 * Where a content type's values live: the entity, and its base fields.
 *
 * Loads by the identity the context knows, so the context never carries
 * an entity: the machine name. Creates when the context creates, and
 * updates the content type the identity names otherwise.
 *
 * Two destinations, rehearsed together in prepare() and held to the
 * config schema, then written in the order core's own content type form
 * writes them, because the second belongs to the first:
 *
 * - The node type config entity, for the keys it owns, and for the
 *   third party settings another module mounted on the surface, which
 *   arrive here under third_party_settings.<module> in the shape they
 *   are stored in — a contributor's storage shape has already been
 *   applied by the time they arrive — and are written with
 *   setThirdPartySetting(), where core keeps them.
 * - Base field overrides on the bundle, for the title label and the three
 *   workflow defaults, which are not stored on the node type at all.
 *   Read, planned and written by the engine's BaseFieldOverrideTarget,
 *   which compares before writing, as core's form does, so a value that
 *   did not move writes no override.
 */
final class NodeTypeTarget implements SurfaceTargetInterface {

  /**
   * The entity type whose bundles this target writes.
   */
  protected const ENTITY_TYPE_ID = 'node';

  /**
   * The surface key the mounted settings arrive under.
   */
  protected const THIRD_PARTY = 'third_party_settings';

  /**
   * The surface keys stored as base field overrides, and where.
   */
  protected const OVERRIDES_MAP = [
    'title_label' => ['field' => 'title', 'property' => BaseFieldOverrideTarget::LABEL],
    'status' => ['field' => 'status', 'property' => BaseFieldOverrideTarget::DEFAULT_VALUE],
    'promote' => ['field' => 'promote', 'property' => BaseFieldOverrideTarget::DEFAULT_VALUE],
    'sticky' => ['field' => 'sticky', 'property' => BaseFieldOverrideTarget::DEFAULT_VALUE],
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
   * Constructs a NodeTypeTarget.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager, autowired, which answers what the node
   *   base fields say for a bundle.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, autowired, which holds what is rehearsed
   *   to the config schema.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly TypedConfigManagerInterface $typedConfig,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Every key, for a content type the context names; nothing for one it
   * does not, which a creating context never does, so a new content type
   * starts from what the surface declares.
   */
  public function load(SurfaceContext $context): array {
    $type = $this->type($context);
    if ($type === NULL) {
      return [];
    }
    $overrides = $this->overrides((string) $type->id())->values();
    $values = [
      'name' => $type->label(),
      'type' => $type->id(),
      'description' => $type->getDescription(),
      'title_label' => $overrides['title_label'],
      'preview_mode' => self::previewModeOf($type),
      'help' => $type->getHelp(),
    ] + $overrides;
    $values['new_revision'] = $type->shouldCreateNewRevision();
    $values['display_submitted'] = $type->displaySubmitted();
    foreach ($type->getThirdPartyProviders() as $provider) {
      $values[self::THIRD_PARTY][$provider] = $type->getThirdPartySettings($provider);
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   *
   * Builds the content type it would save, unsaved — a copy of the one
   * the context names, or a new one — and the base field overrides that
   * would move, each the unsaved override itself, and holds them to the
   * config schema: the node type's is fully validatable, so a name the
   * surface only limited in length is refused here for the line break
   * the schema forbids, filed under the surface key, before anything is
   * written. For a config entity that is also its entity validation:
   * its typed data adapter validates the same config schema.
   *
   * What comes back is what config storage would be handed: the node
   * type's exported array under NODE_TYPE, and each moved override's,
   * keyed by base field name, under OVERRIDES.
   */
  public function prepare(SurfaceContext $context, array $values): array {
    $type = $this->type($context);
    if ($type === NULL) {
      if (!$context->creates) {
        throw new \LogicException(sprintf('The "%s" context edits a content type that does not exist.', $context->operation));
      }
      $type = $this->entityTypeManager->getStorage('node_type')->create(['type' => $values['type'] ?? '']);
    }
    else {
      // The stored entity is read, never written: everything below
      // happens to a copy.
      $type = clone $type;
    }
    // Plain properties, named as they are stored. Preview mode is set as
    // the integer the schema stores: its setter wants the enum and
    // deprecates the integer, and the LabeledChoice has already refused
    // anything else.
    foreach (['name', 'description', 'help', 'preview_mode'] as $key) {
      if (array_key_exists($key, $values)) {
        $type->set($key, $values[$key]);
      }
    }
    // The schema refuses an empty description or help text and stores
    // none as NULL, which is what core's own content type form turns an
    // empty one into before saving.
    foreach (['description', 'help'] as $key) {
      if (is_string($type->get($key)) && trim($type->get($key)) === '') {
        $type->set($key, NULL);
      }
    }
    if (array_key_exists('new_revision', $values)) {
      $type->setNewRevision((bool) $values['new_revision']);
    }
    if (array_key_exists('display_submitted', $values)) {
      $type->setDisplaySubmitted((bool) $values['display_submitted']);
    }
    foreach (is_array($values[self::THIRD_PARTY] ?? NULL) ? $values[self::THIRD_PARTY] : [] as $provider => $settings) {
      foreach (is_array($settings) ? $settings : [] as $key => $setting) {
        $type->setThirdPartySetting((string) $provider, (string) $key, $setting);
      }
    }
    $record = $type->toArray();
    $found = SchemaViolations::collect($this->typedConfig, $type->getConfigDependencyName(), $record, [
      'name' => 'name',
      'type' => 'type',
      'description' => 'description',
      'help' => 'help',
      'preview_mode' => 'preview_mode',
      'new_revision' => 'new_revision',
      'display_submitted' => 'display_submitted',
      self::THIRD_PARTY => self::THIRD_PARTY,
    ]);
    $violations = iterator_to_array($found, FALSE);
    $plan = $this->overrides((string) $type->id())->plan(array_intersect_key($values, self::OVERRIDES_MAP));
    $override_type = $this->entityTypeManager->getDefinition('base_field_override');
    $prefix = $override_type instanceof ConfigEntityTypeInterface ? $override_type->getConfigPrefix() : 'core.base_field_override';
    foreach ($plan[BaseFieldOverrideTarget::SAVE] as $name => $exported) {
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
      throw new TargetViolationsException(new ViolationSet($violations));
    }
    return [
      self::NODE_TYPE => $record,
      self::OVERRIDES => $plan[BaseFieldOverrideTarget::SAVE] + array_fill_keys($plan[BaseFieldOverrideTarget::DELETE], NULL),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The node type first and its overrides after, in the order core's own
   * content type form writes them, because the overrides belong to the
   * bundle.
   */
  public function commit(SurfaceContext $context, array $prepared): void {
    $record = $prepared[self::NODE_TYPE] ?? NULL;
    if (!is_array($record) || !isset($record['type'])) {
      throw new \InvalidArgumentException('The prepared values did not come from a content type target.');
    }
    $type = $this->type($context) ?? $this->entityTypeManager->getStorage('node_type')->load($record['type']);
    if ($type === NULL) {
      if (!$context->creates) {
        throw new \LogicException(sprintf('The "%s" context edits a content type that does not exist.', $context->operation));
      }
      // The uuid the rehearsal handed out, so what was previewed is what
      // is stored.
      $identity = array_intersect_key($record, array_flip(['type', 'uuid']));
      $type = $this->entityTypeManager->getStorage('node_type')->create($identity);
    }
    // What the entity owns of the rehearsed record. Its identity and
    // uuid are its own, and its dependencies are recalculated on save.
    foreach (array_diff_key($record, array_flip(['uuid', 'type', 'dependencies', '_core'])) as $property => $value) {
      $type->set((string) $property, $value);
    }
    $type->save();
    $overrides = is_array($prepared[self::OVERRIDES] ?? NULL) ? $prepared[self::OVERRIDES] : [];
    if ($overrides !== []) {
      $this->overrides((string) $type->id())->write([
        BaseFieldOverrideTarget::SAVE => array_filter($overrides, 'is_array'),
        BaseFieldOverrideTarget::DELETE => array_keys(array_filter($overrides, 'is_null')),
      ]);
    }
  }

  /**
   * Gets the target for a content type's base field overrides.
   *
   * @param string $bundle
   *   The content type, which need not exist yet.
   *
   * @return \Drupal\data_surface\Target\BaseFieldOverrideTarget
   *   The target, for the title label and the workflow defaults.
   */
  protected function overrides(string $bundle): BaseFieldOverrideTarget {
    return new BaseFieldOverrideTarget($this->entityFieldManager, self::ENTITY_TYPE_ID, $bundle, self::OVERRIDES_MAP);
  }

  /**
   * Loads the content type the context's identity names.
   *
   * @param \Drupal\data_surface\Surface\SurfaceContext $context
   *   The context.
   *
   * @return \Drupal\node\NodeTypeInterface|null
   *   The content type, or NULL when the context names none that exists.
   */
  protected function type(SurfaceContext $context): ?NodeTypeInterface {
    $id = $context->known['type'] ?? NULL;
    if (!is_string($id) || $id === '') {
      return NULL;
    }
    $type = $this->entityTypeManager->getStorage('node_type')->load($id);
    return $type instanceof NodeTypeInterface ? $type : NULL;
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
