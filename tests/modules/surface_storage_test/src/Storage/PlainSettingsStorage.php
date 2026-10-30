<?php

declare(strict_types=1);

namespace Drupal\surface_storage_test\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * A simple config object holding nothing translatable.
 *
 * So it is stored without a language code.
 */
final class PlainSettingsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_storage_test.plain';

}
