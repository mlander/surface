<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_resource_test\Shape\Sku;

/**
 * Lets a stock code run to forty, past the twelve the shape allows.
 */
#[Structure('resource_test.broken.widening_shape')]
final class WideningShapeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('sku', Sku::class, 'Stock code')
      ->addConstraint('Length', ['max' => 40]);
  }

}
