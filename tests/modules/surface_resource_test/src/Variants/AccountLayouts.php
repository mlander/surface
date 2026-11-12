<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Variants;

use Drupal\surface\Resource\SiteList;
use PortableSchema\Builder\Schema;
use Drupal\surface\Resource\SiteListInterface;

/**
 * Lists variants that vary by the account: refused wherever asked for.
 */
final class AccountLayouts implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())->add('mine', 'Mine', schema: Schema::from(['type' => 'object', 'properties' => []]))->addCacheContexts(['user.permissions']);
  }

}
