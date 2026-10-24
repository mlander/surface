<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Keys;

use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

/**
 * The gallery's rooms: a fixed list, the same for every account.
 */
final class GalleryRooms implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())
      ->add('east', 'East room')
      ->add('west', 'West room');
  }

}
