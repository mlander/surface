<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Keys;

use Drupal\data_surface\Resource\KeyedList;
use Drupal\data_surface\Resource\KeysFromSiteInterface;

/**
 * The gallery's rooms: a fixed list, the same for every account.
 */
final class GalleryRooms implements KeysFromSiteInterface {

  /**
   * {@inheritdoc}
   */
  public function keys(): KeyedList {
    return (new KeyedList())
      ->add('east', 'East room')
      ->add('west', 'West room');
  }

}
