<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\DeclaresByProperties;
use PortableSchema\Builder\Attribute\Schema;

/**
 * A key spelled with the package's attributes and with Surface's.
 *
 * Outside discovery: SchemaAttributesTest reads it into a blueprint.
 */
final class SpelledTwice {

  use DeclaresByProperties;

  #[Schema(title: 'Name', maxLength: 64)]
  #[Input('Name')]
  public string $name;

}
