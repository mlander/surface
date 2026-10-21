<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Where the exhibit lives: one config object, its provenance flat in it.
 */
final class ExhibitStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_resource_test.exhibit';

}
