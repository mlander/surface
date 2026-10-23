<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Variants;

use Drupal\data_surface\Resource\VariantList;
use Drupal\data_surface\Resource\VariantsFromSiteInterface;

/**
 * Lists variants that vary by the account: refused wherever asked for.
 */
final class AccountLayouts implements VariantsFromSiteInterface {

  /**
   * {@inheritdoc}
   */
  public function variants(): VariantList {
    return (new VariantList())->add('mine', 'Mine', ['type' => 'object', 'properties' => []])->addCacheContexts(['user.permissions']);
  }

}
