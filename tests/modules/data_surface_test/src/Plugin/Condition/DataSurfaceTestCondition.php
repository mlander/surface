<?php

declare(strict_types=1);

namespace Drupal\data_surface_test\Plugin\Condition;

use Drupal\Core\Condition\Attribute\Condition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface_test\Surface\TestConditionSurface;
use Drupal\data_surface\Plugin\Condition\DataSurfaceConditionBase;

/**
 * A condition that adopts surfaces and says nothing else about settings.
 *
 * #[UsesSurface] and the two methods the condition host asks for; the
 * negate checkbox core owns is still there, because the base class keeps
 * the host's own half.
 *
 * Deliberately context free: it compares two of its own values, so the
 * test can evaluate it without gathering contexts.
 */
#[Condition(
  id: 'data_surface_test_condition',
  label: new TranslatableMarkup('Data surface test condition'),
)]
#[UsesSurface(TestConditionSurface::class)]
final class DataSurfaceTestCondition extends DataSurfaceConditionBase {

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
