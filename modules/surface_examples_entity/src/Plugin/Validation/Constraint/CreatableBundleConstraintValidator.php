<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\surface_examples_entity\FieldItemDerivation;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the SurfaceCreatableBundle constraint.
 *
 * The value is held to the bundles FieldItemDerivation::creatableBundles()
 * answers, which the key's options are; no value is no failure, since the
 * key's own required flag answers for that.
 */
final class CreatableBundleConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a CreatableBundleConstraintValidator.
   *
   * @param \Drupal\surface_examples_entity\FieldItemDerivation $derivation
   *   Which bundles have a form display, and which of them may be created.
   */
  public function __construct(
    protected readonly FieldItemDerivation $derivation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('surface_examples_entity.field_item_derivation'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof CreatableBundleConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    [$creatable] = $this->derivation->creatableBundles($constraint->entityTypeId);
    if (!is_string($value) || !isset($creatable[$value])) {
      $this->context->addViolation($constraint->message, ['@bundle' => is_scalar($value) ? (string) $value : $this->formatValue($value)]);
    }
  }

}
