<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Condition;

use Drupal\Core\Condition\Attribute\Condition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\data_surface\Attribute\DataSurfaceAware;
use Drupal\data_surface\Plugin\Condition\DataSurfaceConditionBase;

/**
 * A condition that adopts surfaces and says nothing else about settings.
 *
 * What a condition costs once the base class carries the pipeline: the
 * attribute declaring what it accepts, one refiner method for the
 * setting that depends on another, and the two methods the condition
 * host asks for. There is no defaultConfiguration, no
 * buildConfigurationForm, no validateConfigurationForm and no
 * submitConfigurationForm, and the negate checkbox core owns is still
 * there because the base class keeps the host's own half.
 *
 * Deliberately context free: it compares two of its own values, so the
 * test can evaluate it without gathering contexts.
 */
#[Condition(
  id: 'data_surface_test_condition',
  label: new TranslatableMarkup('Data surface test condition'),
)]
#[DataSurfaceAware(
  definitions: [
    'mode' => new DataDefinition([
      'type' => 'string',
      'label' => new TranslatableMarkup('Comparison'),
      'required' => FALSE,
      'default_value' => 'at_least',
      'constraints' => [
        'LabeledChoice' => [
          'choices' => ['at_least', 'at_most'],
          'labels' => [
            'at_least' => new TranslatableMarkup('At least'),
            'at_most' => new TranslatableMarkup('At most'),
          ],
        ],
      ],
    ]),
    'threshold' => new DataDefinition([
      'type' => 'integer',
      'label' => new TranslatableMarkup('Threshold'),
      'description' => new TranslatableMarkup('The number the reading is compared against.'),
      'required' => FALSE,
      'default_value' => 10,
      'constraints' => ['Range' => ['min' => 0, 'max' => 100]],
    ]),
    'reading' => new DataDefinition([
      'type' => 'integer',
      'label' => new TranslatableMarkup('Reading'),
      'description' => new TranslatableMarkup('The number the condition tests.'),
      'required' => FALSE,
      'default_value' => 0,
      'constraints' => ['Range' => ['min' => 0, 'max' => 100]],
    ]),
  ],
  refinements: [
    'threshold' => ['mode'],
  ],
)]
final class DataSurfaceTestCondition extends DataSurfaceConditionBase {

  /**
   * {@inheritdoc}
   *
   * A threshold of zero in the at most mode would refuse every reading,
   * so that mode narrows the threshold to one at the lowest. Narrowing,
   * not widening: the advertised range already allowed everything the
   * refined one allows.
   */
  public function refineDataDefinition(string $name, DataDefinitionInterface $definition, array $values): DataDefinitionInterface {
    if ($name !== 'threshold' || $values['mode'] !== 'at_most') {
      return $definition;
    }
    $definition->addConstraint('Range', ['min' => 1] + ($definition->getConstraints()['Range'] ?? []));
    if ($definition instanceof DataDefinition) {
      $definition->setDescription(new TranslatableMarkup('The number no reading may pass.'));
    }
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    $configuration = $this->getConfiguration();
    return $configuration['mode'] === 'at_most'
      ? $configuration['reading'] <= $configuration['threshold']
      : $configuration['reading'] >= $configuration['threshold'];
  }

  /**
   * {@inheritdoc}
   *
   * The word standing for the stored mode is read from the surface's own
   * option set, which is derived from the constraint that validates the
   * value. A summary cannot drift from what the setting means, because
   * there is only one place either of them is written down.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The summary.
   */
  public function summary() {
    $configuration = $this->getConfiguration();
    // @phpstan-ignore globalDrupalDependencyInjection.useDependencyInjection
    $options = \Drupal::service('data_surface.options')
      ->resolve($this->getDataSurface()->getDefinition('mode'))
      ->options ?? [];
    return $this->t('@mode @threshold', [
      '@mode' => $options[$configuration['mode']] ?? $configuration['mode'],
      '@threshold' => $configuration['threshold'],
    ]);
  }

}
