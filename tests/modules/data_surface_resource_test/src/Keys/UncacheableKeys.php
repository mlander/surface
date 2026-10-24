<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Keys;

use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

/**
 * Lists keys that cannot be cached: refused wherever they are asked for.
 */
final class UncacheableKeys implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return SiteList::fromLabels(['now' => 'Now'])->mergeCacheMaxAge(0);
  }

}
