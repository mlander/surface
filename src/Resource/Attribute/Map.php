<?php

declare(strict_types=1);

namespace Drupal\surface\Resource\Attribute;

/**
 * On a property: an anonymous map, its keys a plain class's properties.
 *
 * The spelling of addMap(). The class the property is typed with, or
 * `of:`, carries no #[Structure]: its properties are the map's keys, read
 * the way this class's are. When the map needs a frame (a child, a rule,
 * a port), the build runs the owner's declaration again, and the reader
 * reads the same properties again, since it reads no value.
 *
 * @code
 * #[Map('Coordinates')]
 * public Coordinates $coordinates;
 * @endcode
 *
 * @see \Drupal\surface\Resource\BlueprintAdditionsInterface::addMap()
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Map {

  /**
   * Constructs a Map attribute.
   *
   * @param string|\Stringable $label
   *   The map's label, written as #[Input]'s is.
   * @param string|\Stringable|null $description
   *   Its description; NULL for none.
   * @param class-string|null $of
   *   The plain class whose properties are the map's keys; NULL for the
   *   property's type.
   */
  public function __construct(
    public readonly string|\Stringable $label,
    public readonly string|\Stringable|null $description = NULL,
    public readonly ?string $of = NULL,
  ) {}

}
