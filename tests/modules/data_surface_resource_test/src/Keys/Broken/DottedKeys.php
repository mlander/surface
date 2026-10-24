<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Keys\Broken;

use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

/**
 * Lists an entry whose id holds a dot, which no key can be: refused.
 */
final class DottedKeys implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return SiteList::fromLabels(['lathe.left' => 'Left lathe']);
  }

}
