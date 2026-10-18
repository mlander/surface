<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * A structure whose alters watch keys an alter mounted.
 *
 * Its own alter watches a key it mounted itself, which is allowed; the
 * test module's alter watches that same key, another module's, which is
 * refused.
 *
 * @see \Drupal\data_surface_surface_test\Alter\MountWatcherAlter
 * @see \Drupal\data_surface_test\Alter\ForeignMountWatcherAlter
 */
#[Structure('surface_test.mount_watcher')]
final class MountWatcherStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
