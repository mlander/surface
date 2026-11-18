<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Stores the pack fixture, the chosen variant's keys under its load.
 */
final class PackStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_resource_test.pack';

}
