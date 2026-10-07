<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface\Attribute;

/**
 * Says which open slot this surface fills, and for which value.
 *
 * The child declares it, so the parent never has to know its children:
 * a new field type brings its own settings surface.
 *
 * Collected by discovery now; the slots it fills are built in step 2 of
 * the rework.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SurfaceVariant {

  /**
   * Constructs a SurfaceVariant attribute.
   *
   * @param class-string $of
   *   The surface with the slot.
   * @param string $key
   *   The slot's key on that surface.
   * @param string $value
   *   The value of the slot's deciding key this surface is for.
   */
  public function __construct(
    public readonly string $of,
    public readonly string $key,
    public readonly string $value,
  ) {}

}
