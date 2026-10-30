<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Keys;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;

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
