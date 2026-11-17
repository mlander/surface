<?php

declare(strict_types=1);

namespace Drupal\surface_test\Structure;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * The test condition's configuration: a reading against a threshold.
 *
 * Named by SurfaceTestCondition with #[UsesStructure]. Deliberately
 * context free: it compares two of its own values, so a test can
 * evaluate the condition without gathering contexts.
 */
#[Structure('test.condition')]
final class TestConditionStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->addSchema('mode', Schema::options([
      'at_least' => t('At least'),
      'at_most' => t('At most'),
    ])
      ->title(t('Comparison'))
      ->default('at_least'));
    $inputs->addSchema('threshold', Schema::integer()
      ->title(t('Threshold'))
      ->description(t('The number the reading is compared against.'))
      ->default(10)
      ->minimum(0)
      ->maximum(100));
    $inputs->addSchema('reading', Schema::integer()
      ->title(t('Reading'))
      ->description(t('The number the condition tests.'))
      ->default(0)
      ->minimum(0)
      ->maximum(100));
  }

  /**
   * In the at most mode, a threshold of zero would refuse every reading.
   *
   * Narrowing, not widening: the advertised range already allowed
   * everything the refined one allows.
   */
  #[RefinesInput('threshold')]
  public static function atLeastOneAtMost(string $mode): Schema {
    if ($mode !== 'at_most') {
      return Schema::any();
    }
    return Schema::any()->minimum(1)->description(t('The number no reading may pass.'));
  }

}
