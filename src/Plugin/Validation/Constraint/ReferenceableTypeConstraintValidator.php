<?php

declare(strict_types=1);

namespace Drupal\surface\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\surface\Reference\References;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the SurfaceReferenceableType constraint.
 *
 * The value is held to the list References::entityTypes() answers for the
 * account, which the key's options are; no value is no failure.
 */
final class ReferenceableTypeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a ReferenceableTypeConstraintValidator.
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
    assert($constraint instanceof ReferenceableTypeConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    [$types] = $this->references->entityTypes($constraint->interface, $constraint->targets);
    if (!is_string($value) || !isset($types[$value])) {
      $this->context->addViolation($constraint->message, ['%type' => is_scalar($value) ? (string) $value : $this->formatValue($value)]);
    }
  }

}
