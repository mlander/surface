<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Example 8's press page settings, in their own config object.
 */
final class PressStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples.press';

}
