<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Variants;

use Drupal\data_surface\Resource\VariantList;
use Drupal\data_surface\Resource\VariantsFromSiteInterface;

/**
 * Lists variants that cannot be cached: refused wherever they are asked for.
 */
final class UncacheableLayouts implements VariantsFromSiteInterface {

  /**
   * {@inheritdoc}
   */
  public function variants(): VariantList {
    return (new VariantList())->add('now', 'Now', ['type' => 'object', 'properties' => []])->mergeCacheMaxAge(0);
  }

}
