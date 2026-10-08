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
 * A surface is never instantiated. Its shape is a property of the class,
 * so defineInputs() is static and the build step calls it on the class;
 * its #[RefinesInput] methods are static too, and what rides along in a
 * cached form is the class name, not an object. That is also what lets
 * a plugin class be its own surface (#[UsesSurface] with no argument)
 * without anything constructing the plugin to ask.
 *
 * @see \Drupal\data_surface\SurfaceBuild\SurfacesInterface::build()
 */
interface SurfaceInterface {

  /**
   * Shape. Keys, types, labels, defaults, and the subsurfaces attached.
   *
   * Takes nothing but the shape to fill: no values, no context, no
   * services, and static, so the language holds it to that. A shape is
   * the same wherever it is asked for; what differs per situation is
   * applied afterwards by the framework. Anything that reads another
   * key's value is refinement, and is a #[RefinesInput] method.
   *
   * Labels are built with the global t(): there is no instance for a
   * translation service to be injected into, and t() returns the same
   * lazy TranslatableMarkup, which string extraction finds.
   *
   * @param \Drupal\data_surface\Surface\ShapeInterface $inputs
   *   The input shape to fill.
   */
  public static function defineInputs(ShapeInterface $inputs): void;

}
