<?php

declare(strict_types=1);

namespace Drupal\data_surface_storage_test\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * A simple config object keeping one key under a path, beside another.
 *
 * The limit is kept at outer.inner.limit, beside a note no resource
 * declares, the way core's system.performance keeps the page cache's
 * maximum age beside settings other forms edit.
 */
final class NestedSettingsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_storage_test.nested';

  /**
   * {@inheritdoc}
   */
  public const STORED_AT = ['limit' => 'outer.inner.limit'];

}
