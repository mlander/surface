<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

/**
 * An alter that adds inputs to a surface it does not own.
 *
 * What makes a class an alter is #[AltersSurface] on it. This interface
 * is for one that adds inputs; AltersOutputsInterface for one that adds
 * outputs; #[RefinesInput] methods for one that tightens. A class may do any
 * of the three. Never change or remove what the owner declared.
 */
interface SurfaceAlterInterface {

  public function alterInputs(ShapeAdditionsInterface $inputs): void;

}
