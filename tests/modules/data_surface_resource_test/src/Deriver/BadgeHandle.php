<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Deriver;

use Drupal\data_surface\Resource\DeriverInterface;

/**
 * A badge's handle: the first and last names, lower case, joined by a dot.
 */
final class BadgeHandle implements DeriverInterface {

  /**
   * {@inheritdoc}
   */
  public static function derive(array $from): mixed {
    $parts = array_filter([(string) ($from['first'] ?? ''), (string) ($from['last'] ?? '')], static fn (string $part): bool => $part !== '');
    return $parts === [] ? NULL : strtolower(implode('.', $parts));
  }

}
