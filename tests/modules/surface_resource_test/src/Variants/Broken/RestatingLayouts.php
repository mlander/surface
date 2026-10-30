<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Variants\Broken;

use Drupal\surface\Resource\ClosedSiteListInterface;
use Drupal\surface\Resource\SiteList;
use Drupal\surface_resource_test\Structure\Poster\BannerPanelsStructure;

/**
 * A closed provider whose list names a structure again: refused.
 *
 * Each structure is named once, in structures().
 */
final class RestatingLayouts implements ClosedSiteListInterface {

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
    return (new SiteList())->add('banner', 'Banner', structure: BannerPanelsStructure::class);
  }

}
