<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Depot;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Shape\Sku;

/**
 * A shelf tag: a label printed beside a stock code it reads.
 *
 * Its port reads a shape, so whatever attaches it wires the port to a key
 * declared with the stock code.
 */
#[Structure('resource_test.shelf_tag')]
final class ShelfTagStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('code', Sku::class, required: TRUE);
    $inputs->add('label', 'string', t('Label'))
      ->addConstraint('Length', ['max' => 20]);
  }

  /**
   * The label says which code it is printed beside.
   */
  #[RefinesInput('label')]
  public static function besideTheCode(DataDefinition $label, string $code): DataDefinition {
    return $label->setDescription(t('Printed beside @code.', ['@code' => $code]));
  }

}
