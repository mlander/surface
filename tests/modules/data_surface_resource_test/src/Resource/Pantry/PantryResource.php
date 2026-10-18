<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Pantry;

use Drupal\data_surface_resource_test\Structure\Pantry\ShelfStructure;
use Drupal\data_surface\Resource\Attribute\Operation;
use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\PantryStorage;

/**
 * A pantry: every kind of part, in one resource.
 *
 * - shelf: attached by class, no storage, a refiner in its own frame and
 *   an alter of its own; stored by the pantry under its key.
 * - label: attached by class, with storage of its own.
 * - kind_settings: an open slot chosen by kind, filled by JarStructure
 *   (no storage) and TinResource (storage of its own). The kind declares
 *   a sack as well, which no variant fills, so the slot narrows it away.
 */
#[Resource('resource_test.pantry', identity: ['pantry'], storage: PantryStorage::class)]
final class PantryResource implements ResourceInterface {

  /**
   * A new pantry.
   */
  #[Operation('add', label: 'Add a pantry')]
  public static function add(): OperationContext {
    return new OperationContext('add', creates: TRUE);
  }

  /**
   * An existing pantry.
   */
  #[Operation('edit', label: 'Edit a pantry')]
  public static function edit(string $pantry): OperationContext {
    return new OperationContext('edit', known: ['pantry' => $pantry]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('pantry', 'string', 'Pantry')->setRequired(TRUE);
    $inputs->add('kind', 'string', 'Kind', default: 'jar')
      ->addConstraint('Choice', ['choices' => ['jar', 'tin', 'sack']]);
    $inputs->attach('shelf', ShelfStructure::class);
    $inputs->attach('label', LabelResource::class);
    $inputs->attachBy('kind_settings', by: 'kind')
      ->setLabel('Kind settings')
      ->setDescription('What the kind of container needs.');
  }

}
