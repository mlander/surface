<?php

declare(strict_types=1);

namespace Drupal\surface\Resource;

use PortableSchema\Builder\Schema;

/**
 * A shape: one scalar value, its type and what it allows, named.
 *
 * Carries #[Shape]. Like a resource or a structure it is never
 * instantiated: schema() is static, called on the class, and returns the
 * schema of one scalar value, with its title, description, checks,
 * messages and examples. Whatever belongs to the key that uses it stays
 * the key's: whether it is required, its default, and anything it
 * narrows further. A shape is used in the type position of add(),
 * `$inputs->add('license', License::class, t('Event license'))`, or of a
 * port, `$inputs->reads('code', License::class)`.
 *
 * @see \Drupal\surface\Resource\Attribute\Shape
 * @see docs/declaring-a-resource.md#named-shapes
 */
interface ShapeInterface {

  /**
   * The value, as a schema.
   *
   * Takes nothing and holds no service, as defineInputs() does: what a
   * shape allows is a property of the class. It is read as an author's
   * schema is (addSchema()), so a title, a description or a message made
   * with t() stays translatable. No default and no required marker,
   * which the key decides; a scalar type, never an object or an array,
   * which are structures.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema.
   */
  public static function schema(): Schema;

}
