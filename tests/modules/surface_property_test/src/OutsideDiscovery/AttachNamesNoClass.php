<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Attach;
use Drupal\surface\Resource\DeclaresByProperties;

/**
 * A child whose property names no class, and whose attribute names none.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class AttachNamesNoClass {

  use DeclaresByProperties;

  #[Attach(label: 'Contact')]
  public array $contact;

}
