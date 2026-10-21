<?php

declare(strict_types=1);

namespace Drupal\data_surface_storage_test\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * A simple config object that no config schema describes.
 */
final class MissingSchemaSettingsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_storage_test.missing';

}
