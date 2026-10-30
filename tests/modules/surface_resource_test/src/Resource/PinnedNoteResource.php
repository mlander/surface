<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\PinnedNoteStorage;

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
  #[Operation('pin', label: 'Pin a note', permission: 'access content', creates: TRUE)]
  public static function pin(): OperationContext {
    return new OperationContext('pin', creates: TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', t('Note'), default: 'Pinned')->setRequired(TRUE);
  }

}
