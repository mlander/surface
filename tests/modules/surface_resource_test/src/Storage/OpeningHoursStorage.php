<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Keeps the opening hours in their config object, which always holds three.
 *
 * The after-hours flag, the keywords and the holidays are never cleared:
 * their generated schema calls them not nullable.
 */
final class OpeningHoursStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_resource_test.opening_hours';

  /**
   * {@inheritdoc}
   */
  public const ALWAYS_HELD = ['after_hours', 'keywords', 'holidays'];

}
