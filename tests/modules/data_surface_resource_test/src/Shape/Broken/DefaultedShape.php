<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Shape\Broken;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\Attribute\Shape;
use Drupal\data_surface\Resource\ShapeInterface;

/**
 * Declares a default, which belongs to the key: refused when used.
 */
#[Shape('resource_test.broken.defaulted')]
final class DefaultedShape implements ShapeInterface {

  /**
   * {@inheritdoc}
   */
  public static function define(): DataDefinition {
    $definition = DataDefinition::create('string')->setLabel('Defaulted');
    DefinitionMetadata::setDefaultValue($definition, 'x');
    return $definition;
  }

}
