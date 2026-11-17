<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Structure;

use Drupal\surface\Resource\Attribute\RefinesInput;
use Drupal\surface\Resource\Attribute\Structure;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\surface\Resource\StructureInterface;
use PortableSchema\Builder\Schema;

/**
 * A plugin's configuration: a reading compared with a threshold.
 *
 * Named by a condition and an action with #[UsesStructure], so one
 * structure is two plugins' configuration and each host supplies its own
 * context and storage. No storage, no operations: a plugin's structure
 * has neither.
 */
#[Structure('test.threshold')]
final class ThresholdStructure implements StructureInterface {

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(BlueprintInterface $inputs): void {
    $inputs->add('mode', 'string', t('Comparison'), default: 'at_least')
      ->addConstraint('LabeledChoice', [
        'choices' => [
          'at_least' => t('At least'),
          'at_most' => t('At most'),
        ],
      ]);
    $inputs->add('threshold', 'integer', t('Threshold'), default: 10)
      ->setDescription(t('The number the reading is compared against.'))
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
    $inputs->add('reading', 'integer', t('Reading'), default: 0)
      ->setDescription(t('The number the plugin tests.'))
      ->addConstraint('Range', ['min' => 0, 'max' => 100]);
  }

  /**
   * In the at most mode, a threshold of zero would refuse every reading.
   */
  #[RefinesInput('threshold')]
  public static function atLeastOneAtMost(string $mode): Schema {
    if ($mode !== 'at_most') {
      return Schema::any();
    }
    return Schema::any()->minimum(1)->description(t('The number no reading may pass.'));
  }

}
