<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Keeps the opening hours in their config object.
 */
final class OpeningHoursStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_resource_test.opening_hours';

}
