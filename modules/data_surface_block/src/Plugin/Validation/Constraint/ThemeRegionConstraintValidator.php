<?php

declare(strict_types=1);

namespace Drupal\data_surface_block\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the DataSurfaceThemeRegion constraint.
 *
 * No value given is not a failure, as core's existence validators read
 * it; any other value has to be one of the theme's visible regions, and a
 * theme that is not installed has none; with no theme named, one of any
 * installed theme's.
 */
final class ThemeRegionConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a ThemeRegionConstraintValidator.
   *
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themes
   *   The theme handler, which knows each installed theme's regions.
   */
  public function __construct(
    protected readonly ThemeHandlerInterface $themes,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('theme_handler'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof ThemeRegionConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    $regions = $constraint->regions($this->themes);
    if (!is_string($value) || !isset($regions[$value])) {
      $this->context->addViolation($constraint->message, [
        '@theme' => $constraint->theme,
        '@region' => is_scalar($value) ? (string) $value : $this->formatValue($value),
      ]);
    }
  }

}
