<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Larder;

use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Access\SpiceRackAccess;

/**
 * A spice rack: a child with three operations and an access class.
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
#[Resource('resource_test.larder.rack', identity: ['shelf'], access: SpiceRackAccess::class)]
final class SpiceRackResource implements ResourceInterface {

  /**
   * The rack of a larder being edited.
   *
   * It needs the larder, which a larder context that knows nothing yet,
   * as a tool's static definition is built in, cannot give it: there the
   * rack is passed on to its default.
   */
  #[Operation('edit', label: 'Edit a spice rack')]
  public static function edit(string $larder): OperationContext {
    return new OperationContext('edit', known: ['larder' => $larder]);
  }

  /**
   * The rack, when nothing else picks an operation: a small one.
   */
  #[Operation('browse', label: 'Browse a spice rack', default: TRUE)]
  public static function browse(): OperationContext {
    return (new OperationContext('browse'))->withConstraint('spices', 'Range', ['min' => 0, 'max' => 10]);
  }

  /**
   * The rack being restocked, on one shelf, which it then knows.
   */
  #[Operation('restock', label: 'Restock a spice rack', permission: 'stock spice rack')]
  public static function restock(string $larder, string $shelf): OperationContext {
    return new OperationContext('restock', known: ['shelf' => strtolower($shelf), 'larder' => $larder]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('shelf', 'string', 'Shelf', default: 'middle');
    $inputs->add('spices', 'integer', 'Spices', default: 3)
      ->addConstraint('Range', ['min' => 0, 'max' => 40]);
    $inputs->add('label', 'string', 'Label', default: 'Spices');
  }

}
