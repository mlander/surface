<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Resource\Pantry;

use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_resource_test\Storage\PantryLabelStorage;
use PortableSchema\Builder\Schema;

/**
 * A pantry's label: a child stored apart, by storage of its own.
 */
#[Resource('resource_test.pantry.label', storage: PantryLabelStorage::class)]
final class LabelResource implements ResourceInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('text', Schema::string()
      ->title('Text')
      ->default('Pantry'));
  }

}
