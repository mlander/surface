<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Broken;

use Drupal\surface\Refinement\DoesNotApply;
use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
   * @return \PortableSchema\Builder\Schema|\Drupal\surface\Refinement\DoesNotApply
   *   Nothing to add, or that the send day does not apply.
   */
  #[RefinesInput('send_day')]
  // phpcs:ignore Drupal.Commenting.FunctionComment.MissingReturnType
  public static function quietly(string $frequency) {
    return $frequency === 'weekly' ? Schema::any() : DoesNotApply::here();
  }

}
