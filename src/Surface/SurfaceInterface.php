<?php

declare(strict_types=1);

namespace Drupal\data_surface\Surface;

/**
 * One surface: what it asks for.
 *
 * How those values tighten each other is beside it, one #[RefinesInput]
 * method per refined key. Open the class and you know the whole
 * contract. A surface that also answers with something adds
 * HasOutputsInterface.
 *
 * A surface is a plain object with no constructor: it holds no services,
 * which is what lets one shared instance serve every build and ride
 * along, as the refiner it carries, in a cached form.
 *
 * @see \Drupal\data_surface\SurfaceBuild\SurfacesInterface::build()
 */
interface SurfaceInterface {

  /**
   * Shape. Keys, types, labels, defaults, and the subsurfaces attached.
   *
   * Takes nothing but the shape to fill: no values, no context. A shape
   * is the same wherever it is asked for; what differs per situation is
   * applied afterwards by the framework. Anything that reads another
   * key's value is refinement, and is a #[RefinesInput] method.
   *
   * @param \Drupal\data_surface\Surface\ShapeInterface $inputs
   *   The input shape to fill.
   */
  public function defineInputs(ShapeInterface $inputs): void;

}
