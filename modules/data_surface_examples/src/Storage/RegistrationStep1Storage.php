<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Storage;

use Drupal\data_surface\Storage\ConfigObjectStorage;

/**
 * Example 1's registration settings, in their own config object.
 *
 * Each example keeps its own object, so the examples never write over each
 * other. The object always exists, from config/install.
 */
final class RegistrationStep1Storage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_examples.registration_step1';

}
