<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Structure;

use Drupal\surface\Refinement\Condition;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;

/**
 * A reviewer, mixed into both MixIn twins, reading who it is for.
 */
#[Structure('property_test.audience')]
final class AudienceStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->reads('audience', 'string');

    $inputs->add('reviewer', 'string', t('Reviewer'));

    $inputs->narrow('reviewer')
      ->when(Condition::equals('audience', 'private'))
      ->doesNotApply();
  }

}
