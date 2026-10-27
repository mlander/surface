<?php

declare(strict_types=1);

namespace Drupal\data_surface_reference_test\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Keeps the reference settings in their config object.
 */
final class ReferenceSettingsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_reference_test.settings';

}
