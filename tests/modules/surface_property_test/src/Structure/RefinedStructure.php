<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A key a refiner narrows, with calls: RefinedPropertiesStructure's twin.
 */
#[Structure('property_test.refined')]
final class RefinedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', Schema::enum(['small', 'large'])
      ->title(t('Size'))
      ->default('small'));

    $inputs->add('limit', Schema::integer()
      ->title(t('Limit'))
      ->default(5)
      ->minimum(1)
      ->maximum(100));
  }

  /**
   * A small one holds up to ten.
   */
  #[RefinesInput('limit')]
  public static function smallHoldsTen(string $size): Schema {
    return $size === 'small' ? Schema::any()->minimum(1)->maximum(10) : Schema::any();
  }

}
