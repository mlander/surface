<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

use Drupal\Core\TypedData\DataDefinition;

/**
 * A shape: one scalar value, its type and what it allows, named.
 *
 * Carries #[Shape]. Like a resource or a structure it is never
 * instantiated: define() is static, called on the class, and returns a
 * plain core data definition of a scalar type, with its label,
 * description, constraints and examples. Whatever belongs to the key that
 * uses it stays the key's: whether it is required, its default, and
 * anything it narrows further. A shape is used in the type position of
 * add(), `$inputs->add('licence', Licence::class, t('Event licence'))`, or
 * of a port, `$inputs->reads('code', Licence::class)`.
 *
 * @see \Drupal\data_surface\Resource\Attribute\Shape
 * @see docs/declaring-a-resource.md#named-shapes
 */
interface ShapeInterface {

  /**
   * The value, as a core data definition.
   *
   * Takes nothing and holds no service, as defineInputs() does: what a
   * shape allows is a property of the class. No default and no required
   * flag, which the key decides; a scalar type, never a map or a list,
   * which are structures.
   *
   * @return \Drupal\Core\TypedData\DataDefinition
   *   The definition.
   */
  public static function define(): DataDefinition;

}
