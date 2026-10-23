<?php

declare(strict_types=1);

namespace Drupal\data_surface_block\Storage;

use Drupal\block\BlockInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\data_surface\Pipeline\StorageViolationsException;
use Drupal\data_surface\Pipeline\Violation;
use Drupal\data_surface\Pipeline\ViolationSet;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Storage\ConfigEntityStorage;
use Drupal\data_surface_block\Variants\BlockPluginStructures;

/**
 * A placed block lives on its block config entity, `block.block.<id>`.
 *
 * Its machine name, status, weight, theme, region and plugin are the
 * entity's own properties (ConfigEntityStorage). The rest is shaped here:
 *
 * - **The settings.** The block's settings array holds the keys every
 *   block has and the plugin's own. The label and whether it shows are
 *   the resource's keys, the label shown as `visible` or `0` as core
 *   stores it, and the provider is the plugin's; the plugin's own keys
 *   are the `settings` variant. What is stored is what the plugin makes
 *   of them, its defaults filled, as a save would store it.
 * - **The visibility.** A list of conditions on the resource, a map by
 *   condition id on the block: each condition's id and negation, with
 *   its settings flat beside them. A condition twice is refused.
 * - **The dependencies.** prepare() calculates what the block depends on,
 *   as its save does (its theme, its plugin's provider, what the plugin
 *   names), so a dry run shows them.
 *
 * A violation core's block schema finds is filed under the key that owns
 * it, a condition's by its place in the list.
 *
 * Decision: see docs/decisions.md#a-placed-block-is-a-resource.
 */
final class BlockPlacementStorage extends ConfigEntityStorage {

  /**
   * {@inheritdoc}
   */
  public const ENTITY_TYPE_ID = 'block';

  /**
   * {@inheritdoc}
   */
  public const PROPERTIES = ['id', 'status', 'weight', 'theme', 'region', 'plugin'];

  /**
   * The resource's keys, in the order it declares them.
   */
  protected const ORDER = [
    'label',
    'id',
    'label_display',
    'plugin',
    'settings',
    'theme',
    'region',
    'weight',
    'status',
    'visibility',
  ];

  /**
   * How core stores a label shown and a label hidden.
   */
  protected const LABEL_SHOWN = BlockPluginInterface::BLOCK_LABEL_VISIBLE;

  /**
   * Constructs a BlockPlacementStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, autowired.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service, autowired.
   * @param \Drupal\Core\Block\BlockManagerInterface $blocks
   *   The block plugin manager, autowired, which names a plugin's provider.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    TypedConfigManagerInterface $typedConfig,
    TranslationInterface $string_translation,
    protected readonly BlockManagerInterface $blocks,
  ) {
    parent::__construct($entityTypeManager, $typedConfig, $string_translation);
  }

  /**
   * {@inheritdoc}
   *
   * Refuses a condition held twice, and files a condition's violations by
   * its place in the list.
   */
  public function prepare(OperationContext $context, array $values): array {
    $refused = [];
    $positions = [];
    foreach (array_values(is_array($values['visibility'] ?? NULL) ? $values['visibility'] : []) as $delta => $condition) {
      $id = is_array($condition) ? ($condition['id'] ?? NULL) : NULL;
      if (!is_string($id)) {
        continue;
      }
      if (isset($positions[$id])) {
        $refused[] = new Violation('visibility', $delta . '.id', $this->t('The @id condition is held already, at position @first: a block holds each condition once.', [
          '@id' => $id,
          '@first' => $positions[$id],
        ]), Violation::TAKEN);
        continue;
      }
      $positions[$id] = $delta;
    }
    if ($refused !== []) {
      throw new StorageViolationsException(new ViolationSet($refused));
    }
    try {
      return parent::prepare($context, $values);
    }
    catch (StorageViolationsException $e) {
      $placed = [];
      foreach ($e->getViolations() as $violation) {
        $placed[] = $violation->key === 'visibility' ? self::placeCondition($violation, $positions) : $violation;
      }
      throw new StorageViolationsException(new ViolationSet($placed));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function fromEntity(ConfigEntityInterface $entity): array {
    assert($entity instanceof BlockInterface);
    $values = parent::fromEntity($entity);
    $settings = (array) $entity->get('settings');
    $values['label'] = $settings['label'] ?? '';
    $values['label_display'] = ($settings['label_display'] ?? NULL) === self::LABEL_SHOWN;
    $values['settings'] = array_diff_key($settings, array_flip(BlockPluginStructures::BASE));
    $values['visibility'] = [];
    foreach ((array) $entity->get('visibility') as $id => $condition) {
      $condition = (array) $condition;
      $settings = array_map(self::listOf(...), array_diff_key($condition, array_flip(['id', 'negate', 'uuid'])));
      if (($settings['context_mapping'] ?? NULL) === []) {
        unset($settings['context_mapping']);
      }
      $values['visibility'][] = [
        'id' => (string) $id,
        'negate' => (bool) ($condition['negate'] ?? FALSE),
        'settings' => $settings,
      ];
    }
    // In the order the resource declares its keys, as every door reads
    // them.
    $order = array_flip(self::ORDER);
    return array_replace(array_intersect_key($order, $values), $values);
  }

  /**
   * {@inheritdoc}
   */
  protected function toEntity(ConfigEntityInterface $entity, array $values): void {
    assert($entity instanceof BlockInterface);
    parent::toEntity($entity, $values);
    $plugin = $entity->getPluginId();
    $stored = (array) $entity->get('settings');
    $definition = $this->blocks->getDefinition($plugin, FALSE);
    $label_display = array_key_exists('label_display', $values)
      ? ($values['label_display'] ? self::LABEL_SHOWN : '0')
      : ($stored['label_display'] ?? self::LABEL_SHOWN);
    $own = array_key_exists('settings', $values) ? (array) $values['settings'] : array_diff_key($stored, array_flip(BlockPluginStructures::BASE));
    $entity->set('settings', [
      'id' => $plugin,
      'label' => $values['label'] ?? $stored['label'] ?? '',
      'label_display' => $label_display,
      'provider' => is_array($definition) ? ($definition['provider'] ?? '') : ($stored['provider'] ?? ''),
    ] + $own);
    // What the plugin makes of them, its defaults filled, is what a save
    // stores.
    $entity->set('settings', $entity->getPlugin()->getConfiguration());
    if (array_key_exists('visibility', $values)) {
      $visibility = [];
      foreach (is_array($values['visibility']) ? $values['visibility'] : [] as $condition) {
        if (!is_array($condition) || !is_string($condition['id'] ?? NULL)) {
          continue;
        }
        $visibility[$condition['id']] = [
          'id' => $condition['id'],
          'negate' => (bool) ($condition['negate'] ?? FALSE),
        ] + array_map(self::keyedByValue(...), is_array($condition['settings'] ?? NULL) ? $condition['settings'] : []);
      }
      $entity->set('visibility', $visibility);
      $entity->set('visibility', $entity->getVisibilityConditions()->getConfiguration());
    }
  }

  /**
   * {@inheritdoc}
   *
   * The label and whether it shows are inside the settings; a key of the
   * plugin's under `settings`.
   */
  protected function schemaPaths(): array {
    return [
      'settings.label' => 'label',
      'settings.label_display' => 'label_display',
      'settings' => 'settings',
      'visibility' => 'visibility',
    ] + parent::schemaPaths();
  }

  /**
   * {@inheritdoc}
   *
   * Under the plugin chosen: the plugin collection is built from the id
   * the block is created with, and a settings array of another plugin is
   * no starting point.
   */
  protected function copy(ConfigEntityInterface $entity, array $values): ConfigEntityInterface {
    assert($entity instanceof BlockInterface);
    $record = $entity->toArray();
    $plugin = $values['plugin'] ?? $record['plugin'];
    if ($plugin !== $record['plugin']) {
      $record['settings'] = [];
    }
    $copy = $this->storage()->create(['plugin' => $plugin] + $record);
    assert($copy instanceof ConfigEntityInterface);
    $copy->enforceIsNew(FALSE);
    return $copy;
  }

  /**
   * {@inheritdoc}
   *
   * The plugin, which the block's plugin collection is built from.
   */
  protected function initial(array $values): array {
    return isset($values['plugin']) ? ['plugin' => $values['plugin'], 'settings' => []] : [];
  }

  /**
   * Reads a condition's checked values, a map of each to itself, as a list.
   *
   * Core's conditions keep what a person checks (roles, bundles,
   * languages) as a map of each value to itself, the way the block layout
   * submits checkboxes; the resource asks for the list of them.
   *
   * @param mixed $value
   *   A condition's setting, as stored.
   *
   * @return mixed
   *   The list of the values, for such a map; the setting as it is
   *   otherwise.
   */
  protected static function listOf(mixed $value): mixed {
    if (!is_array($value) || $value === [] || array_is_list($value)) {
      return $value;
    }
    foreach ($value as $key => $item) {
      if (!is_string($item) || (string) $key !== $item) {
        return $value;
      }
    }
    return array_values($value);
  }

  /**
   * Writes a list of strings as the map of each to itself core keeps.
   *
   * @param mixed $value
   *   A condition's setting, as accepted.
   *
   * @return mixed
   *   The map, for a list of strings; the setting as it is otherwise.
   */
  protected static function keyedByValue(mixed $value): mixed {
    if (!is_array($value) || $value === [] || !array_is_list($value) || array_filter($value, 'is_string') !== $value) {
      return $value;
    }
    return array_combine($value, $value);
  }

  /**
   * Files a condition's violation by its place in the list.
   *
   * @param \Drupal\data_surface\Pipeline\Violation $violation
   *   The violation, its path under the block's visibility map:
   *   `<condition id>.<key>`.
   * @param array<string, int> $positions
   *   Each condition's place in the list, by id.
   *
   * @return \Drupal\data_surface\Pipeline\Violation
   *   The violation at `<delta>.id`, `<delta>.negate` or
   *   `<delta>.settings.<key>`.
   */
  protected static function placeCondition(Violation $violation, array $positions): Violation {
    [$id, $rest] = array_pad(explode('.', $violation->path, 2), 2, '');
    if (!isset($positions[$id])) {
      return $violation;
    }
    $delta = $positions[$id];
    $path = match (TRUE) {
      $rest === '' => (string) $delta,
      $rest === 'id', $rest === 'negate' => $delta . '.' . $rest,
      default => $delta . '.settings.' . $rest,
    };
    return $violation->at('visibility', $path);
  }

}
