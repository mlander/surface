<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Describe;
use Drupal\surface\Resource\Attribute\Group;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\DeclaresByProperties;

/**
 * A key drawn in a group the class does not declare.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
#[Group('main', 'Main')]
final class UnknownGroup {

  use DeclaresByProperties;

  #[Input('Aside')]
  #[Describe(group: 'side')]
  public ?string $aside = NULL;

}
