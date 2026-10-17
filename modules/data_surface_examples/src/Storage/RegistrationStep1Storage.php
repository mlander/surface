<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Storage;

/**
 * Example 1's registration settings, in their own config object.
 */
final class RegistrationStep1Storage extends RegistrationStorage {

  /**
   * {@inheritdoc}
   */
  public const CONFIG = 'data_surface_examples.registration_step1';

}
