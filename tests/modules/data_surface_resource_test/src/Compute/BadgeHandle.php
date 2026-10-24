<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Compute;

use Drupal\data_surface\Resource\ComputesValueInterface;

/**
 * A badge's handle: the first and last names, lower case, joined by a dot.
 */
final class BadgeHandle implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from): mixed {
    $parts = array_filter([(string) ($from['first'] ?? ''), (string) ($from['last'] ?? '')], static fn (string $part): bool => $part !== '');
    return $parts === [] ? NULL : strtolower(implode('.', $parts));
  }

}
