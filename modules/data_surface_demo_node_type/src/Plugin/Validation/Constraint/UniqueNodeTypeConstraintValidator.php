<?php

declare(strict_types=1);

namespace Drupal\data_surface_demo_node_type\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the DataSurfaceUniqueNodeType constraint.
 */
final class UniqueNodeTypeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a UniqueNodeTypeConstraintValidator.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, which answers whether the name is taken.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof UniqueNodeTypeConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    if ($this->entityTypeManager->getStorage('node_type')->load($value) !== NULL) {
      $this->context->addViolation($constraint->message, ['%value' => $value]);
    }
  }

}
