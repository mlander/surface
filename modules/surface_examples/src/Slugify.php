<?php

declare(strict_types=1);

namespace Drupal\surface_examples;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\Resource\ComputesValueInterface;

/**
 * Example 15: a venue's slug, computed from its name.
 *
 * Lower case, each run of anything but a letter or a digit one dash, and
 * no dash at either end: "Riverside Hall" is riverside-hall. A pure
 * function of the name, so the narrowing shows the slug as the name is
 * typed, and the pipeline computes the same one when the venue is added.
 */
final class Slugify implements ComputesValueInterface {

  /**
   * {@inheritdoc}
   */
  public static function compute(array $from, ?DataDefinitionInterface $key = NULL): mixed {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) ($from['label'] ?? ''))), '-');
    return $slug === '' ? NULL : $slug;
  }

}
