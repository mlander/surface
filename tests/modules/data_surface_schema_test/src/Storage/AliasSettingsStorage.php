<?php

declare(strict_types=1);

namespace Drupal\data_surface_schema_test\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;
use Drupal\data_surface\Storage\StorageLayoutInterface;

/**
 * Keeps the alias settings, the types as the list of those checked.
 */
final class AliasSettingsStorage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_schema_test.aliases';

  /**
   * The key asked for as a map of checkboxes and stored as a list of ids.
   */
  public const LISTED = 'types';

  /**
   * {@inheritdoc}
   */
  protected function layout(): StorageLayoutInterface {
    return new CheckedListLayout(self::LISTED);
  }

}
