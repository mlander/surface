<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Port;
use Drupal\surface\Resource\Attribute\Rule;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;

/**
 * A contact reading two ports, as properties: ContactStructure's twin.
 */
#[Structure('property_test.contact.properties')]
final class ContactPropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Port]
  public bool $listed;

  #[Port(type: 'any', required: TRUE)]
  public mixed $anything;

  #[Input('Name')]
  public string $name;

  #[Input('Email', type: 'email')]
  #[Rule(whenEquals: ['listed', TRUE], require: TRUE, because: 'Required while the contact is listed.')]
  public ?string $email = NULL;

}
