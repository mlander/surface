<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Resource;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_surface_test\Storage\PinnedNoteStorage;

/**
 * A resource with storage and an operation that a plugin also uses.
 *
 * Exists for the tool derivation rule: a resource a plugin names with
 * #[UsesStructure] is that plugin's configuration and gets no tool, even
 * one that names storage and an operation of its own.
 */
#[Resource('test.pinned_note', storage: PinnedNoteStorage::class)]
final class PinnedNoteResource implements ResourceInterface {

  /**
   * Pins a note.
   */
  #[Operation('pin', label: 'Pin a note', permission: 'access content')]
  public static function pin(): OperationContext {
    return new OperationContext('pin', creates: TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'), default: 'Pinned')->setRequired(TRUE);
  }

}
