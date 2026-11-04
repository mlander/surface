<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Map;

use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Input;

/**
 * The keys of an anonymous map of plain values: no structure, no frame.
 */
final class Coordinates {

  #[Input('Latitude')]
  #[Constraint('Range', ['min' => -90, 'max' => 90])]
  public float $lat;

  #[Input('Longitude')]
  #[Constraint('Range', ['min' => -180, 'max' => 180])]
  public float $lng;

}
