<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Map;

use Drupal\surface\Resource\Attribute\Attach;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface_property_test\Structure\EntryPropertiesStructure;

/**
 * The keys of an anonymous map that needs a frame: a child is attached.
 *
 * The build reads these properties twice, once into a plain map, stopped
 * at the child, and again into the map's own structure.
 */
final class Booking {

  #[Input('Seats')]
  public int $seats = 1;

  #[Attach(label: 'Row')]
  public EntryPropertiesStructure $row;

}
