<?php

declare(strict_types=1);

namespace Drupal\surface_shell_test;

use Drupal\surface\Resource\ComputesValueInterface;
use PortableSchema\Builder\Schema;

/**
 * Computes a child's map from a string: its name is the string.
 *
 * The string `none` gives a map whose name is empty, which the child
 * refuses as required.
 */
final class NameFromSource implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?Schema $key = NULL): mixed {
    $source = is_string($from['src'] ?? NULL) ? $from['src'] : NULL;
    return $source === NULL ? NULL : ['name' => $source === 'none' ? NULL : $source];
  }

}
