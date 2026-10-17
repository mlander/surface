<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Structure;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\data_surface\Resource\ShapeInterface;

/**
 * A field item's shape written by hand, for one field type.
 *
 * Where the derivation from typed data is thin, a module writes the item
 * structure itself and marks it #[FieldItemStructure(type: '...')]. Its
 * keys are the item's property names, which is how the content entity
 * storage writes them: each key is set on the field's first item by that
 * name.
 */
interface FieldItemStructureInterface {

  /**
   * Fills the item's shape.
   *
   * Static, like a structure's defineInputs(), and value-independent; it is
   * handed the field definition because one class serves every field of
   * its type, each with its own label and settings.
   *
   * @param \Drupal\data_surface\Resource\ShapeInterface $inputs
   *   The shape to fill.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   */
  public static function defineItem(ShapeInterface $inputs, FieldDefinitionInterface $field): void;

}
