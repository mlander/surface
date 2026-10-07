<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Has two providers of one situation id.
 */
#[Surface('surface_test.broken.clashing_situation')]
final class ClashingSituationSurface implements SurfaceInterface {

  /**
   * One provider of the open situation.
   */
  #[Situation('open', label: 'Open')]
  public static function open(): SurfaceContext {
    return new SurfaceContext('open');
  }

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('name', 'string', 'Name');
  }

}
