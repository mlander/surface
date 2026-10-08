<?php

declare(strict_types=1);

namespace Drupal\data_surface_surface_test\Target;

/**
 * Keeps a pantry's label apart from the pantry.
 */
final class PantryLabelTarget extends PantryTarget {

  /**
   * {@inheritdoc}
   */
  public const PART = 'pantry_label';

}
