<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Resource\Larder;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_surface_test\Storage\LarderStorage;

/**
 * A larder with two spice racks: children invoked through operations.
 *
 * Both racks are the same resource, SpiceRackResource, which declares three
 * operations of its own. Which one each rack is invoked through is the
 * resolution order the build step applies:
 * - editing the larder, the rack at `rack` is invoked through the rack's
 *   own `edit`, by operation, and the one at `spare_rack` through
 *   `restock`, which the larder's edit operation names, handing it a
 *   shelf;
 * - adding a larder, neither is named and the rack has no `add`, so both
 *   are invoked through the rack's default, `browse`;
 * - stocking a larder, the larder hands both racks a context whole, built
 *   by calling the rack's own operations: no operation of the rack's is
 *   invoked, so `restock`'s permission is not asked, and the rack's
 *   access class answers all the same.
 *
 * The racks have no storage: the larder stores them under their keys.
 */
#[Resource('surface_test.larder', identity: ['larder'], storage: LarderStorage::class)]
final class LarderResource implements ResourceInterface {

  /**
   * A new larder.
   */
  #[Operation('add', label: 'Add a larder', permission: 'keep a larder')]
  public static function add(): OperationContext {
    return new OperationContext('add', creates: TRUE);
  }

  /**
   * An existing larder, its spare rack restocked on the top shelf.
   *
   * The shelf is handed in capitals, and the rack's restock operation
   * knows it in lower case: the child's own known identity wins.
   */
  #[Operation('edit', label: 'Edit a larder', permission: 'keep a larder')]
  public static function edit(string $larder): OperationContext {
    return (new OperationContext('edit', known: ['larder' => $larder]))
      ->withChild('spare_rack', operation: 'restock', known: ['shelf' => 'TOP']);
  }

  /**
   * An existing larder, both racks handed a context the larder built.
   *
   * The larder takes responsibility for entering them, as a field does
   * for its storage, so only the rack's access class decides its keys.
   */
  #[Operation('stock', label: 'Stock a larder', permission: 'keep a larder')]
  public static function stock(string $larder): OperationContext {
    return (new OperationContext('stock', known: ['larder' => $larder]))
      ->withChild('rack', SpiceRackResource::browse())
      ->withChild('spare_rack', SpiceRackResource::restock($larder, 'TOP'));
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('larder', 'string', 'Larder')->setRequired(TRUE);
    $inputs->add('note', 'string', 'Note');
    $inputs->attach('rack', SpiceRackResource::class)->setLabel('Spice rack');
    $inputs->attach('spare_rack', SpiceRackResource::class)->setLabel('Spare spice rack');
  }

}
