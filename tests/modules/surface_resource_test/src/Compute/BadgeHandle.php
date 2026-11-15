<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Compute;

use Drupal\surface\Resource\ComputesValueInterface;
use PortableSchema\Builder\Schema;

/**
 * A badge's handle: the first and last names, lower case, joined by a dot.
 */
final class BadgeHandle implements ComputesValueInterface {

  /**
   * The node the last call was handed, for the test that reads it.
   */
  public static ?Schema $handed = NULL;

  /**
   * Gets the node the last call was handed.
   *
   * @return \PortableSchema\Builder\Schema|null
   *   The node, or NULL when no call was handed one.
   */
  public static function handed(): ?Schema {
    return static::$handed;
  }

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?Schema $key = NULL): mixed {
    static::$handed = $key;
    $parts = array_filter([(string) ($from['first'] ?? ''), (string) ($from['last'] ?? '')], static fn (string $part): bool => $part !== '');
    return $parts === [] ? NULL : strtolower(implode('.', $parts));
  }

}
