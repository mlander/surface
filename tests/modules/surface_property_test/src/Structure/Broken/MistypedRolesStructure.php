<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Each;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Keys\Letters;
use PortableSchema\Builder\Attribute\Schema;

/**
 * A role per letter, typed as an integer, which a role's id is not.
 *
 * Refused when built: PropertyDeclarationTest builds it.
 */
#[Structure('property_test.broken.mistyped_roles')]
final class MistypedRolesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Each('integer', from: Letters::class, label: 'Roles')]
  #[Schema(additionalProperties: ['portable-check' => ['drupal:SurfaceValidReference' => ['entityTypeId' => 'user_role']]])]
  public array $roles;

}
