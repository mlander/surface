<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Fixtures;

use Drupal\surface\Resource\ComputesValueInterface;
use PortableSchema\Builder\Schema;

/**
 * Computes a key for StaticRefinerRuleTest, reading the clock: flagged.
 */
final class ComputeFixture implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?Schema $key = NULL): mixed {
    return ($from['label'] ?? '') . \Drupal::time()->getRequestTime();
  }

  /**
   * Static, and no compute(): not flagged.
   */
  public static function helper(): int {
    return \Drupal::time()->getRequestTime();
  }

}
