<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Stores the shelter fixture, the chosen variant's keys under its shelter.
 */
final class ShelterStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_resource_test.shelter';

}
