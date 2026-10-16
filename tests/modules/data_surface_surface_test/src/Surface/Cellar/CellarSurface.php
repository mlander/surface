<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Cellar;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Surface\Attribute\RefinesInput;
use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Surface\Larder\SpiceRackSurface;
use Drupal\data_surface_surface_test\Surface\Pantry\ShelfSurface;
use Drupal\data_surface_surface_test\Target\CellarTarget;

/**
 * A cellar: two collections of children it already knows.
 *
 * - racks: a list of spice racks, one to four, fewer in a small cellar.
 *   The rack has situations and an access class, so every item is
 *   invoked through the rack's situation for the cellar's operation (its
 *   `edit`, which takes the larder, when the cellar is edited; its
 *   default `browse`, ten spices at most, when one is added), and its
 *   access answers once for the whole list.
 * - shelves: a list of shelves, at most three, each with the shelf's own
 *   refiner, which holds a shelf's height to its own unit, item by item.
 *
 * The cellar stores both under their keys: neither child names a target.
 */
#[Surface('surface_test.cellar', identity: ['larder'], target: CellarTarget::class)]
final class CellarSurface implements SurfaceInterface {

  /**
   * A new cellar.
   */
  #[Situation('add', label: 'Add a cellar', permission: 'keep a larder')]
  public static function add(): SurfaceContext {
    return new SurfaceContext('add', creates: TRUE);
  }

  /**
   * An existing cellar.
   */
  #[Situation('edit', label: 'Edit a cellar', permission: 'keep a larder')]
  public static function edit(string $larder): SurfaceContext {
    return new SurfaceContext('edit', known: ['larder' => $larder]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('larder', 'string', 'Larder')->setRequired(TRUE);
    $inputs->add('size', 'string', 'Size', default: 'large')
      ->addConstraint('Choice', ['choices' => ['small', 'large']]);
    $inputs->attachList('racks', SpiceRackSurface::class, min: 1, max: 4)
      ->setLabel('Spice racks')
      ->setDescription('One rack to four.');
    $inputs->attachList('shelves', ShelfSurface::class, max: 3)
      ->setLabel('Shelves');
  }

  /**
   * A small cellar holds two racks at most: the parent narrows its list.
   */
  #[RefinesInput('racks')]
  public static function fewerRacksWhenSmall(DataDefinitionInterface $racks, string $size): DataDefinitionInterface {
    if ($size !== 'small') {
      return $racks;
    }
    $count = $racks->getConstraints()['Count'] ?? [];
    return $racks->addConstraint('Count', array_replace($count, ['max' => min(2, $count['max'] ?? 2)]));
  }

}
