<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\DeclaresByProperties;

/**
 * A key of the type any, which add() itself refuses.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class AnyKey {

  use DeclaresByProperties;

  #[Input('Anything', type: 'any')]
  public mixed $anything = NULL;

}
