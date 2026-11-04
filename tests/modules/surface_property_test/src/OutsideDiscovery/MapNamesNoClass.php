<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Map;
use Drupal\surface\Resource\DeclaresByProperties;

/**
 * A map whose property names no class, and whose attribute names none.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class MapNamesNoClass {

  use DeclaresByProperties;

  #[Map('Where')]
  public array $where;

}
