<?php

declare(strict_types=1);

namespace Drupal\data_surface_entity\Surface\Attribute;

/**
 * On a class in src/Surface: the item surface of every field of a type.
 *
 * Replaces the derivation from typed data for that field type wherever a
 * field of it is derived: the class implements FieldItemSurfaceInterface,
 * whose defineItem() fills the item's shape, and its own static
 * #[RefinesInput] methods refine it. The field's label, situations and
 * access stay the derived ones; only the shape and its refiners are
 * written by hand.
 *
 * @see \Drupal\data_surface_entity\Surface\FieldItemSurfaceInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class FieldItemSurface {

  /**
   * Constructs a FieldItemSurface attribute.
   *
   * @param string $type
   *   The field type id.
   */
  public function __construct(
    public readonly string $type,
  ) {}

}
