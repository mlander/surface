<?php

declare(strict_types=1);

namespace Drupal\surface_reader_test\Storage;

use Drupal\surface\Storage\ConfigObjectResourceStorage;

/**
 * Stores the condition host, the chosen condition's keys beside its id.
 */
final class ConditionHostStorage extends ConfigObjectResourceStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_reader_test.condition_host';

}
