<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Pantry;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\Attribute\SurfaceVariant;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * A jar's settings: fills the pantry's open slot, stored by the pantry.
 */
#[Surface('surface_test.pantry.jar')]
#[SurfaceVariant(of: PantrySurface::class, key: 'kind_settings', value: 'jar')]
final class JarSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('lid', 'string', 'Lid', default: 'screw')
      ->setDescription('How the jar closes.')
      ->addConstraint('Choice', ['choices' => ['screw', 'clip']]);
    $inputs->add('volume', 'integer', 'Volume', default: 500)
      ->addConstraint('Range', ['min' => 1, 'max' => 5000]);
  }

}
