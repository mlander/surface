<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Keys;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;

/**
 * Lists one entry that says what it starts from.
 */
final class DefaultedKeys implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return (new SiteList())->add('first', 'First', default: TRUE);
  }

}
