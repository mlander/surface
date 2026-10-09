<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Crate;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A crate: a slot whose variant refines one of its keys by another.
 *
 * For the served contract, which enumerates a variant's refined keys
 * inside the variant's own conditional.
 */
#[Surface('surface_test.crate')]
final class CrateSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('contents', 'string', 'Contents', default: 'fruit')
      ->setRequired(TRUE)
      ->addConstraint('Choice', ['choices' => ['fruit']]);
    $inputs->attachBy('contents_settings', by: 'contents')
      ->setLabel('Contents settings');
  }

}
