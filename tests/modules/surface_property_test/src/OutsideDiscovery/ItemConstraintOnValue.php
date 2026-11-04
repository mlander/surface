<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\DeclaresByProperties;

/**
 * An item constraint on a key that is no list.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class ItemConstraintOnValue {

  use DeclaresByProperties;

  #[Input('Name')]
  #[Constraint('Length', ['max' => 8], item: TRUE)]
  public ?string $name = NULL;

}
