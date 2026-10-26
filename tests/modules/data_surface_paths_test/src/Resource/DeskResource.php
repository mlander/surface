<?php

declare(strict_types=1);

namespace Drupal\data_surface_paths_test\Resource;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_paths_test\Storage\NoteStorage;

/**
 * The desk: a singleton with both `update` and `configure`.
 *
 * Two updates carry a default's id, so neither is the default, and every
 * update of the desk is a custom method.
 */
#[Resource('paths_test.desk', storage: NoteStorage::class)]
#[Operation('configure', label: 'Configure the desk')]
#[Operation('update', label: 'Update the desk')]
#[Operation('touch', label: 'Touch the desk')]
final class DeskResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
  }

}
