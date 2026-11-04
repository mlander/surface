<?php

declare(strict_types=1);

namespace Drupal\surface_property_test\Compute;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\ComputesValueInterface;

/**
 * The first letter of each word of the label, lower case.
 */
final class Initials implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?DataDefinitionInterface $key = NULL): mixed {
    preg_match_all('/\b\w/u', mb_strtolower((string) ($from['label'] ?? '')), $letters);
    return $letters[0] === [] ? NULL : implode('', $letters[0]);
  }

}
