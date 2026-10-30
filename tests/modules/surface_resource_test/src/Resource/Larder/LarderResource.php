<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Larder;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\LarderStorage;

/**
 * A larder with two spice racks: children invoked through operations.
 *
 * Both racks are the same resource, SpiceRackResource, which declares three
 * operations of its own. Which one each rack is invoked through is the
 * resolution order the build step applies:
 * - editing the larder, the rack at `rack` is invoked through the rack's
 *   own `update`, by operation, and the one at `spare_rack` through
 *   `restock`, which the larder's update operation names, handing it a
 *   shelf;
 * - adding a larder, neither is named, and both are invoked through the
 *   rack's own `create`, by the context's id; copying one, the rack has
 *   no `copy`, and both are invoked through its operation of the
 *   parent's kind, `create` again;
 * - stocking a larder, the larder hands both racks a context whole, built
 *   by calling the rack's own operations: no operation of the rack's is
 *   invoked, so `restock`'s permission is not asked, and the rack's
 *   access class answers all the same.
 *
 * The racks have no storage: the larder stores them under their keys.
 */
#[Resource('resource_test.larder', identity: ['larder'], storage: LarderStorage::class)]
final class LarderResource implements ResourceInterface {

  /**
   * A new larder.
   */
  #[Operation('create', label: 'Add a larder', permission: 'keep a larder', creates: TRUE)]
  public static function create(): OperationContext {
    return new OperationContext('create', creates: TRUE);
  }

  /**
   * A new larder, copied: an operation of the create kind the rack lacks.
   */
  #[Operation('copy', label: 'Copy a larder', permission: 'keep a larder', creates: TRUE)]
  public static function copy(): OperationContext {
    return new OperationContext();
  }

  /**
   * An existing larder, its spare rack restocked on the top shelf.
   *
   * The shelf is handed in capitals, and the rack's restock operation
   * knows it in lower case: the child's own known identity wins.
   */
  #[Operation('update', label: 'Edit a larder', permission: 'keep a larder')]
  public static function update(string $larder): OperationContext {
    return (new OperationContext('update', known: ['larder' => $larder]))
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
