<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Keys\Broken;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;

/**
 * Lists an entry with a schema, which a key per thing does not read.
 */
final class SchemaKeys implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())->add('lathe', 'Lathe', schema: ['type' => 'object']);
  }

}
