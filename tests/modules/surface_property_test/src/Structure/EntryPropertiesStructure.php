<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Port;
use Drupal\surface\Resource\Attribute\Rule;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;

/**
 * A limit while it is selling, as properties: EntryStructure's twin.
 */
#[Structure('property_test.entry.properties')]
final class EntryPropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Port]
  public bool $selling;

  #[Input('Most')]
  #[Constraint('Range', ['min' => 0])]
  #[Rule(whenEquals: ['selling', FALSE], doesNotApply: TRUE)]
  public int $max = 10;

}
