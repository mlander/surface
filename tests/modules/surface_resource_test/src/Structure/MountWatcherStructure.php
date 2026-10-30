<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A structure whose alters watch keys an alter mounted.
 *
 * Its own alter watches a key it mounted itself, which is allowed; the
 * test module's alter watches that same key, another module's, which is
 * refused.
 *
 * @see \Drupal\surface_resource_test\Alter\MountWatcherAlter
 * @see \Drupal\surface_test\Alter\ForeignMountWatcherAlter
 */
#[Structure('resource_test.mount_watcher')]
final class MountWatcherStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'integer', 'Size');
  }

}
