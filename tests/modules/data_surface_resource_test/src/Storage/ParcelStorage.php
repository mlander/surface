<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Keeps the parcel in its config object.
 */
final class ParcelStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_resource_test.parcel';

}
