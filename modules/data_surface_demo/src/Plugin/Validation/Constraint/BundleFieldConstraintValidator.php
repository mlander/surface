<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the DataSurfaceDemoBundleField constraint.
 *
 * No value given is not a failure, which is how core's own existence
 * validators read an absent value.
 */
final class BundleFieldConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a BundleFieldConstraintValidator.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The field manager, which knows the fields of every bundle.
   */
  public function __construct(
    protected readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_field.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof BundleFieldConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    $fields = $this->entityFieldManager->getFieldDefinitions($constraint->entityTypeId, $constraint->bundle);
    if (!is_string($value) || !isset($fields[$value])) {
      $this->context->addViolation($constraint->message, [
        '@field' => is_scalar($value) ? (string) $value : $this->formatValue($value),
        '@entity_type_id' => $constraint->entityTypeId,
        '@bundle' => $constraint->bundle,
      ]);
    }
  }

}
