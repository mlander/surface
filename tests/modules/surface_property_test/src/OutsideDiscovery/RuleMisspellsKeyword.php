<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Rule;
use Drupal\surface\Resource\DeclaresByProperties;

/**
 * A rule whose keyword is misspelled.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class RuleMisspellsKeyword {

  use DeclaresByProperties;

  #[Input('Note')]
  public ?string $note = NULL;

  #[Input('Code')]
  #[Rule(whenPresent: 'note', constrain: ['maxLenght' => 4])]
  public ?string $code = NULL;

}
