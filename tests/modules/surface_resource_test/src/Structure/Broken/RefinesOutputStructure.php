<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\DefinesOutputsInterface;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * Has a refiner naming an output key.
 */
#[Structure('resource_test.broken.refines_output')]
final class RefinesOutputStructure implements StructureInterface, DefinesOutputsInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('count', Schema::integer()
      ->title('Count'));
  }

  /**
   * {@inheritdoc}
   */
  public static function defineOutputs(BlueprintInterface $outputs): void {
    $outputs->add('total', Schema::integer()
      ->title('Total'));
  }

  /**
   * Tries to refine an output.
   */
  #[RefinesInput('total')]
  public static function totalOfCount(int $count): Schema {
    return Schema::any();
  }

}
