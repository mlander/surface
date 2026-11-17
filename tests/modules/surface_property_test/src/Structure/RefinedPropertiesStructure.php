<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Attribute\Check;
use PortableSchema\Builder\Attribute\Schema as SchemaAttribute;
use PortableSchema\Builder\Schema;

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
  #[Check('drupal:Choice', ['choices' => ['small', 'large']])]
  public string $size = 'small';

  #[Input('Limit')]
  #[SchemaAttribute(minimum: 1, maximum: 100)]
  public int $limit = 5;

  /**
   * A small one holds up to ten.
   */
  #[RefinesInput('limit')]
  public static function smallHoldsTen(string $size): Schema {
    return $size === 'small' ? Schema::any()->minimum(1)->maximum(10) : Schema::any();
  }

}
