<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Cellar;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Resource\Larder\SpiceRackResource;
use Drupal\surface_resource_test\Structure\Pantry\ShelfStructure;
use Drupal\surface_resource_test\Storage\CellarStorage;

/**
 * A cellar: two collections of children it already knows.
 *
 * - racks: a list of spice racks, one to four, fewer in a small cellar.
 *   The rack has operations and an access class, so every item is
 *   invoked through the rack's operation for the cellar's operation (its
 *   `update`, which takes the larder, when the cellar is edited; its
 *   `create`, ten spices at most, when one is added), and its
 *   access answers once for the whole list.
 * - shelves: a list of shelves, at most three, each with the shelf's own
 *   refiner, which holds a shelf's height to its own unit, item by item.
 *
 * The cellar stores both under their keys: neither child names storage.
 */
#[Resource('resource_test.cellar', identity: ['larder'], storage: CellarStorage::class)]
final class CellarResource implements ResourceInterface {

  /**
   * A new cellar.
   */
  #[Operation('create', label: 'Add a cellar', permission: 'keep a larder', creates: TRUE)]
  public static function create(): OperationContext {
    return new OperationContext('create', creates: TRUE);
  }

  /**
   * An existing cellar.
   */
  #[Operation('update', label: 'Edit a cellar', permission: 'keep a larder')]
  public static function update(string $larder): OperationContext {
    return new OperationContext('update', known: ['larder' => $larder]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('larder', 'string', 'Larder')->setRequired(TRUE);
    $inputs->add('size', 'string', 'Size', default: 'large')
      ->addConstraint('Choice', ['choices' => ['small', 'large']]);
    $inputs->attachList('racks', SpiceRackResource::class, min: 1, max: 4)
      ->setLabel('Spice racks')
      ->setDescription('One rack to four.');
    $inputs->attachList('shelves', ShelfStructure::class, max: 3)
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
