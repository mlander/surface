<?php

declare(strict_types=1);

namespace Drupal\data_surface\SurfaceBuild;

/**
 * Fills one open slot for the values no #[SurfaceVariant] fills.
 *
 * An open slot is filled by every surface marked #[SurfaceVariant] for
 * it. A slot whose deciding values come from somewhere no surface class
 * answers for — every field type a site has, most of which declare no
 * settings surface — can still say what each of them holds, from a
 * description that already exists: a field type's config schema. An
 * implementation is that description, read as plain core definitions;
 * the build step seals each value's definitions into a child, in the
 * slot's variant table beside the declared ones, emitted the same way.
 *
 * A derived child is a shape and nothing else: no situations, no alters,
 * no refiners, no target and no access class. Its values are stored by
 * the parent under the slot's key, and a declared variant always wins
 * over a derived one for the same value.
 *
 * A service tagged `data_surface.derived_variants`.
 */
interface DerivedVariantsInterface {

  /**
   * Names the slot this fills.
   *
   * @return array{0: class-string<\Drupal\data_surface\Surface\SurfaceInterface>, 1: string}
   *   The surface class and the slot's key.
   */
  public function slot(): array;

  /**
   * Says where the derived variants come from, for the catalogue.
   *
   * @return string
   *   One phrase, such as "the config schema of each field type's
   *   settings".
   */
  public function source(): string;

  /**
   * Describes every value this derives a variant for.
   *
   * @param string[] $declared
   *   The deciding values a declared variant already fills, which this
   *   leaves out.
   *
   * @return array<string, array<string, \Drupal\Core\TypedData\DataDefinitionInterface>>
   *   Fresh definitions keyed by key, keyed by deciding value, in the
   *   order they are offered. Fresh on every call: the build step seals
   *   what it is handed.
   */
  public function variants(array $declared): array;

}
