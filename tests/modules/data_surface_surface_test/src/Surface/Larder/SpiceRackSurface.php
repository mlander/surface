<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Larder;

use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Access\SpiceRackAccess;

/**
 * A spice rack: a child with three situations and an access class.
 *
 * - `edit` matches the larder's operation, and takes the larder from the
 *   identity the larder knows;
 * - `browse` is the default, for a larder whose operation it has none for,
 *   and holds no more than ten spices, which is how a test tells it apart;
 * - `restock` is reached only when the larder names it, needs the shelf
 *   the larder hands it, and asks a permission of its own.
 *
 * SpiceRackAccess answers open, locked or omitted from the account's
 * permissions, wherever the rack is invoked.
 */
#[Surface('surface_test.larder.rack', identity: ['shelf'], access: SpiceRackAccess::class)]
final class SpiceRackSurface implements SurfaceInterface {

  /**
   * The rack of a larder being edited.
   *
   * It needs the larder, which a larder context that knows nothing yet,
   * as a tool's static definition is built in, cannot give it: there the
   * rack is passed on to its default.
   */
  #[Situation('edit', label: 'Edit a spice rack')]
  public static function edit(string $larder): SurfaceContext {
    return new SurfaceContext('edit', known: ['larder' => $larder]);
  }

  /**
   * The rack, when nothing else picks a situation: a small one.
   */
  #[Situation('browse', label: 'Browse a spice rack', default: TRUE)]
  public static function browse(): SurfaceContext {
    return (new SurfaceContext('browse'))->withConstraint('spices', 'Range', ['min' => 0, 'max' => 10]);
  }

  /**
   * The rack being restocked, on one shelf, which it then knows.
   */
  #[Situation('restock', label: 'Restock a spice rack', permission: 'stock spice rack')]
  public static function restock(string $larder, string $shelf): SurfaceContext {
    return new SurfaceContext('restock', known: ['shelf' => strtolower($shelf), 'larder' => $larder]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('shelf', 'string', 'Shelf', default: 'middle');
    $inputs->add('spices', 'integer', 'Spices', default: 3)
      ->addConstraint('Range', ['min' => 0, 'max' => 40]);
    $inputs->add('label', 'string', 'Label', default: 'Spices');
  }

}
