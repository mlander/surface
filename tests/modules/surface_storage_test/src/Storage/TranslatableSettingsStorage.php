<?php

declare(strict_types=1);

namespace Drupal\surface_storage_test\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * A simple config object holding a translatable greeting.
 *
 * So it is stored with a language code.
 */
final class TranslatableSettingsStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_storage_test.translatable';

}
