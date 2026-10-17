<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource\Attribute;

/**
 * Says which open slot this structure fills, and for which value.
 *
 * The child declares it, so the parent never has to know its children:
 * a new field type brings its own settings structure.
 *
 * Discovery collects it, and the build step fills the open slot it names
 * with this structure, for the value it names.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Variant {

  /**
   * Constructs a Variant attribute.
   *
   * @param class-string $of
   *   The resource or structure with the slot.
   * @param string $key
   *   The slot's key on it.
   * @param string $value
   *   The value of the slot's deciding key this structure is for.
   */
  public function __construct(
    public readonly string $of,
    public readonly string $key,
    public readonly string $value,
  ) {}

}
