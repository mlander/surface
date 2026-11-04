<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Resource;

use Drupal\surface\Compute\MachineName;
use Drupal\surface\Resource\Attribute\Computed;
use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Resource;
use Drupal\surface\Resource\Compute;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\ResourceInterface;
use Drupal\surface_property_test\Compute\Initials;

/**
 * Two computed keys, as properties: ComputedResource's twin.
 *
 * Both computed properties are non-nullable with no initializer, and
 * neither is required: a computed key is never required by inference.
 */
#[Resource('property_test.computed.properties', identity: ['id'])]
final class ComputedPropertiesResource implements ResourceInterface {

  use DeclaresByProperties;

  #[Input('Name')]
  #[Constraint('Length', ['max' => 64])]
  public string $label;

  #[Computed('Machine name', from: ['label'], using: MachineName::class, type: 'machine_name', when: Compute::UNLESS_SENT, description: 'Computed from the name unless one is given.')]
  #[Constraint('Length', ['max' => 32])]
  public string $id;

  #[Computed('Initials', from: ['label'], using: Initials::class)]
  public string $initials;

}
