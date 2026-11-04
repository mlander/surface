<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Map;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Map\Booking;
use Drupal\surface_property_test\Map\Coordinates;

/**
 * Two anonymous maps, as properties: MapsStructure's twin.
 *
 * The booking's class attaches the property-declared entry, so its map is
 * MapsStructure's but for the child's id.
 */
#[Structure('property_test.maps.properties')]
final class MapsPropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Map('Coordinates', description: 'Where it is.')]
  public Coordinates $coordinates;

  #[Map('Booking')]
  public Booking $booking;

}
