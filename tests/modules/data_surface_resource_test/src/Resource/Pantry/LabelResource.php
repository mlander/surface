<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Resource\Pantry;

use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_resource_test\Storage\PantryLabelStorage;

/**
 * A pantry's label: a child stored apart, by storage of its own.
 */
#[Resource('resource_test.pantry.label', storage: PantryLabelStorage::class)]
final class LabelResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('text', 'string', 'Text', default: 'Pantry');
  }

}
