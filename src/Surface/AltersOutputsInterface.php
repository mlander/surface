<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

/**
 * An alter that adds outputs. Optional, as HasOutputsInterface is.
 */
interface AltersOutputsInterface {

  /**
   * Adds outputs to the surface named on #[AltersSurface].
   *
   * @param \Drupal\data_surface\Surface\ShapeAdditionsInterface $outputs
   *   The output shape, add-only.
   */
  public function alterOutputs(ShapeAdditionsInterface $outputs): void;

}
