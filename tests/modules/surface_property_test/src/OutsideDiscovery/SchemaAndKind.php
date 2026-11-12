<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Port;
use Drupal\surface\Resource\DeclaresByProperties;
use PortableSchema\Builder\Attribute\Of;

/**
 * A key spelled as a schema that also says it is a port.
 *
 * Outside discovery: SchemaAttributesTest reads it into a blueprint.
 */
final class SchemaAndKind {

  use DeclaresByProperties;

  #[Of('portable:email')]
  #[Port]
  public string $email;

}
