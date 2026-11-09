<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Stores the campsite fixture, each pitch's gear flat beside its kind.
 */
final class CampsiteStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_resource_test.campsite';

}
