<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Keeps the digest in its config object.
 */
final class DigestStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_resource_test.digest';

}
