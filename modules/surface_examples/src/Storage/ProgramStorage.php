<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Example 10's program settings, in their own config object.
 */
final class ProgramStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples.program';

}
