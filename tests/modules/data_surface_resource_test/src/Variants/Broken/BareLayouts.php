<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Variants\Broken;

use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface\Resource\SiteListInterface;

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
