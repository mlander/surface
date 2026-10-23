<?php

declare(strict_types=1);

namespace Drupal\data_surface_component_block\Storage;

use Drupal\block\BlockInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceStorageInterface;
use Drupal\data_surface\Storage\SchemaViolations;

/**
 * A placed component block's settings live on its block config entity.
 *
 * Loads by the machine name its context knows. The settings it reads and
 * writes are the structure's; the block host's own keys (its label, how
 * the label shows, the plugin id and provider) are kept as stored, and
 * the block's plugin, handed the settings, holds them to its structure
 * once more on save. A block whose settings did not move is not saved.
 */
final class PlacedComponentBlockStorage implements ResourceStorageInterface {

  /**
   * The keys of a block's settings its host owns, which no caller sets here.
   */
  public const HOST_KEYS = ['id', 'label', 'label_display', 'provider', 'context_mapping'];

  /**
   * Constructs a PlacedComponentBlockStorage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, autowired.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfig
   *   The typed config manager, autowired, which holds a rehearsed block to
   *   its config schema.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TypedConfigManagerInterface $typedConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(OperationContext $context): array {
    $block = $this->block($context);
    return [
      'block' => (string) $block->id(),
      'settings' => array_diff_key($block->get('settings'), array_flip(self::HOST_KEYS)),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The block it would save, unsaved, as its exported array, held to the
   * block's config schema.
   */
  public function prepare(OperationContext $context, array $values): array {
    $block = $this->block($context);
    $settings = $this->settings($block, $values);
    $record = $block->toArray();
    $record['settings'] = $settings;
    SchemaViolations::check($this->typedConfig, 'block.block.' . $block->id(), $record, ['settings' => 'settings']);
    return ['settings' => $settings];
  }

  /**
   * {@inheritdoc}
   */
  public function commit(OperationContext $context, array $prepared): void {
    $block = $this->block($context);
    if ($prepared['settings'] == $block->get('settings')) {
      return;
    }
    $block->set('settings', $prepared['settings'])->save();
  }

  /**
   * Gives the settings a block would hold with accepted values.
   *
   * @param \Drupal\block\BlockInterface $block
   *   The stored block.
   * @param array $values
   *   The accepted values.
   *
   * @return array
   *   The block's settings: the structure's keys as accepted, the host's
   *   as stored.
   */
  protected function settings(BlockInterface $block, array $values): array {
    return array_intersect_key($block->get('settings'), array_flip(self::HOST_KEYS)) + (array) ($values['settings'] ?? []);
  }

  /**
   * Loads the block the context names.
   *
   * @param \Drupal\data_surface\Resource\OperationContext $context
   *   The context, which knows the block's machine name.
   *
   * @return \Drupal\block\BlockInterface
   *   The block.
   *
   * @throws \InvalidArgumentException
   *   When the context names no block that exists: a block is placed
   *   through the block layout, never here.
   */
  protected function block(OperationContext $context): BlockInterface {
    $id = $context->known['block'] ?? NULL;
    $block = is_string($id) ? $this->entityTypeManager->getStorage('block')->load($id) : NULL;
    if (!$block instanceof BlockInterface) {
      throw new \InvalidArgumentException(sprintf('The "%s" context names no placed block: a block is placed through the block layout, then configured here.', $context->id));
    }
    return $block;
  }

}
