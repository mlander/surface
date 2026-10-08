<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface_surface_test\Surface\Broken\ClashingSituationSurface;

/**
 * A second provider of the open situation, which the build refuses.
 */
final class ClashingSituations {

  /**
   * The other provider of the open situation.
   */
  #[Situation('open', label: 'Open again', of: ClashingSituationSurface::class)]
  public static function open(): SurfaceContext {
    return new SurfaceContext('open');
  }

}
