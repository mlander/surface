<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Refinement\DoesNotApply;
use Drupal\data_surface\Resource\Attribute\RefinesInput;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * Says a key does not apply without saying so in its return type.
 */
#[Structure('resource_test.broken.undeclared_does_not_apply')]
final class UndeclaredDoesNotApplyStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('frequency', 'string', 'Frequency')
      ->addConstraint('Choice', ['choices' => ['weekly', 'monthly']]);
    $inputs->add('send_day', 'string', 'Send day');
  }

  /**
   * Rules the send day out, with no return type to say it may.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|\Drupal\data_surface\Refinement\DoesNotApply
   *   The send day, or that it does not apply.
   */
  #[RefinesInput('send_day')]
  // phpcs:ignore Drupal.Commenting.FunctionComment.MissingReturnType
  public static function quietly(DataDefinitionInterface $send_day, string $frequency) {
    return $frequency === 'weekly' ? $send_day : DoesNotApply::here();
  }

}
