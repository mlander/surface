<?php

declare(strict_types=1);

namespace Drupal\surface_schema_test\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Keeps the narrowed settings in their config object.
 */
final class NarrowedStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_schema_test.narrowed';

}
