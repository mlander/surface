<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Pantry;

use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Target\PantryLabelTarget;

/**
 * A pantry's label: a child stored apart, by a target of its own.
 */
#[Surface('surface_test.pantry.label', target: PantryLabelTarget::class)]
final class LabelSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('text', 'string', 'Text', default: 'Pantry');
  }

}
