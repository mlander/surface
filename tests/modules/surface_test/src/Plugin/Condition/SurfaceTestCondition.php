<?php

declare(strict_types=1);

namespace Drupal\surface_test\Plugin\Condition;

use Drupal\Core\Condition\Attribute\Condition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\surface\Resource\Attribute\UsesStructure;
use Drupal\surface_test\Structure\TestConditionStructure;
use Drupal\surface\Plugin\Condition\TypedConditionPluginBase;

/**
 * A condition that adopts structures and says nothing else about settings.
 *
 * #[UsesStructure] and the two methods the condition host asks for; the
 * negate checkbox core owns is still there, because the base class keeps
 * the host's own half.
 *
 * Deliberately context free: it compares two of its own values, so the
 * test can evaluate it without gathering contexts.
 */
#[Condition(
  id: 'surface_test_condition',
  label: new TranslatableMarkup('Data surface test condition'),
)]
#[UsesStructure(TestConditionStructure::class)]
final class SurfaceTestCondition extends TypedConditionPluginBase {

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
   * The word standing for the stored mode is read from the structure's own
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
    $options = \Drupal::service('surface.options')
      ->resolve($this->getSurface()->getDefinition('mode'))
      ->options ?? [];
    return $this->t('@mode @threshold', [
      '@mode' => $options[$configuration['mode']] ?? $configuration['mode'],
      '@threshold' => $configuration['threshold'],
    ]);
  }

}
