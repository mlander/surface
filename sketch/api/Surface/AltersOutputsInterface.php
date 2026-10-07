<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

/**
 * An alter that adds outputs. Optional, as HasOutputsInterface is.
 */
interface AltersOutputsInterface {

  public function alterOutputs(ShapeAdditionsInterface $outputs): void;

}
