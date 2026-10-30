<?php

declare(strict_types=1);

namespace Drupal\my_module\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Keeps my module's colors in their config object, my_module.colors.
 */
final class MyColorsStorage extends ConfigObjectStorage {

  public const CONFIG = 'my_module.colors';

}
