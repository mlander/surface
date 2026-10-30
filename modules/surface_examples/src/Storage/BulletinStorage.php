<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Example 7's bulletin settings, in their own config object.
 */
final class BulletinStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples.bulletin';

}
