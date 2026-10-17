<?php

declare(strict_types=1);

namespace Drupal\data_surface\Resource;

/**
 * An alter that adds outputs. Optional, as HasOutputsInterface is.
 */
interface AltersOutputsInterface {

  /**
   * Adds outputs to the resource or structure named on #[Alters].
   *
   * @param \Drupal\data_surface\Resource\ShapeAdditionsInterface $outputs
   *   The output shape, add-only.
   */
  public function alterOutputs(ShapeAdditionsInterface $outputs): void;

}
