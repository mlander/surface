<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Structure;

use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;
use Drupal\data_surface_examples\Access\FeesAccess;

/**
 * Example 10: the program's fees, whose access class answers for them.
 */
#[Structure('event.fees', access: FeesAccess::class)]
final class FeesStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('standard', 'float', t('Standard fee'), default: 20.0)
      ->addConstraint('Range', ['min' => 0]);
    $inputs->add('concession', 'float', t('Concession fee'), default: 10.0)
      ->addConstraint('Range', ['min' => 0]);
  }

}
