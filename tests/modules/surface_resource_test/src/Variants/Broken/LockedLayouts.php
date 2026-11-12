<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Variants\Broken;

use Drupal\surface\Resource\SiteList;
use PortableSchema\Builder\Schema;
use Drupal\surface\Resource\SiteListInterface;

/**
 * Lists a variant that says it is locked, which a variant does not read.
 */
final class LockedLayouts implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    $schema = Schema::from(['type' => 'object', 'properties' => []]);
    return (new SiteList())->add('wide', 'Wide', locked: TRUE, schema: $schema);
  }

}
