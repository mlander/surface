<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Variants;

use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

/**
 * Lists variants that vary by the account: refused wherever asked for.
 */
final class AccountLayouts implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())->add('mine', 'Mine', schema: ['type' => 'object', 'properties' => []])->addCacheContexts(['user.permissions']);
  }

}
