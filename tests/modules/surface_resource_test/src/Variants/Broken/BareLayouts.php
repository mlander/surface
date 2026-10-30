<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Variants\Broken;

use Drupal\surface\Resource\SiteList;
use Drupal\surface\Resource\SiteListInterface;

/**
 * Lists a variant with no schema and no structure: refused.
 */
final class BareLayouts implements SiteListInterface {

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return SiteList::fromLabels(['wide' => 'Wide']);
  }

}
