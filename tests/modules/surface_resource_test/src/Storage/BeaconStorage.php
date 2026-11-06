<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Stores the beacon fixture, the members of its variants flat beside its mode.
 */
final class BeaconStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_resource_test.beacon';

}
