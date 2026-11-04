<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A key a refiner narrows, with calls: RefinedPropertiesStructure's twin.
 */
#[Structure('property_test.refined')]
final class RefinedStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('size', 'string', t('Size'), default: 'small')
      ->addConstraint('Choice', ['choices' => ['small', 'large']]);

    $inputs->add('limit', 'integer', t('Limit'), default: 5)
      ->addConstraint('Range', ['min' => 1, 'max' => 100]);
  }

  /**
   * A small one holds up to ten.
   */
  #[RefinesInput('limit')]
  public static function smallHoldsTen(DataDefinitionInterface $limit, string $size): DataDefinitionInterface {
    return $size === 'small' ? $limit->addConstraint('Range', ['min' => 1, 'max' => 10]) : $limit;
  }

}
