<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Example 7's bulletin settings, in their own config object.
 */
final class BulletinStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_examples.bulletin';

}
