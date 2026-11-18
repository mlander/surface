<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Example 18's catering settings, in their own config object.
 */
final class CateringStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples.catering';

}
