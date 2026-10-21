<?php

declare(strict_types=1);

namespace Drupal\data_surface_storage_test\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * A simple config object holding nothing translatable.
 *
 * So it is stored without a language code.
 */
final class PlainSettingsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_storage_test.plain';

}
