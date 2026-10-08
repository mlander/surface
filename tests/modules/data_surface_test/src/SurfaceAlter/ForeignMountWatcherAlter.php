<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\SurfaceAlter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_surface_test\Surface\MountWatcherSurface;

/**
 * Watches a key another module mounted, which is refused.
 *
 * The kind is data_surface_surface_test's. Applies only where that module
 * is enabled too, since the surface it names is that module's.
 */
#[AltersSurface(MountWatcherSurface::class)]
final class ForeignMountWatcherAlter implements SurfaceAlterInterface {

  /**
   * {@inheritdoc}
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void {
    $inputs->add('note', 'string', 'Note');
  }

  /**
   * Watches the other module's kind.
   */
  #[RefinesInput('note')]
  public function noteOfKind(DataDefinitionInterface $note, ?string $kind): DataDefinitionInterface {
    return $note;
  }

}
