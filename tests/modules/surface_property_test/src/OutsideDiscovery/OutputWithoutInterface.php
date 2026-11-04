<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Output;
use Drupal\surface\Resource\DeclaresByProperties;

/**
 * An output on a class that does not implement DefinesOutputsInterface.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class OutputWithoutInterface {

  use DeclaresByProperties;

  #[Output('Total')]
  public int $total;

}
