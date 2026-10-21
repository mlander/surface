<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Structure\Workshop;

use Drupal\data_surface\Refinement\Condition;
use Drupal\data_surface\Resource\Attribute\Structure;
use Drupal\data_surface\Resource\BlueprintInterface;
use Drupal\data_surface\Resource\StructureInterface;

/**
 * One bench of the workshop: whether it is in use, and for how long.
 *
 * Held at every bench the site lists. Its declared rule applies at each
 * of them: a bench not in use has no hours.
 */
#[Structure('resource_test.workshop.bench')]
final class BenchStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('enabled', 'boolean', t('In use'), default: TRUE);
    $inputs->add('hours', 'integer', t('Hours a day'), default: 1)
      ->addConstraint('Range', ['min' => 1, 'max' => 12]);
    $inputs->narrow('hours')
      ->when(Condition::equals('enabled', FALSE))
      ->doesNotApply();
  }

}
