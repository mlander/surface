<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_surface_test\Surface\Broken\StrictMountWatcherSurface;

/**
 * Watches a key it mounted with a parameter that cannot take NULL.
 *
 * A mounted key is handed as it stands, NULL until it is answered, so
 * this is refused when the surface is built rather than failing on the
 * first empty value.
 */
#[AltersSurface(StrictMountWatcherSurface::class)]
final class StrictMountWatcherAlter implements SurfaceAlterInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
  }

  /**
   * Watches the kind, which may be NULL, as a string.
   */
  #[RefinesInput('size')]
  public function sizeOfKind(DataDefinitionInterface $size, string $kind): DataDefinitionInterface {
    return $size;
  }

}
