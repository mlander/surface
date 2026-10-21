<?php

declare(strict_types=1);

namespace Drupal\data_surface_schema_test\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Keeps the narrowed settings in their config object.
 */
final class NarrowedStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_schema_test.narrowed';

}
