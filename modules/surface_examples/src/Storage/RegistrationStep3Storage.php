<?php

declare(strict_types=1);

namespace Drupal\surface_examples\Storage;

use Drupal\surface\Storage\ConfigObjectStorage;

/**
 * Example 3's registration settings, in their own config object.
 *
 * Each example keeps its own object, so the examples never write over each
 * other. The object always exists, from config/install.
 */
final class RegistrationStep3Storage extends ConfigObjectStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'surface_examples.registration_step3';

}
