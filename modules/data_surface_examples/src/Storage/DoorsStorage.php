<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Example 11's door settings, which always hold the badges and greeters.
 *
 * Its config schema is written from the resource and this class: fully
 * validatable, with no `nullable: true` on the keys named here, which is
 * how this storage knows the badges may not hold nothing and the notes
 * may.
 */
final class DoorsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_examples.doors';

  /**
   * {@inheritdoc}
   */
  public const ALWAYS_HELD = ['badges', 'greeters'];

}
