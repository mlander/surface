<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\SurfaceAlter;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\AltersSurface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\ShapeAdditionsInterface;
use Drupal\data_surface\Surface\SurfaceAlterInterface;
use Drupal\data_surface_surface_test\Surface\MountWatcherSurface;

/**
 * Refines a key it mounted, and the owner's, by watching a key it mounted.
 *
 * Both are allowed: the kind is read at its path inside the mount, and
 * handed as it stands, NULL while it is unanswered.
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
   * Without a kind, the detail is short.
   */
  #[RefinesInput('detail')]
  public function detailOfKind(DataDefinitionInterface $detail, ?string $kind): DataDefinitionInterface {
    return $kind === NULL ? $detail->addConstraint('Length', ['max' => 10]) : $detail;
  }

  /**
   * A large kind allows no more than a hundred.
   */
  #[RefinesInput('size')]
  public function sizeOfKind(DataDefinitionInterface $size, ?string $kind): DataDefinitionInterface {
    return $kind === 'large' ? $size->addConstraint('Range', ['max' => 100]) : $size;
  }

}
