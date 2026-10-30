<?php

declare(strict_types=1);

namespace Drupal\surface_resource_test\Storage;

/**
 * Keeps a pantry's label apart from the pantry.
 */
final class PantryLabelStorage extends PantryStorage {

  /**
   * {@inheritdoc}
   */
  public const PART = 'pantry_label';

}
