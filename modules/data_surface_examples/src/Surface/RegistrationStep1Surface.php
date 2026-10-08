<?php

declare(strict_types=1);

namespace Drupal\data_surface_examples\Surface;

use Drupal\data_surface\Surface\Attribute\Situation;
use Drupal\data_surface\Surface\Attribute\Surface;
use Drupal\data_surface\Surface\ShapeInterface;
use Drupal\data_surface\Surface\SurfaceContext;
use Drupal\data_surface\Surface\SurfaceInterface;
use Drupal\data_surface_examples\Target\RegistrationStep1Target;

/**
 * Example 1: declare what you accept.
 *
 * Three keys, each with its type, its label and what it allows. The form
 * at /surface-examples/1, its validation, and the tool
 * data_surface:registration.step1:configure are all read from here.
 */
#[Surface('registration.step1', target: RegistrationStep1Target::class)]
final class RegistrationStep1Surface implements SurfaceInterface {

  /**
   * Configures the registration settings, which always exist.
   */
  #[Situation('configure', label: 'Configure registration', permission: 'administer site configuration')]
  public static function configure(): SurfaceContext {
    return new SurfaceContext('configure');
  }

  /**
   * {@inheritdoc}
   */
  public static function defineInputs(ShapeInterface $inputs): void {
    $inputs->add('title', 'string', t('Event title'))
      ->setRequired(TRUE);
    $inputs->add('capacity', 'integer', t('Capacity'), default: 50)
      ->addConstraint('Range', ['min' => 1, 'max' => 1000]);
    $inputs->add('open', 'boolean', t('Registration open'), default: TRUE);
  }

}
