<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Surface\Larder;

use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_surface_test\Target\LarderTarget;

/**
 * A larder with two spice racks: children invoked through situations.
 *
 * Both racks are the same surface, SpiceRackSurface, which declares three
 * situations of its own. Which one each rack is invoked through is the
 * resolution order the build step applies:
 * - editing the larder, the rack at `rack` is invoked through the rack's
 *   own `edit`, by operation, and the one at `spare_rack` through
 *   `restock`, which the larder's edit situation names, handing it a
 *   shelf;
 * - adding a larder, neither is named and the rack has no `add`, so both
 *   are invoked through the rack's default, `browse`.
 *
 * The racks have no target: the larder stores them under their keys.
 */
#[Surface('surface_test.larder', identity: ['larder'], target: LarderTarget::class)]
final class LarderSurface implements SurfaceInterface {

  /**
   * A new larder.
   */
  #[Situation('add', label: 'Add a larder', permission: 'keep a larder')]
  public static function add(): SurfaceContext {
    return new SurfaceContext('add', creates: TRUE);
  }

  /**
   * An existing larder, its spare rack restocked on the top shelf.
   *
   * The shelf is handed in capitals, and the rack's restock situation
   * knows it in lower case: the child's own known identity wins.
   */
  #[Situation('edit', label: 'Edit a larder', permission: 'keep a larder')]
  public static function edit(string $larder): SurfaceContext {
    return (new SurfaceContext('edit', known: ['larder' => $larder]))
      ->withChild('spare_rack', situation: 'restock', known: ['shelf' => 'TOP']);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('larder', 'string', 'Larder')->setRequired(TRUE);
    $inputs->add('note', 'string', 'Note');
    $inputs->attach('rack', SpiceRackSurface::class)->setLabel('Spice rack');
    $inputs->attach('spare_rack', SpiceRackSurface::class)->setLabel('Spare spice rack');
  }

}
