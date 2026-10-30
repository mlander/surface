<?php

declare(strict_types=1);

namespace Drupal\surface_examples_entity\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the SurfaceReferenceable constraint.
 *
 * The field's selection handler is asked, as core's ValidReference asks
 * it when the entity is validated; no value is no failure.
 */
final class ReferenceableConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a ReferenceableConstraintValidator.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The field manager.
   * @param \Drupal\Core\Entity\EntityReferenceSelection\SelectionPluginManagerInterface $selection
   *   The selection handlers.
   */
  public function __construct(
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly SelectionPluginManagerInterface $selection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_field.manager'), $container->get('plugin.manager.entity_reference_selection'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof ReferenceableConstraint);
    if ($value === NULL || $value === '') {
      return;
    }
    $field = $this->entityFieldManager->getFieldDefinitions($constraint->entityTypeId, $constraint->bundle)[$constraint->fieldName] ?? NULL;
    $valid = $field !== NULL && (is_int($value) || is_string($value))
      && $this->selection->getSelectionHandler($field)->validateReferenceableEntities([$value]) !== [];
    if (!$valid) {
      $this->context->addViolation($constraint->message, [
        '@field' => $field?->getLabel() ?? $constraint->fieldName,
        '@id' => is_scalar($value) ? (string) $value : $this->formatValue($value),
      ]);
    }
  }

}
