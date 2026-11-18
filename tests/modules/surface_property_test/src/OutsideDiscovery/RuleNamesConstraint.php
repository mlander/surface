<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\OutsideDiscovery;

use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Rule;
use Drupal\surface\Resource\DeclaresByProperties;

/**
 * A rule that names a constraint and its options where keywords go.
 *
 * Outside discovery: PropertyDeclarationTest reads it into a blueprint.
 */
final class RuleNamesConstraint {

  use DeclaresByProperties;

  #[Input('Note')]
  public ?string $note = NULL;

  #[Input('Code')]
  #[Rule(whenPresent: 'note', constrain: ['Length' => ['max' => 4]])]
  public ?string $code = NULL;

}
