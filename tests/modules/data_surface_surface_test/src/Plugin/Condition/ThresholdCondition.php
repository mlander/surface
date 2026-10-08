<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Plugin\Condition;

use Drupal\Core\Condition\Attribute\Condition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\data_surface\Plugin\Condition\DataSurfaceConditionBase;
use Drupal\data_surface\Surface\Attribute\UsesSurface;
use Drupal\data_surface_surface_test\Surface\ThresholdSurface;

/**
 * A condition whose configuration is a surface named by attribute.
 *
 * Its two methods are the condition host's, and nothing about settings:
 * the host reads #[UsesSurface] from the definition.
 */
#[Condition(
  id: 'data_surface_surface_test_threshold',
  label: new TranslatableMarkup('Threshold (surface test)'),
)]
#[UsesSurface(ThresholdSurface::class)]
final class ThresholdCondition extends DataSurfaceConditionBase {

  /**
   * {@inheritdoc}
   */
  public function evaluate() {
    $reading = (int) $this->configuration['reading'];
    $threshold = (int) $this->configuration['threshold'];
    return $this->configuration['mode'] === 'at_most' ? $reading <= $threshold : $reading >= $threshold;
  }

  /**
   * {@inheritdoc}
   */
  public function summary(): TranslatableMarkup {
    return $this->t('Reading @mode @threshold', [
      '@mode' => $this->configuration['mode'],
      '@threshold' => $this->configuration['threshold'],
    ]);
  }

}
