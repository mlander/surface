<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure\Workshop;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

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
    $inputs->add('enabled', Schema::bool()
      ->title(t('In use'))
      ->default(TRUE));
    $inputs->add('hours', Schema::integer()
      ->title(t('Hours a day'))
      ->default(1)
      ->minimum(1)
      ->maximum(12));
    $inputs->narrow('hours')
      ->when(Condition::equals('enabled', FALSE))
      ->doesNotApply();
  }

}
