<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Map;

use Drupal\surface\Resource\Attribute\Input;
use PortableSchema\Builder\Attribute\Schema;

/**
 * The keys of an anonymous map of plain values: no structure, no frame.
 */
final class Coordinates {

  #[Input('Latitude')]
  #[Schema(minimum: -90, maximum: 90)]
  public float $lat;

  #[Input('Longitude')]
  #[Schema(minimum: -180, maximum: 180)]
  public float $lng;

}
