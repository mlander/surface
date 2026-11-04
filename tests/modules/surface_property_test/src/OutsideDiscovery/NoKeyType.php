<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\DeclaresByProperties;

/**
 * An array that is no list: no key type, and no type: given.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class NoKeyType {

  use DeclaresByProperties;

  #[Input('Tags')]
  public array $tags = [];

}
