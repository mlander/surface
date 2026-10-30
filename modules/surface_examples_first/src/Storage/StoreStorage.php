<?php

declare(strict_types=1);

namespace Drupal\surface_examples_first\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Keeps the store location in its config object.
 */
final class StoreStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples_first.store';

}
