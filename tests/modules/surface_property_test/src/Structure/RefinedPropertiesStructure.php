<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\Constraint;
use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;

/**
 * A key a refiner narrows, as properties: RefinedStructure's twin.
 *
 * The refiner is the same static method: the properties replace
 * defineInputs() alone.
 */
#[Structure('property_test.refined.properties')]
final class RefinedPropertiesStructure implements StructureInterface {

  use DeclaresByProperties;

  #[Input('Size')]
  #[Constraint('Choice', ['choices' => ['small', 'large']])]
  public string $size = 'small';

  #[Input('Limit')]
  #[Constraint('Range', ['min' => 1, 'max' => 100])]
  public int $limit = 5;

  /**
   * A small one holds up to ten.
   */
  #[RefinesInput('limit')]
  public static function smallHoldsTen(DataDefinitionInterface $limit, string $size): DataDefinitionInterface {
    return $size === 'small' ? $limit->addConstraint('Range', ['min' => 1, 'max' => 10]) : $limit;
  }

}
