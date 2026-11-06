<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Attach;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface_property_test\Structure\ContactStructure;

/**
 * A property that says it is both a value and a child.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class TwoKinds {

  use DeclaresByProperties;

  #[Input('Contact')]
  #[Attach(label: 'Contact')]
  public ContactStructure $contact;

}
