<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Example 8's information desk settings, in their own config object.
 */
final class InfoDeskStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples.desk';

}
