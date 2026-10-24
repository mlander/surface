<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Fixtures;

use Drupal\data_surface\Resource\DeriverInterface;

/**
 * A deriver for StaticRefinerRuleTest that reads the clock: flagged.
 */
final class DeriverFixture implements DeriverInterface {

  /**
   * {@inheritdoc}
   */
  public static function derive(array $from): mixed {
    return ($from['label'] ?? '') . \Drupal::time()->getRequestTime();
  }

  /**
   * Static, and no deriver: not flagged.
   */
  public static function helper(): int {
    return \Drupal::time()->getRequestTime();
  }

}
