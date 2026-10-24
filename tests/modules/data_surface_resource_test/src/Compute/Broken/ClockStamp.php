<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Compute\Broken;

use Drupal\data_surface\Resource\ComputesValueInterface;

/**
 * Computes a key from the clock: refused by the guard when it runs.
 */
final class ClockStamp implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from): mixed {
    return ($from['first'] ?? '') . '-' . \Drupal::time()->getRequestTime();
  }

}
