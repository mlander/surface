<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Compute;

use Drupal\surface\Resource\ComputesValueInterface;
use PortableSchema\Builder\Schema;

/**
 * A badge's code: the first three letters of the first name, in capitals.
 */
final class BadgeCode implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?Schema $key = NULL): mixed {
    $first = (string) ($from['first'] ?? '');
    return $first === '' ? NULL : strtoupper(substr($first, 0, 3));
  }

}
