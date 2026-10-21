<?php

declare(strict_types=1);

namespace Drupal\data_surface_resource_test\Storage;

/**
 * Keeps a tin's settings apart from the pantry.
 */
final class PantryTinStorage extends PantryStorage {

  /**
   * {@inheritdoc}
   */
  public const PART = 'pantry_tin';

}
