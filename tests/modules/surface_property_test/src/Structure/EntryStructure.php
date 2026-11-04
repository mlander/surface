<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A limit while it is selling, with calls: EntryPropertiesStructure's twin.
 */
#[Structure('property_test.entry')]
final class EntryStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('selling', 'boolean');

    $inputs->add('max', 'integer', t('Most'), default: 10)
      ->addConstraint('Range', ['min' => 0]);

    $inputs->narrow('max')
      ->when(Condition::equals('selling', FALSE))
      ->doesNotApply();
  }

}
