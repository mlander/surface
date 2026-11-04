<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\MixIn;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;

/**
 * A structure mixed in after the keys, as properties: MixInStructure's twin.
 */
#[Structure('property_test.mixin.properties')]
#[MixIn(AudienceStructure::class, reads: ['audience' => 'visibility'])]
final class MixInPropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Input('Visibility')]
  #[Constraint('Choice', ['choices' => ['public', 'private']])]
  public string $visibility = 'public';

  #[Input('Title')]
  public string $title;

}
