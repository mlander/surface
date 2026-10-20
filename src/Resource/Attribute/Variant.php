<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource\Attribute;

/**
 * Says which variants this structure is one of, and for which value.
 *
 * Variants are the structures a sibling key chooses between, attached at
 * one key with attachVariants(); each is the variant for one value of the
 * deciding key. The child declares it, so the parent never has to know
 * its children: a new field type brings its own settings structure.
 *
 * Discovery collects it, and the build step adds this structure to the
 * variants at the key it names, for the value it names.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Variant {

  /**
   * Constructs a Variant attribute.
   *
   * @param class-string $of
   *   The resource or structure that attaches the variants.
   * @param string $at
   *   The key on it the variants are attached at.
   * @param string $for
   *   The value of the deciding key this structure is the variant for.
   */
  public function __construct(
    public readonly string $of,
    public readonly string $at,
    public readonly string $for,
  ) {}

}
