<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource\Attribute;

/**
 * Names a shape: a scalar definition reused at many keys.
 *
 * A shape is one value with its type, its label, its description, its
 * constraints and its examples, declared once and named, so a key says
 * `$inputs->add('license', License::class, t('Event license'))` instead
 * of repeating them. The served contract writes it once, as a def under
 * this id, and refers to it from every key that uses it; an alter naming
 * the class tightens it at every one of those keys. Any class in a
 * module's src/Shape directory carrying this is found. The id shares one
 * namespace with resource and structure ids, since all three name defs.
 *
 * @see \Drupal\data_surface\Resource\ShapeInterface
 * @see docs/decisions.md#a-shape-is-a-named-definition
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Shape {

  /**
   * Constructs a Shape attribute.
   *
   * @param string $id
   *   Machine name, dotted as a resource's is: `event.license`.
   */
  public function __construct(
    public readonly string $id,
  ) {}

}
