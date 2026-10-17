<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Resource\Pantry;

use Drupal\data_surface\Resource\Attribute\Resource;
use Drupal\data_surface\Resource\ShapeInterface;
use Drupal\data_surface\Resource\ResourceInterface;
use Drupal\data_surface_surface_test\Storage\PantryLabelStorage;

/**
 * A pantry's label: a child stored apart, by storage of its own.
 */
#[Resource('surface_test.pantry.label', storage: PantryLabelStorage::class)]
final class LabelResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('text', 'string', 'Text', default: 'Pantry');
  }

}
