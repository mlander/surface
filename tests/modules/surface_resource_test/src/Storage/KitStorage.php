<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Stores the kit fixture, the chosen variant's keys flat beside its kind.
 */
final class KitStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_resource_test.kit';

}
