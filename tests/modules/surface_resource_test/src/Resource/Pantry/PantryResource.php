<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Pantry;

use Drupal\surface_resource_test\Structure\Pantry\ShelfStructure;
use Drupal\surface\Resource\Attribute\Operation;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\PantryStorage;

/**
 * A pantry: every kind of part, in one resource.
 *
 * - shelf: attached by class, no storage, a refiner in its own frame and
 *   an alter of its own; stored by the pantry under its key.
 * - label: attached by class, with storage of its own.
 * - kind_settings: variants chosen by kind, declared by JarStructure
 *   (no storage) and TinResource (storage of its own). The kind declares
 *   its own list, which may name no value but a variant's, and keeps its
 *   own words: the variants give no labels here.
 */
#[Resource('resource_test.pantry', identity: ['pantry'], storage: PantryStorage::class)]
final class PantryResource implements ResourceInterface {

  /**
   * A new pantry.
   */
  #[Operation('create', label: 'Add a pantry', creates: TRUE)]
  public static function create(): OperationContext {
    return new OperationContext('create', creates: TRUE);
  }

  /**
   * An existing pantry.
   */
  #[Operation('update', label: 'Edit a pantry')]
  public static function update(string $pantry): OperationContext {
    return new OperationContext('update', known: ['pantry' => $pantry]);
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('pantry', 'string', 'Pantry')->setRequired(TRUE);
    $inputs->add('kind', 'string', 'Kind', default: 'jar')
      ->addConstraint('Choice', ['choices' => ['jar', 'tin']]);
    $inputs->attach('shelf', ShelfStructure::class);
    $inputs->attach('label', LabelResource::class);
    $inputs->attachVariants('kind_settings', by: 'kind')
      ->setLabel('Kind settings')
      ->setDescription('What the kind of container needs.');
  }

}
