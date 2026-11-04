<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\Input;
use Drupal\surface\Resource\Attribute\Output;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DeclaresByProperties;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_property_test\Item\Listed;

/**
 * Outputs of a value and of a list, as properties: OutputsStructure's twin.
 */
#[Structure('property_test.outputs.properties')]
final class OutputsPropertiesStructure implements StructureInterface, DefinesOutputsInterface {

  use DeclaresByProperties;

  #[Input('Query')]
  public ?string $query = NULL;

  #[Output('Total', description: 'How many matched.')]
  public int $total;

  #[Output('Items', list: Listed::class)]
  public array $items;

}
