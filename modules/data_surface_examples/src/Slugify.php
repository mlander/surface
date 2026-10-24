<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples;

use Drupal\data_surface\Resource\DeriverInterface;

/**
 * Example 15: a venue's slug, computed from its name.
 *
 * Lower case, each run of anything but a letter or a digit one dash, and
 * no dash at either end: "Riverside Hall" is riverside-hall. A pure
 * function of the name, so the narrowing shows the slug as the name is
 * typed, and the pipeline computes the same one when the venue is added.
 */
final class Slugify implements DeriverInterface {

  /**
   * {@inheritdoc}
   */
  public static function derive(array $from): mixed {
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) ($from['label'] ?? ''))), '-');
    return $slug === '' ? NULL : $slug;
  }

}
