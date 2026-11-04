<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\DeclaresByProperties;

/**
 * A public property that says nothing of what it is.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class NoAttribute {

  use DeclaresByProperties;

  public string $loose;

}
