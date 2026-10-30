<?php

declare(strict_types=1);

namespace Drupal\surface_storage_test\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * A simple config object that no config schema describes.
 */
final class MissingSchemaSettingsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_storage_test.missing';

}
