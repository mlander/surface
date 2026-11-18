<?php

declare(strict_types=1);

namespace Drupal\my_module\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Keeps my module's settings in its config object, my_module.settings.
 *
 * The storage the introduction's page 8 shows for a config object.
 */
final class MySettingsStorage extends ConfigObjectResourceStorage {

  public const CONFIG = 'my_module.settings';

}
