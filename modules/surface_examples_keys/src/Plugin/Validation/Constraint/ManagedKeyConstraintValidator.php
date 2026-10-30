<?php

declare(strict_types=1);

namespace Drupal\surface_examples_keys\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\surface_examples_keys\Keys\ManagedKeys;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Refuses a value that names no key the site manages.
 */
final class ManagedKeyConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs the validator.
   *
   * @param \Drupal\surface_examples_keys\Keys\ManagedKeys $keys
   *   The keys the site manages.
   */
  public function __construct(
    protected readonly ManagedKeys $keys,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('surface_examples_keys.keys'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof ManagedKeyConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    if (!is_string($value) || !isset($this->keys->labels()[$value])) {
      $this->context->addViolation($constraint->message, [
        '@key' => is_scalar($value) ? (string) $value : $this->formatValue($value),
      ]);
    }
  }

}
