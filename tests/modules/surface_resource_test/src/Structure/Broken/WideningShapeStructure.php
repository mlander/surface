<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use Drupal\surface_resource_test\Shape\Sku;
use PortableSchema\Builder\Schema;

/**
 * Lets a stock code run to forty, past the twelve the shape allows.
 */
#[Structure('resource_test.broken.widening_shape')]
final class WideningShapeStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('sku', Schema::of(Sku::class)
      ->title('Stock code')
      ->check('drupal:Length', ['max' => 40]));
  }

}
