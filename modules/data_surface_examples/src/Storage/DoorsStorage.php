<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Example 11's door settings, in a config object whose schema is strict.
 *
 * Its config schema is fully validatable, which is how this storage knows
 * the badges may not hold nothing and the notes may.
 */
final class DoorsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_examples.doors';

}
