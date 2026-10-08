<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceInterface;

/**
 * Has a watches list that does not match its parameters.
 */
#[Surface('surface_test.broken.watches_mismatch')]
final class WatchesMismatchSurface implements SurfaceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('first', 'string', 'First');
    $inputs->add('second', 'string', 'Second');
    $inputs->add('third', 'string', 'Third');
  }

  /**
   * Lists watches its parameters no longer match.
   */
  #[RefinesInput('third', watches: ['first', 'second'])]
  public static function thirdOf(DataDefinitionInterface $third, string $first, string $renamed): DataDefinitionInterface {
    return $third;
  }

}
