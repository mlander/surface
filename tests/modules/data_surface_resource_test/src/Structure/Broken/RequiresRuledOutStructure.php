<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Requires, by a rule, a key a refiner may rule out.
 */
#[Structure('resource_test.broken.requires_ruled_out')]
final class RequiresRuledOutStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('mode', 'string', 'Mode');
    $inputs->add('detail', 'string', 'Detail');
    $inputs->narrow('detail')
      ->when(Condition::present('mode'))
      ->require();
  }

  /**
   * A detail applies only in the detailed mode.
   */
  #[RefinesInput('detail')]
  public static function detailOnlyWhenDetailed(DataDefinitionInterface $detail, string $mode): DataDefinitionInterface|DoesNotApply {
    return $mode === 'detailed' ? $detail : DoesNotApply::here();
  }

}
