<?php

declare(strict_types=1);

namespace Drupal\data_surface_target_test\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * A simple config object holding a translatable greeting.
 *
 * So it is stored with a language code.
 */
final class TranslatableSettingsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_target_test.translatable';

}
