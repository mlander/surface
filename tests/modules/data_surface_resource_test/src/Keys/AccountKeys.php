<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Keys;

use Drupal\data_surface\Resource\KeyedList;
use Drupal\data_surface\Resource\KeysFromSiteInterface;

/**
 * Lists keys that vary by the account: refused wherever they are asked for.
 */
final class AccountKeys implements KeysFromSiteInterface {

  /**
   * {@inheritdoc}
   */
  public function keys(): KeyedList {
    return KeyedList::fromLabels(['mine' => 'Mine'])->addCacheContexts(['user.permissions']);
  }

}
