<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\HasOutputsInterface;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Has a refiner naming an output key.
 */
#[Structure('surface_test.broken.refines_output')]
final class RefinesOutputStructure implements StructureInterface, HasOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('count', 'integer', 'Count');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $outputs->add('total', 'integer', 'Total');
  }

  /**
   * Tries to refine an output.
   */
  #[RefinesInput('total')]
  public static function totalOfCount(DataDefinitionInterface $total, int $count): DataDefinitionInterface {
    return $total;
  }

}
