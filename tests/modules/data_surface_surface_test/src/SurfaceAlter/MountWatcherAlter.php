<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_surface_test\Surface\Broken\MountWatcherSurface;

/**
 * Refines a key it mounted, by watching another key it mounted.
 *
 * Refining its own key is allowed; watching one is not, because a
 * mounted key's value lives under the mount, where nothing can hand it
 * to a refiner.
 */
#[AltersSurface(MountWatcherSurface::class)]
final class MountWatcherAlter implements SurfaceAlterInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('kind', 'string', 'Kind');
    $inputs->add('detail', 'string', 'Detail');
  }

  /**
   * Watches the alter's own mounted kind.
   */
  #[RefinesInput('detail')]
  public function detailOfKind(DataDefinitionInterface $detail, string $kind): DataDefinitionInterface {
    return $detail;
  }

}
