<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\surface\Reference\References;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the SurfaceValidReference constraint.
 *
 * The selection handler is asked, as core's ValidReference asks a field's.
 * One message for an id that names nothing, one of another bundle and one
 * the account may not reference, so a refusal never says whether an
 * entity the account cannot see exists; core's validator tells the first
 * apart. No value is no failure.
 */
final class ValidReferenceConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a ValidReferenceConstraintValidator.
   *
   * @param \Drupal\surface\Reference\References $references
   *   What reference keys are, and which entities they may name.
   */
  public function __construct(
    protected readonly References $references,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('surface.references'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof ValidReferenceConstraint);
    if ($value === NULL || $value === '' || $constraint->entityTypeId === '') {
      return;
    }
    $valid = (is_int($value) || is_string($value))
      && $this->references->validates($constraint->entityTypeId, $constraint->bundles, $value);
    if (!$valid) {
      $this->context->addViolation($constraint->message, [
        '%type' => $constraint->entityTypeId,
        '%id' => is_scalar($value) ? (string) $value : $this->formatValue($value),
      ]);
    }
  }

}
