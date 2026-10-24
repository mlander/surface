<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Variants\Broken;

use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

/**
 * Lists a variant that says it is locked, which a variant does not read.
 */
final class LockedLayouts implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())->add('wide', 'Wide', locked: TRUE, schema: ['type' => 'object', 'properties' => []]);
  }

}
