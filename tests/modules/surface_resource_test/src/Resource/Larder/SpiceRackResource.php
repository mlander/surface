<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Larder;

use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Access\SpiceRackAccess;

/**
 * A spice rack: a child with four operations and an access class.
 *
 * - `update` matches the larder's operation, and takes the larder from the
 *   identity the larder knows;
 * - `create` matches a larder being added, and is the operation of its
 *   kind for a larder whose creating operation it has none for (`copy`);
 *   it holds no more than ten spices, which is how a test tells it apart;
 * - `browse` holds no more than ten spices too, and is reached only when
 *   the larder hands its context whole;
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
  #[Operation('update', label: 'Edit a spice rack')]
  public static function update(string $larder): OperationContext {
    return new OperationContext('update', known: ['larder' => $larder]);
  }

  /**
   * A new rack: a small one.
   */
  #[Operation('create', label: 'Add a spice rack', creates: TRUE)]
  public static function create(): OperationContext {
    return (new OperationContext())->withConstraint('spices', 'Range', ['min' => 0, 'max' => 10]);
  }

  /**
   * The rack browsed: a small one.
   */
  #[Operation('browse', label: 'Browse a spice rack')]
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
