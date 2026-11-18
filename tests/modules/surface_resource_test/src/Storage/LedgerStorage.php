<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Keeps the ledger in its config object.
 */
final class LedgerStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_resource_test.ledger';

}
