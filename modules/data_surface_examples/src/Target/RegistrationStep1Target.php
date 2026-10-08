<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Target;

/**
 * Example 1's registration settings, in their own config object.
 */
final class RegistrationStep1Target extends RegistrationTarget {

  /**
   * {@inheritdoc}
   */
  protected const CONFIG = 'data_surface_examples.registration_step1';

}
