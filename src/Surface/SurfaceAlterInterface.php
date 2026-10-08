<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

/**
 * An alter that adds inputs to a surface it does not own.
 *
 * What makes a class an alter is #[AltersSurface] on it. This interface
 * is for one that adds inputs; AltersOutputsInterface for one that adds
 * outputs; #[RefinesInput] methods for one that tightens. A class may do any
 * of the three. Never change or remove what the owner declared.
 *
 * What an alter adds is mounted under the alter's own module name, at
 * third_party_settings.<module>.<key> for inputs and
 * third_party_outputs.<module>.<key> for outputs, which is where the
 * engine keeps every contribution so that the owner's storage and schema
 * never have to know a contributor's keys.
 */
interface SurfaceAlterInterface {

  /**
   * Adds inputs to the surface named on #[AltersSurface].
   *
   * @param \Drupal\data_surface\Surface\ShapeAdditionsInterface $inputs
   *   The input shape, add-only.
   */
  public function alterInputs(ShapeAdditionsInterface $inputs): void;

}
