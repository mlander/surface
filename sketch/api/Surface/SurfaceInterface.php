<?php

declare(strict_types=1);

namespace Drupal\surface_sketch\Surface;

/**
 * One surface: what it asks for.
 *
 * How those values tighten each other is beside it, one #[RefinesInput]
 * method per refined key. Open the class and you know the whole
 * contract. A surface that also answers with something adds
 * HasOutputsInterface.
 */
interface SurfaceInterface {

  /**
   * Shape. Keys, types, labels, defaults, and the subsurfaces attached.
   *
   * Takes nothing but the shape to fill: no values, no context. A shape
   * is the same wherever it is asked for; what differs per situation is
   * applied afterwards by the framework. Anything that reads another
   * key's value is refinement, and is a #[RefinesInput] method.
   */
  public function defineInputs(ShapeInterface $inputs): void;

}
