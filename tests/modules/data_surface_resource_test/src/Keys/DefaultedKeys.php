<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Keys;

use Drupal\data_surface\Resource\KeyedList;
use Drupal\data_surface\Resource\KeysFromSiteInterface;

/**
 * Lists one entry that says what it starts from.
 */
final class DefaultedKeys implements KeysFromSiteInterface {

  /**
   * {@inheritdoc}
   */
  public function keys(): KeyedList {
    return (new KeyedList())->add('first', 'First', default: TRUE);
  }

}
