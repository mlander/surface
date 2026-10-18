<?php

declare(strict_types=1);

namespace Drupal\data_surface_first\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Keeps the store location in its config object.
 */
final class StoreStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_first.store';

}
