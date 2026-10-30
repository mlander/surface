<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Compute\Broken;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\ComputesValueInterface;

/**
 * Computes a key from the clock: refused by the guard when it runs.
 */
final class ClockStamp implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?DataDefinitionInterface $key = NULL): mixed {
    return ($from['first'] ?? '') . '-' . \Drupal::time()->getRequestTime();
  }

}
