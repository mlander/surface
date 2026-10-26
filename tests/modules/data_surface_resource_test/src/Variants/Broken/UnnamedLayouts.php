<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Variants\Broken;

use Drupal\data_surface\Resource\ClosedSiteListInterface;
use Drupal\data_surface\Resource\SiteList;
use Drupal\data_surface_resource_test\Structure\Poster\BannerPanelsStructure;

/**
 * A closed provider listing a value its structures() do not name: refused.
 */
final class UnnamedLayouts implements ClosedSiteListInterface {

  /**
   * {@inheritdoc}
   */
  public static function structures(): array {
    return ['banner' => BannerPanelsStructure::class];
  }

  /**
   * {@inheritdoc}
   */
  public function list(): SiteList {
    return SiteList::fromLabels(['poster' => 'Poster']);
  }

}
