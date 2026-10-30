<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * Has a refiner naming an output key.
 */
#[Structure('resource_test.broken.refines_output')]
final class RefinesOutputStructure implements StructureInterface, DefinesOutputsInterface {

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
