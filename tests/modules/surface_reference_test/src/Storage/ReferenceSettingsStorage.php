<?php

declare(strict_types=1);

namespace Drupal\surface_reference_test\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Keeps the reference settings in their config object.
 */
final class ReferenceSettingsStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_reference_test.settings';

}
