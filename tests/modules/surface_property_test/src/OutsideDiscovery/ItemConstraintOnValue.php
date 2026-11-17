<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\DeclaresByProperties;
use PortableSchema\Builder\Attribute\Schema;

/**
 * An item's rule on a key that is no list.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class ItemConstraintOnValue {

  use DeclaresByProperties;

  #[Input('Name')]
  #[Schema(items: ['maxLength' => 8])]
  public ?string $name = NULL;

}
