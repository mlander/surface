<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Deriver\Broken;

use Drupal\data_surface\Resource\DeriverInterface;

/**
 * A deriver that reads the clock: refused by the guard when it runs.
 */
final class ClockDeriver implements DeriverInterface {

  /**
   * {@inheritdoc}
   */
  public static function derive(array $from): mixed {
    return ($from['first'] ?? '') . '-' . \Drupal::time()->getRequestTime();
  }

}
