<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Structure\Attribute;

/**
 * On a class in src/Structure: the item structure of a field type.
 *
 * Replaces the derivation from typed data for that field type wherever a
 * field of it is derived: the class implements FieldItemStructureInterface,
 * whose defineItem() fills the item's shape, and its own static
 * #[RefinesInput] methods refine it. The field's label, operations and
 * access stay the derived ones; only the shape and its refiners are
 * written by hand.
 *
 * @see \Drupal\data_surface_entity\Structure\FieldItemStructureInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class FieldItemStructure {

  /**
   * Constructs a FieldItemStructure attribute.
   *
   * @param string $type
   *   The field type id.
   */
  public function __construct(
    public readonly string $type,
  ) {}

}
