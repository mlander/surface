<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Pantry;

use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Target\PantryTarget;

/**
 * A pantry: every kind of subsurface, in one surface.
 *
 * - shelf: attached by class, no target, a refiner in its own frame and
 *   an alter of its own; stored by the pantry under its key.
 * - label: attached by class, with a target of its own.
 * - kind_settings: an open slot chosen by kind, filled by JarSurface
 *   (no target) and TinSurface (a target of its own). The kind declares
 *   a sack as well, which no variant fills, so the slot narrows it away.
 */
#[Surface('surface_test.pantry', identity: ['pantry'], target: PantryTarget::class)]
final class PantrySurface implements SurfaceInterface {

  /**
   * A new pantry.
   */
  #[Situation('add', label: 'Add a pantry')]
  public static function add(): SurfaceContext {
    return new SurfaceContext('add', creates: TRUE);
  }

  /**
   * An existing pantry.
   */
  #[Situation('edit', label: 'Edit a pantry')]
  public static function edit(string $pantry): SurfaceContext {
    return new SurfaceContext('edit', known: ['pantry' => $pantry]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('pantry', 'string', 'Pantry')->setRequired(TRUE);
    $inputs->add('kind', 'string', 'Kind', default: 'jar')
      ->addConstraint('Choice', ['choices' => ['jar', 'tin', 'sack']]);
    $inputs->attach('shelf', ShelfSurface::class);
    $inputs->attach('label', LabelSurface::class);
    $inputs->attachBy('kind_settings', by: 'kind')
      ->setLabel('Kind settings')
      ->setDescription('What the kind of container needs.');
  }

}
