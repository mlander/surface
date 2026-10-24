<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Variants;

use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

/**
 * Lists variants that cannot be cached: refused wherever they are asked for.
 */
final class UncacheableLayouts implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())->add('now', 'Now', schema: ['type' => 'object', 'properties' => []])->mergeCacheMaxAge(0);
  }

}
