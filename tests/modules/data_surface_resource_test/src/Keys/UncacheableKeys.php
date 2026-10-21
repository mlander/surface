<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Keys;

use Drupal\data_surface\Resource\KeyedList;
use Drupal\data_surface\Resource\KeysFromSiteInterface;

/**
 * Lists keys that cannot be cached: refused wherever they are asked for.
 */
final class UncacheableKeys implements KeysFromSiteInterface {

  /**
   * {@inheritdoc}
   */
  public function keys(): KeyedList {
    return KeyedList::fromLabels(['now' => 'Now'])->mergeCacheMaxAge(0);
  }

}
